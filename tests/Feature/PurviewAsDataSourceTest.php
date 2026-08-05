<?php

namespace Tests\Feature;

use App\Models\InformationSystem;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\DatabaseScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Purview sebagai Sistem Informasi biasa di Data Discovery.
 *
 * Versi pertama menaruh Purview sebagai tombol tersendiri di katalog. Itu
 * salah tempat: koneksinya tidak tersimpan, sistemnya tidak muncul di daftar
 * Data Discovery, dan kolomnya tidak pernah melewati alur klasifikasi yang
 * dipakai sumber lain. Akibatnya klasifikasi justru TIDAK satu pintu —
 * Purview berdiri di sampingnya.
 *
 * Yang diuji di sini adalah alur utuhnya: sistem dibuat, koneksi diuji,
 * dipindai, lalu kolomnya keluar dengan bentuk yang sama persis seperti hasil
 * pemindaian MySQL — sehingga seluruh lapisan di hilir tidak perlu tahu
 * sumbernya Purview.
 */
class PurviewAsDataSourceTest extends TestCase
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

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'account_name' => 'purview-uji',
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
            'client_id' => '22222222-2222-2222-2222-222222222222',
            'client_secret' => 'rahasia-uji',
        ];
    }

    private function fakePurview(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-uji'], 200),
            '*purview.azure.com*' => Http::response([
                '@search.count' => 4,
                'value' => [
                    [
                        'id' => 'g1', 'name' => 'customers', 'entityType' => 'azure_sql_table',
                        'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers',
                    ],
                    [
                        'id' => 'g2', 'name' => 'nik', 'entityType' => 'azure_sql_column',
                        'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers#nik',
                        'classification' => ['MICROSOFT.PERSONAL.NATIONAL_ID'],
                    ],
                    [
                        'id' => 'g3', 'name' => 'email', 'entityType' => 'azure_sql_column',
                        'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers#email',
                        'classification' => ['MICROSOFT.PERSONAL.EMAIL'],
                    ],
                    [
                        // Sengaja TANPA klasifikasi Purview.
                        'id' => 'g4', 'name' => 'no_ktp', 'entityType' => 'azure_sql_column',
                        'qualifiedName' => 'mssql://srv01/COREBANK/dbo/customers#no_ktp',
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_purview_dikenali_sebagai_tipe_sumber(): void
    {
        // Sebelum perbaikan ini, memindai tipe 'purview' membalas
        // "Unknown source type" — sumbernya tidak pernah sampai ke alur mana pun.
        $this->fakePurview();

        $result = DatabaseScanner::testConnection('purview', $this->config());

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertStringNotContainsString('Unknown source type', json_encode($result));
    }

    public function test_metadata_purview_menjadi_tabel_dan_kolom(): void
    {
        $this->fakePurview();

        $schema = DatabaseScanner::scanSchema('purview', $this->config());

        $this->assertSame('real_purview', $schema['engine']);
        $this->assertNotEmpty($schema['tables']);

        $table = collect($schema['tables'])->firstWhere('name', 'mssql://srv01/COREBANK/dbo/customers');
        $this->assertNotNull($table, 'Dataset Purview seharusnya menjadi tabel.');
        $this->assertCount(3, $table['columns']);
    }

    public function test_klasifikasi_purview_terbawa_ke_kolom(): void
    {
        $this->fakePurview();

        $schema = DatabaseScanner::scanSchema('purview', $this->config());
        $cols = collect($schema['tables'])->firstWhere('name', 'mssql://srv01/COREBANK/dbo/customers')['columns'];

        $nik = collect($cols)->firstWhere('name', 'nik');
        $this->assertTrue($nik['pii_detected']);
        $this->assertSame('sensitive', $nik['classification']);
        $this->assertSame('spesifik', $nik['pdp_category']);
        $this->assertTrue($nik['encryption_required']);
    }

    public function test_kolom_tanpa_klasifikasi_purview_tetap_diperiksa_dari_namanya(): void
    {
        // Purview hanya mengisi klasifikasi setelah pemindaian klasifikasinya
        // dijalankan di sana. Banyak organisasi belum melakukannya — dan
        // membiarkan seluruh katalog masuk tanpa satu pun sinyal jauh lebih
        // buruk daripada memakai nama kolom sebagai sinyal lemah.
        $this->fakePurview();

        $schema = DatabaseScanner::scanSchema('purview', $this->config());
        $cols = collect($schema['tables'])->firstWhere('name', 'mssql://srv01/COREBANK/dbo/customers')['columns'];

        $ktp = collect($cols)->firstWhere('name', 'no_ktp');
        $this->assertTrue($ktp['pii_detected'], 'no_ktp seharusnya dikenali dari namanya.');
        $this->assertSame('spesifik', $ktp['pdp_category']);
        $this->assertStringContainsString('nama kolom', $ktp['pii_reason']);
    }

    public function test_sistem_purview_dapat_dibuat_diuji_dan_dipindai(): void
    {
        $this->fakePurview();

        // Sistem Informasi dibuat lewat Universal CRUD (`/api/m/{module}`),
        // jalur yang sama dengan sumber data lain — itulah maksudnya "satu
        // pintu": Purview tidak punya jalur pembuatan tersendiri.
        $created = $this->postJson('/api/m/data-discovery', [
            'name' => 'Katalog Purview Korporat',
            'source_type' => 'purview',
            'connection_config' => $this->config(),
        ])->assertCreated()->json('data');

        $id = $created['id'];

        $this->postJson("/api/data-discovery/{$id}/test-connection")->assertOk();
        $this->postJson("/api/data-discovery/{$id}/scan")->assertOk();

        $system = InformationSystem::withoutGlobalScope('org')->findOrFail($id);
        $tables = $system->scan_results['tables'] ?? [];

        $this->assertNotEmpty($tables, 'Hasil pemindaian Purview seharusnya tersimpan pada sistemnya.');

        $names = collect($tables)->pluck('columns')->flatten(1)->pluck('name');
        $this->assertTrue($names->contains('nik'));
        $this->assertTrue($names->contains('email'));
    }
}
