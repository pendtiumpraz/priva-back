<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PiiPatternRule;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\CloudStorageScanner;
use App\Services\ContentPiiScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pola PII milik organisasi, dan kejujuran pemindai penyimpanan objek.
 *
 * Dua hal yang paling menentukan: pola kustom benar-benar dipakai saat
 * memindai isi kolom (bukan sekadar tersimpan), dan pemindai cloud tidak
 * pernah lagi mengarang temuan ketika tidak dapat menyambung.
 */
class PiiPatternAndCloudScanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        ContentPiiScanner::flushCustomPatterns();

        $this->org = Organization::create(['name' => 'Bank Uji', 'slug' => 'bank-'.uniqid()]);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'DPO',
            'permissions' => ['data_discovery:read', 'data_discovery:write'],
        ]);
        $this->user = User::create([
            'org_id' => $this->org->id,
            'name' => 'DPO Uji',
            'email' => 'dpo'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]);
    }

    protected function tearDown(): void
    {
        ContentPiiScanner::flushCustomPatterns();
        parent::tearDown();
    }

    // ==================== Pola kustom ====================

    #[Test]
    public function pola_kustom_benar_benar_dipakai_saat_memindai_isi_kolom(): void
    {
        Sanctum::actingAs($this->user);

        // Nomor CIF bank: 10 digit diawali "CIF". Tidak mungkin diketahui di
        // muka, dan tanpa pola ini kolomnya tidak akan pernah terdeteksi.
        $this->postJson('/api/pii-patterns', [
            'key' => 'cif',
            'label' => 'Nomor CIF Nasabah',
            'pattern' => '/^CIF\d{10}$/',
            'pdp_category' => 'spesifik',
            'classification' => 'sensitive',
            'encryption_required' => true,
            'sample_value' => 'CIF0012345678',
        ])->assertStatus(201);

        ContentPiiScanner::flushCustomPatterns();

        $result = ContentPiiScanner::analyzeColumnContent([
            'CIF0012345678', 'CIF0087654321', 'CIF0011112222', 'CIF0099998888',
        ]);

        $this->assertNotNull($result, 'Kolom berisi nomor CIF harus terdeteksi.');
        $this->assertTrue($result['is_pii']);
        $this->assertSame('spesifik', $result['pdp_category']);
        $this->assertTrue($result['encryption_required']);
    }

    #[Test]
    public function pola_bawaan_tetap_bekerja_berdampingan_dengan_pola_kustom(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/pii-patterns', [
            'key' => 'cif',
            'label' => 'CIF',
            'pattern' => '/^CIF\d{10}$/',
        ])->assertStatus(201);

        ContentPiiScanner::flushCustomPatterns();

        // NIK adalah pola bawaan; menambah pola kustom tidak boleh menggesernya.
        $result = ContentPiiScanner::analyzeColumnContent([
            '3201234567890001', '3201234567890002', '3201234567890003',
        ]);

        $this->assertNotNull($result);
        $this->assertTrue($result['is_pii']);
        $this->assertSame('spesifik', $result['pdp_category']);
    }

    #[Test]
    public function pola_rusak_ditolak_sebelum_tersimpan(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/pii-patterns', [
            'key' => 'rusak',
            'label' => 'Pola Rusak',
            'pattern' => '/^(tidak-ditutup\d{',
        ])->assertStatus(422);

        $this->assertSame(0, PiiPatternRule::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function pola_yang_tidak_cocok_dengan_contohnya_sendiri_ditolak(): void
    {
        Sanctum::actingAs($this->user);

        // Sah secara sintaksis, tetapi salah tulis. Tanpa lapis pemeriksaan
        // ini, kesalahannya baru ketahuan berbulan-bulan kemudian ketika ada
        // yang menyadari kolomnya tidak pernah terdeteksi.
        $res = $this->postJson('/api/pii-patterns', [
            'key' => 'polis',
            'label' => 'Nomor Polis',
            'pattern' => '/^POL\d{8}$/',
            'sample_value' => 'POLIS-12345678',
        ])->assertStatus(422);

        $this->assertStringContainsString('tidak mencocokkan contoh', $res->json('message'));
        $this->assertSame(0, PiiPatternRule::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function pengujian_pola_memperingatkan_pola_yang_terlalu_longgar(): void
    {
        Sanctum::actingAs($this->user);

        $res = $this->postJson('/api/pii-patterns/test', [
            'pattern' => '/.*/',
            'values' => ['apa saja', '12345', 'budi@contoh.id', 'xyz'],
        ])->assertOk();

        $this->assertSame(4, $res->json('data.matched'));
        $this->assertNotNull($res->json('data.warning'), 'Pola yang mencocokkan semuanya harus diperingatkan.');
    }

    #[Test]
    public function pola_satu_organisasi_tidak_dipakai_organisasi_lain(): void
    {
        // Kunci sengaja dipilih yang TIDAK ada di katalog bawaan. Sejak katalog
        // disemai per tenant, memakai kunci bawaan (mis. 'cif') tidak lagi
        // menguji isolasi — organisasi lain memang punya polanya sendiri, dan
        // tesnya akan gagal karena alasan yang salah.
        Sanctum::actingAs($this->user);
        $this->postJson('/api/pii-patterns', [
            'key' => 'kode_internal_abc',
            'label' => 'Kode Internal ABC',
            'pattern' => '/^ZX-\d{2}-QQ\d{4}$/',
        ])->assertStatus(201);

        $otherOrg = Organization::create(['name' => 'Bank Lain', 'slug' => 'lain-'.uniqid()]);
        $otherRole = TenantRole::create([
            'org_id' => $otherOrg->id,
            'name' => 'DPO',
            'permissions' => ['data_discovery:read', 'data_discovery:write'],
        ]);
        $otherUser = User::create([
            'org_id' => $otherOrg->id,
            'name' => 'DPO Lain',
            'email' => 'lain'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'dpo',
            'tenant_role_id' => $otherRole->id,
        ]);

        Sanctum::actingAs($otherUser);
        ContentPiiScanner::flushCustomPatterns();

        // Organisasi lain punya katalog bawaannya sendiri, tetapi TIDAK boleh
        // memuat pola yang disusun organisasi pertama.
        $keys = array_column($this->getJson('/api/pii-patterns')->json('data'), 'key');
        $this->assertNotContains('kode_internal_abc', $keys);

        // Dan nilainya pun tidak boleh terdeteksi di sana.
        $result = ContentPiiScanner::analyzeColumnContent([
            'ZX-88-QQ1234', 'ZX-77-QQ5678', 'ZX-11-QQ9012',
        ]);
        $this->assertNull($result);
    }

    #[Test]
    public function tanpa_izin_data_discovery_pola_ditolak(): void
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'permissions' => ['ropa:read'],
        ]);
        $outsider = User::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'email' => 'no'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'tenant_role_id' => $role->id,
        ]);

        Sanctum::actingAs($outsider);
        $this->getJson('/api/pii-patterns')->assertStatus(403);
    }

    // ==================== Kejujuran pemindai cloud ====================

    #[Test]
    public function pemindai_cloud_tidak_pernah_lagi_mengarang_temuan(): void
    {
        // Sebelumnya jalur ini mengembalikan daftar berkas hardcoded beserta
        // temuan PII yang dibangkitkan rand(). Kini konfigurasi kosong harus
        // menghasilkan kegagalan yang jujur.
        foreach ([
            ['aws_s3', CloudStorageScanner::scanS3([])],
            ['gcs', CloudStorageScanner::scanGcs([])],
            ['azure_blob', CloudStorageScanner::scanAzureBlob([])],
        ] as [$label, $result]) {
            $this->assertSame([], $result['tables'], "[{$label}] tidak boleh mengembalikan tabel karangan.");
            $this->assertNotEmpty($result['error'] ?? null, "[{$label}] wajib menjelaskan kegagalannya.");
        }
    }

    #[Test]
    public function hasil_pemindaian_cloud_bersifat_tetap_bukan_acak(): void
    {
        // Implementasi lama memakai rand(), sehingga dua pemindaian berturut
        // atas sumber yang sama memberi angka berbeda tanpa ada yang berubah.
        $a = CloudStorageScanner::scanS3(['bucket' => 'tidak-ada']);
        $b = CloudStorageScanner::scanS3(['bucket' => 'tidak-ada']);

        $this->assertSame($a['tables'], $b['tables']);
        $this->assertSame([], $a['tables']);
    }

    #[Test]
    public function azure_tanpa_sas_menjelaskan_alasannya_bukan_sekadar_gagal(): void
    {
        $result = CloudStorageScanner::scanAzureBlob([
            'account' => 'akunuji',
            'container' => 'data',
        ]);

        $this->assertSame([], $result['tables']);
        $this->assertStringContainsString('SAS token', $result['error']);
    }

    #[Test]
    public function gcs_menjelaskan_kebutuhan_kunci_hmac(): void
    {
        $result = CloudStorageScanner::scanGcs(['bucket' => 'bucket-uji']);

        $this->assertSame([], $result['tables']);
        $this->assertStringContainsString('HMAC', $result['error']);
        $this->assertStringContainsString('Interoperability', $result['error']);
    }
}
