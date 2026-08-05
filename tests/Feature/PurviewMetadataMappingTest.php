<?php

namespace Tests\Feature;

use App\Models\DataCatalogAsset;
use App\Models\DataCatalogLineage;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Penarikan metadata Microsoft Purview dan pemetaannya menjadi katalog.
 *
 * Diuji dengan meniru balasan Data Map API, bukan menyambung ke tenant
 * sungguhan. Yang dijaga di sini justru bagian yang tidak akan terlihat saat
 * demo berjalan mulus: apakah hierarki asetnya benar-benar tersambung, atau
 * hanya tampak tersambung karena tepinya dibuat menunjuk kunci yang tidak ada.
 */
class PurviewMetadataMappingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'DPO Uji',
            'slug' => 'dpo-uji-'.uniqid(),
            'permissions' => ['data_discovery:read', 'data_discovery:write'],
        ]);

        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id,
            'tenant_role_id' => $role->id,
        ]));
    }

    /** Kredensial yang sah bentuknya, dipakai semua uji di kelas ini. */
    private function creds(array $extra = []): array
    {
        return array_merge([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'client_id' => '22222222-2222-2222-2222-222222222222',
            'client_secret' => 'rahasia-uji',
            'account_name' => 'purview-uji',
        ], $extra);
    }

    private function fakeToken(): array
    {
        return ['login.microsoftonline.com/*' => Http::response(['access_token' => 'token-uji'], 200)];
    }

    /**
     * Balasan pencarian yang bentuknya mengikuti Data Map API: satu basis data,
     * satu tabel di dalamnya, dan dua kolom di dalam tabel itu.
     */
    private function fakeSearch(): array
    {
        return ['*purview.azure.com*' => Http::response([
            '@search.count' => 4,
            'value' => [
                [
                    'id' => 'guid-db',
                    'name' => 'COREBANK',
                    'entityType' => 'azure_sql_db',
                    'qualifiedName' => 'mssql://srv01/COREBANK',
                ],
                [
                    'id' => 'guid-table',
                    'name' => 'customers',
                    'entityType' => 'azure_sql_table',
                    'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers',
                    'description' => 'Master nasabah',
                ],
                [
                    'id' => 'guid-col-nik',
                    'name' => 'nik',
                    'entityType' => 'azure_sql_column',
                    'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers#nik',
                    'classification' => ['MICROSOFT.PERSONAL.NATIONAL_ID'],
                ],
                [
                    'id' => 'guid-col-email',
                    'name' => 'email',
                    'entityType' => 'azure_sql_column',
                    'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers#email',
                    'classification' => ['MICROSOFT.PERSONAL.EMAIL'],
                ],
            ],
        ], 200)];
    }

    public function test_aset_purview_ditarik_dan_tipenya_dipetakan(): void
    {
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $this->assertSame(4, DataCatalogAsset::count());

        $byName = DataCatalogAsset::pluck('asset_type', 'name');
        $this->assertSame('system', $byName['COREBANK']);
        $this->assertSame('dataset', $byName['customers']);
        $this->assertSame('field', $byName['nik']);
        $this->assertSame('field', $byName['email']);
    }

    public function test_hierarki_terbentuk_dan_tepinya_tidak_menggantung(): void
    {
        // Inti kelas uji ini. Versi pertama konektor memakai GUID sebagai kunci
        // aset sementara induknya diturunkan dari qualifiedName — tepinya
        // terbentuk, jumlahnya benar, tetapi seluruhnya menunjuk kunci yang
        // tidak pernah ada. Peta silsilahnya akan tampak kosong tanpa satu pun
        // pesan galat.
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $keys = DataCatalogAsset::pluck('asset_key')->all();
        $edges = DataCatalogLineage::all();

        $this->assertGreaterThan(0, $edges->count(), 'Tidak ada tepi silsilah yang terbentuk.');

        foreach ($edges as $edge) {
            $this->assertContains($edge->from_key, $keys, "Tepi menggantung: {$edge->from_key} tidak ada sebagai aset.");
            $this->assertContains($edge->to_key, $keys, "Tepi menggantung: {$edge->to_key} tidak ada sebagai aset.");
        }
    }

    public function test_kolom_menyambung_ke_tabelnya(): void
    {
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $tableKey = 'purview:mssql://srv01/COREBANK/dbo/customers';
        $nikKey = 'purview:mssql://srv01/COREBANK/dbo/customers#nik';

        $this->assertDatabaseHas('data_catalog_lineage', [
            'from_key' => $tableKey,
            'to_key' => $nikKey,
        ]);
    }

    public function test_klasifikasi_purview_dipetakan_ke_kosakata_pdp(): void
    {
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        // NATIONAL_ID menyiratkan NIK — data pribadi spesifik, bukan sekadar pii.
        $nik = DataCatalogAsset::where('name', 'nik')->firstOrFail();
        $email = DataCatalogAsset::where('name', 'email')->firstOrFail();

        $this->assertSame('sensitive', $nik->classification);
        $this->assertSame('pii', $email->classification);

        // Kategori PDP dan kewajiban enkripsi harus ikut, bukan dibiarkan
        // kosong. Aset hasil pemindaian sendiri selalu memilikinya; kalau aset
        // impor tidak, laporan yang menghitung data spesifik akan melewatkan
        // seluruh aset dari Purview tanpa memberi tanda ada yang terlewat.
        $this->assertSame('spesifik', $nik->pdp_category);
        $this->assertTrue($nik->encryption_required);

        $this->assertSame('umum', $email->pdp_category);
        $this->assertFalse($email->encryption_required);
    }

    public function test_aset_tanpa_klasifikasi_purview_tidak_ditebak(): void
    {
        // Purview hanya mengisi klasifikasi bila pemindaian klasifikasinya
        // sudah dijalankan di sana. Bila belum, aset tetap ditarik tetapi
        // TANPA sensitivitas — dan itu tidak boleh ditebak-tebak, karena
        // tebakan yang salah pada arah ini menyembunyikan data pribadi.
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $table = DataCatalogAsset::where('name', 'customers')->firstOrFail();

        $this->assertNull($table->classification);
        $this->assertNull($table->pdp_category);
        $this->assertFalse($table->encryption_required);
    }

    public function test_uji_sambungan_tidak_menulis_apa_pun(): void
    {
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));

        $res = $this->postJson('/api/data-catalog/sync-purview', $this->creds(['test_only' => true]))->assertOk();

        $this->assertTrue($res->json('data.success'));
        $this->assertSame(0, DataCatalogAsset::count());
    }

    public function test_403_dijelaskan_sebagai_peran_yang_kurang(): void
    {
        // Pesan Azure untuk 403 tidak pernah menyebut peran sebagai penyebab,
        // padahal itulah yang paling sering terlewat saat penyiapan.
        Http::fake(array_merge($this->fakeToken(), [
            '*purview.azure.com*' => Http::response(['error' => ['message' => 'Forbidden']], 403),
        ]));

        $res = $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertStatus(422);

        $this->assertStringContainsString('Data Reader', $res->json('message'));
    }

    public function test_kredensial_salah_dijelaskan_bukan_dibiarkan_gagal_diam(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'error_description' => 'AADSTS7000215: Invalid client secret provided.',
            ], 401),
        ]);

        $res = $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertStatus(422);

        $this->assertStringContainsString('Azure AD menolak kredensial', $res->json('message'));
    }

    public function test_klasifikasi_manual_bertahan_melewati_sinkronisasi_ulang(): void
    {
        // Inti dari "satu pintu". Organisasi menetapkan sensitivitas di sini,
        // lalu Purview ditarik ulang seminggu kemudian. Tanpa penjagaan,
        // keputusan itu kembali ke versi Purview tanpa pesan galat apa pun —
        // sebuah kolom hanya berubah diam-diam dari spesifik menjadi umum.
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        // Purview menyebut kolom email sebagai data pribadi umum; organisasi
        // menilainya spesifik karena konteks pemakaiannya.
        $email = DataCatalogAsset::where('name', 'email')->firstOrFail();
        $this->putJson("/api/data-catalog/assets/{$email->id}", [
            'classification' => 'sensitive',
            'pdp_category' => 'spesifik',
            'encryption_required' => true,
        ])->assertOk();

        $this->assertTrue($email->fresh()->manually_classified);

        // Tarik ulang dari Purview, yang tetap menyebutnya pii/umum.
        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $after = $email->fresh();
        $this->assertSame('sensitive', $after->classification, 'Klasifikasi manual tertimpa sinkronisasi.');
        $this->assertSame('spesifik', $after->pdp_category);
        $this->assertTrue($after->encryption_required);
    }

    public function test_struktur_tetap_diperbarui_walau_klasifikasi_dikunci(): void
    {
        // Penguncian hanya berlaku pada KEPUTUSAN sensitivitas. Nama, deskripsi,
        // dan pemilik tetap harus mengikuti sumbernya — kalau ikut beku,
        // katalog akan basi justru pada aset yang paling diperhatikan orang.
        //
        // Dua balasan berbeda disusun sebagai SEQUENCE, bukan dua panggilan
        // Http::fake terpisah: memanggil fake dua kali tidak menggantikan stub
        // yang pertama, dan stub pertama itulah yang tetap menang.
        Http::fake(array_merge($this->fakeToken(), [
            '*purview.azure.com*' => Http::sequence()
                ->push([
                    '@search.count' => 1,
                    'value' => [[
                        'id' => 'guid-table',
                        'name' => 'customers',
                        'entityType' => 'azure_sql_table',
                        'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers',
                        'description' => 'Master nasabah',
                    ]],
                ], 200)
                ->push([
                    '@search.count' => 1,
                    'value' => [[
                        'id' => 'guid-table',
                        'name' => 'customers',
                        'entityType' => 'azure_sql_table',
                        'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers',
                        'description' => 'Master nasabah — diperbarui',
                    ]],
                ], 200),
        ]));

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $table = DataCatalogAsset::where('name', 'customers')->firstOrFail();
        $this->putJson("/api/data-catalog/assets/{$table->id}", ['classification' => 'sensitive'])->assertOk();

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $after = $table->fresh();
        $this->assertSame('Master nasabah — diperbarui', $after->description, 'Deskripsi seharusnya tetap mengikuti sumber.');
        $this->assertSame('sensitive', $after->classification, 'Klasifikasi manual seharusnya tetap.');
    }

    public function test_penguncian_dapat_dicabut(): void
    {
        Http::fake(array_merge($this->fakeToken(), $this->fakeSearch()));
        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $email = DataCatalogAsset::where('name', 'email')->firstOrFail();
        $this->putJson("/api/data-catalog/assets/{$email->id}", ['classification' => 'sensitive'])->assertOk();
        $this->postJson("/api/data-catalog/assets/{$email->id}/release-classification")->assertOk();

        $this->assertFalse($email->fresh()->manually_classified);

        $this->postJson('/api/data-catalog/sync-purview', $this->creds())->assertOk();

        $this->assertSame('pii', $email->fresh()->classification, 'Setelah dicabut, aset harus mengikuti sumbernya lagi.');
    }
}
