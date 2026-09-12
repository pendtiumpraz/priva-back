<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PartnerApiKey;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pemasukan pihak ketiga secara massal — lewat CSV dan lewat API mitra.
 *
 * Janji yang dijaga di sini:
 *   1. pratinjau tidak menulis apa pun, dan melaporkan baris bermasalah per baris;
 *   2. mengimpor berkas yang sama dua kali MEMPERBARUI, bukan menggandakan;
 *   3. baris hasil impor terlihat oleh pengguna non-admin (assign_group terisi);
 *   4. kunci API hanya menjangkau organisasinya sendiri, dan hanya dengan izin
 *      yang memang diberikan pada kunci itu.
 */
class VendorImportApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'admin',
            'slug' => 'admin-uji-'.uniqid(),
            'permissions' => ['vendor_risk:read', 'vendor_risk:write'],
        ]);
        $this->user = User::factory()->create(['org_id' => $this->org->id, 'tenant_role_id' => $role->id]);
        Sanctum::actingAs($this->user);
    }

    /** @param  array<int, string>  $lines */
    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pihak-ketiga.csv', implode("\n", $lines)."\n");
    }

    /** Kunci API mitra; mengembalikan kunci mentahnya (hanya ada saat dibuat). */
    private function apiKey(array $permissions, ?string $orgId = null): string
    {
        return PartnerApiKey::generateKey([
            'org_id' => $orgId ?? $this->org->id,
            'name' => 'Sistem Pengadaan',
            'permissions' => $permissions,
            'environment' => 'live',
            'rate_limit_per_minute' => 120,
            'is_active' => true,
            // Kolom wajib: setiap kunci harus bisa ditelusuri ke penerbitnya.
            'created_by' => $this->user->id,
        ])['key'];
    }

    public function test_templat_memuat_judul_kolom_yang_dikenali(): void
    {
        $isi = $this->get('/api/vendor-risk/import/template')->assertOk()->streamedContent();

        $this->assertStringContainsString('external_ref', $isi);
        $this->assertStringContainsString('name', $isi);
    }

    public function test_pratinjau_melaporkan_baris_bermasalah_tanpa_menulis(): void
    {
        $res = $this->post('/api/vendor-risk/import/preview', [
            'file' => $this->csv([
                'nama,id rekanan,negara,peran,email kontak',
                'PT Cloud Mitra,VND-1,Indonesia,processor,budi@cloudmitra.co.id',
                ',VND-2,Indonesia,processor,ani@contoh.co.id',
                'PT Peran Aneh,VND-3,Indonesia,tukang kebun,rudi@contoh.co.id',
                'PT Surel Salah,VND-4,Indonesia,processor,bukan-surel',
            ]),
        ])->assertOk();

        $res->assertJsonPath('data.valid', 1);
        $res->assertJsonPath('data.invalid', 3);
        $this->assertSame(0, Vendor::where('org_id', $this->org->id)->count(), 'pratinjau tidak boleh menulis');
    }

    public function test_pratinjau_menolak_berkas_tanpa_kolom_nama(): void
    {
        $this->post('/api/vendor-risk/import/preview', [
            'file' => $this->csv(['negara,peran', 'Indonesia,processor']),
        ])->assertStatus(422);
    }

    public function test_impor_menyimpan_dan_terlihat_oleh_pengguna_non_admin(): void
    {
        $res = $this->post('/api/vendor-risk/import/commit', [
            'file' => $this->csv([
                'nama,id rekanan,negara,peran,layanan',
                'PT Cloud Mitra,VND-1,Indonesia,processor,Hosting; Pencadangan',
            ]),
        ])->assertCreated();

        $res->assertJsonPath('imported', 1);

        $vendor = Vendor::where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame('VND-1', $vendor->external_ref);
        $this->assertSame(Vendor::ROLE_PROCESSOR, $vendor->type);
        $this->assertSame(['Hosting', 'Pencadangan'], $vendor->services_provided);
        // Tanpa assign_group, baris hasil impor tak terlihat pengguna non-admin.
        $this->assertSame('(All Group)', $vendor->assign_group);
    }

    public function test_impor_ulang_berkas_yang_sama_memperbarui_bukan_menggandakan(): void
    {
        $baris = ['nama,id rekanan,negara', 'PT Cloud Mitra,VND-1,Indonesia'];
        $this->post('/api/vendor-risk/import/commit', ['file' => $this->csv($baris)])->assertCreated();

        $res = $this->post('/api/vendor-risk/import/commit', [
            'file' => $this->csv(['nama,id rekanan,negara', 'PT Cloud Mitra Nusantara,VND-1,Singapura']),
        ])->assertCreated();

        $res->assertJsonPath('imported', 0);
        $res->assertJsonPath('updated', 1);
        $this->assertSame(1, Vendor::where('org_id', $this->org->id)->count());
        $vendor = Vendor::where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame('PT Cloud Mitra Nusantara', $vendor->name);
        $this->assertSame('Singapura', $vendor->country);
    }

    public function test_id_rekanan_ganda_di_dalam_satu_berkas_dilaporkan(): void
    {
        $res = $this->post('/api/vendor-risk/import/preview', [
            'file' => $this->csv([
                'nama,id rekanan',
                'PT Cloud Mitra,VND-1',
                'PT Cloud Mitra Lagi,VND-1',
            ]),
        ])->assertOk();

        $res->assertJsonPath('data.valid', 1);
        $res->assertJsonPath('data.invalid', 1);
    }

    public function test_api_menolak_tanpa_kunci_dan_tanpa_izin(): void
    {
        $this->getJson('/api/v1/third-parties')->assertStatus(401);

        // Kunci sah, tetapi izinnya hanya breach — bukan pihak ketiga.
        $this->getJson('/api/v1/third-parties', ['X-Api-Key' => $this->apiKey(['breach.read'])])
            ->assertStatus(403);

        // Izin baca tidak memberi hak tulis: keduanya terdaftar terpisah.
        $this->postJson('/api/v1/third-parties', ['name' => 'PT Uji'], ['X-Api-Key' => $this->apiKey(['third_party.read'])])
            ->assertStatus(403);
    }

    public function test_api_hanya_menjangkau_organisasi_pemilik_kunci(): void
    {
        Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Milik Kami']);
        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        $punyaTetangga = Vendor::create(['org_id' => $lain->id, 'name' => 'PT Milik Tetangga']);

        $res = $this->getJson('/api/v1/third-parties', ['X-Api-Key' => $this->apiKey(['third_party.read'])])->assertOk();

        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.name', 'PT Milik Kami');

        $this->getJson("/api/v1/third-parties/{$punyaTetangga->id}", ['X-Api-Key' => $this->apiKey(['third_party.read'])])
            ->assertStatus(404);
    }

    public function test_api_memperbarui_saat_id_rekanan_sudah_dikenal(): void
    {
        $kunci = ['X-Api-Key' => $this->apiKey(['third_party.read', 'third_party.write'])];

        $this->postJson('/api/v1/third-parties', [
            'name' => 'PT Cloud Mitra',
            'external_ref' => 'VND-1',
            'type' => 'Processor',
            'country' => 'Indonesia',
        ], $kunci)->assertCreated()->assertJsonPath('created', true);

        $this->postJson('/api/v1/third-parties', [
            'name' => 'PT Cloud Mitra Nusantara',
            'external_ref' => 'VND-1',
        ], $kunci)->assertOk()->assertJsonPath('created', false);

        $this->assertSame(1, Vendor::where('org_id', $this->org->id)->count());
        $vendor = Vendor::where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame('PT Cloud Mitra Nusantara', $vendor->name);
        $this->assertSame(Vendor::ROLE_PROCESSOR, $vendor->type);
        $this->assertSame('(All Group)', $vendor->assign_group);
    }
}
