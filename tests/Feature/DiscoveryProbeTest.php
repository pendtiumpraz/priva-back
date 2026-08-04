<?php

namespace Tests\Feature;

use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryProbeConfig;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Models\SystemSetting;
use App\Models\TenantRole;
use App\Models\User;
use App\Services\ActiveDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Penemuan data store: mode pasif (bawaan) dan mode aktif (berlapis penjagaan).
 *
 * Test yang paling menentukan di berkas ini bukan "penemuan bekerja",
 * melainkan "pemindaian jaringan TIDAK berjalan kecuali seluruh lapis
 * penjagaan terlewati". Memindai jaringan klien tanpa izin berlapis adalah
 * jenis kesalahan yang tidak dapat ditarik kembali.
 */
class DiscoveryProbeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

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

    // ==================== Mode pasif ====================

    #[Test]
    public function mode_bawaan_adalah_pasif(): void
    {
        Sanctum::actingAs($this->user);

        $res = $this->getJson('/api/discovery-probe/config')->assertOk();

        $this->assertSame('passive', $res->json('data.mode'));
        $this->assertFalse($res->json('meta.platform.active_scan_allowed'));
    }

    #[Test]
    public function berkas_konfigurasi_diurai_menjadi_kandidat(): void
    {
        Sanctum::actingAs($this->user);

        $text = <<<'CFG'
        DB_CONNECTION=mysql
        DB_HOST=corebank-db.internal
        DB_PORT=3306
        DB_PASSWORD=rahasia-sekali

        REPORTING_URL=postgres://reader:sandi@dwh.internal:5432/warehouse
        LEGACY="Server=legacy-sql.internal,1433;Database=Nasabah"
        CACHE_HOST=localhost
        CACHE_PORT=6379
        CFG;

        $res = $this->postJson('/api/discovery-probe/ingest', [
            'source' => 'config_file',
            'text' => $text,
            'label' => 'app/.env produksi',
        ])->assertOk();

        $this->assertSame(3, $res->json('data.found'), 'localhost harus diabaikan.');

        $hosts = DiscoveryCandidate::withoutGlobalScope('org')->pluck('service_hint', 'host');
        $this->assertSame('mysql', $hosts['corebank-db.internal']);
        $this->assertSame('postgresql', $hosts['dwh.internal']);
        $this->assertSame('mssql', $hosts['legacy-sql.internal']);
    }

    #[Test]
    public function kredensial_disamarkan_pada_bukti_temuan(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/discovery-probe/ingest', [
            'source' => 'config_file',
            'text' => 'postgres://reader:SandiProduksi123@dwh.internal:5432/warehouse',
        ])->assertOk();

        $evidence = DiscoveryCandidate::withoutGlobalScope('org')->first()->evidence;

        // Bukti temuan tidak boleh berubah menjadi tempat penyimpanan kata
        // sandi produksi.
        $this->assertStringNotContainsString('SandiProduksi123', $evidence);
        $this->assertStringContainsString('****', $evidence);
    }

    #[Test]
    public function endpoint_yang_sudah_terdaftar_ditandai_bukan_dibuang(): void
    {
        $system = InformationSystem::create([
            'org_id' => $this->org->id,
            'name' => 'Core Banking',
            'source_type' => 'mysql',
            'connection_config' => ['host' => 'corebank-db.internal', 'port' => 3306],
        ]);

        Sanctum::actingAs($this->user);
        $res = $this->postJson('/api/discovery-probe/ingest', [
            'source' => 'config_file',
            'text' => "DB_HOST=corebank-db.internal\nDB_PORT=3306\nX=postgres://a:b@baru.internal:5432/db",
        ])->assertOk();

        $this->assertSame(1, $res->json('data.known'));
        $this->assertSame(1, $res->json('data.new'));

        // Yang sudah terdaftar TETAP disimpan: pemeriksa menanyakan cakupan
        // penemuan, bukan hanya temuannya.
        $known = DiscoveryCandidate::withoutGlobalScope('org')->where('host', 'corebank-db.internal')->first();
        $this->assertSame('registered', $known->status);
        $this->assertSame($system->id, $known->matched_system_id);
    }

    #[Test]
    public function kandidat_yang_sudah_diabaikan_tidak_kembali_menjadi_baru(): void
    {
        Sanctum::actingAs($this->user);
        $payload = ['source' => 'config_file', 'text' => 'postgres://a:b@dwh.internal:5432/db'];

        $this->postJson('/api/discovery-probe/ingest', $payload)->assertOk();
        $candidate = DiscoveryCandidate::withoutGlobalScope('org')->first();

        $this->putJson("/api/discovery-probe/candidates/{$candidate->id}", [
            'status' => 'ignored',
            'note' => 'Basis data pihak ketiga, di luar cakupan.',
        ])->assertOk();

        // Tanpa aturan ini, daftar yang sudah ditinjau akan penuh lagi setiap
        // penemuan dijalankan, dan orang berhenti membacanya.
        $this->postJson('/api/discovery-probe/ingest', $payload)->assertOk();

        $this->assertSame('ignored', $candidate->fresh()->status);
    }

    #[Test]
    public function kandidat_dapat_didaftarkan_menjadi_sistem_informasi(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/discovery-probe/ingest', [
            'source' => 'config_file',
            'text' => 'postgres://a:b@dwh.internal:5432/warehouse',
        ])->assertOk();

        $candidate = DiscoveryCandidate::withoutGlobalScope('org')->first();

        $res = $this->putJson("/api/discovery-probe/candidates/{$candidate->id}", [
            'register_as_system' => true,
            'system_name' => 'Data Warehouse',
        ])->assertStatus(201);

        $system = InformationSystem::withoutGlobalScope('org')->find($res->json('data.information_system_id'));
        $this->assertSame('Data Warehouse', $system->name);
        $this->assertSame('dwh.internal', $system->connection_config['host']);
        $this->assertSame(5432, $system->connection_config['port']);
        $this->assertSame('registered', $candidate->fresh()->status);
    }

    #[Test]
    public function baris_cmdb_diurai_menjadi_kandidat(): void
    {
        Sanctum::actingAs($this->user);

        $res = $this->postJson('/api/discovery-probe/ingest', [
            'source' => 'cmdb',
            'rows' => [
                ['hostname' => 'oracle-fin.internal', 'type' => 'Oracle Database', 'name' => 'Finance DB'],
                ['host' => 'mongo-log.internal', 'port' => 27017],
                ['host' => 'localhost', 'port' => 3306],
            ],
        ])->assertOk();

        $this->assertSame(2, $res->json('data.found'));

        // Porta disimpulkan dari jenis layanan ketika tidak disebutkan.
        $oracle = DiscoveryCandidate::withoutGlobalScope('org')->where('host', 'oracle-fin.internal')->first();
        $this->assertSame(1521, $oracle->port);
        $this->assertSame('oracle', $oracle->service_hint);
    }

    // ==================== Mode aktif: penjagaan berlapis ====================

    #[Test]
    public function pemindaian_aktif_ditolak_saat_saklar_platform_mati(): void
    {
        Sanctum::actingAs($this->user);

        // Tenant sudah menyiapkan segalanya — mode aktif, rentang, persetujuan.
        $this->putJson('/api/discovery-probe/config', [
            'mode' => 'active',
            'cidr_ranges' => ['10.0.0.0/30'],
            'approve_active_scan' => true,
        ])->assertOk();

        // Saklar platform tetap yang menentukan.
        $res = $this->postJson('/api/discovery-probe/scan')->assertStatus(422);
        $this->assertSame('platform', $res->json('gate'));
        $this->assertSame(0, DiscoveryCandidate::withoutGlobalScope('org')->count());
    }

    #[Test]
    public function pemindaian_aktif_ditolak_tanpa_persetujuan_tenant(): void
    {
        SystemSetting::updateOrCreate(['key' => 'discovery.active_scan_allowed'], ['value' => true, 'section' => 'discovery']);

        Sanctum::actingAs($this->user);
        $this->putJson('/api/discovery-probe/config', [
            'mode' => 'active',
            'cidr_ranges' => ['10.0.0.0/30'],
        ])->assertOk();

        $res = $this->postJson('/api/discovery-probe/scan')->assertStatus(422);
        $this->assertSame('approval', $res->json('gate'));
    }

    #[Test]
    public function pemindaian_aktif_ditolak_tanpa_rentang_ip(): void
    {
        SystemSetting::updateOrCreate(['key' => 'discovery.active_scan_allowed'], ['value' => true, 'section' => 'discovery']);

        Sanctum::actingAs($this->user);
        $this->putJson('/api/discovery-probe/config', [
            'mode' => 'active',
            'approve_active_scan' => true,
        ])->assertOk();

        $res = $this->postJson('/api/discovery-probe/scan')->assertStatus(422);
        $this->assertSame('scope', $res->json('gate'));
    }

    #[Test]
    public function persetujuan_gugur_ketika_rentang_ip_berubah(): void
    {
        SystemSetting::updateOrCreate(['key' => 'discovery.active_scan_allowed'], ['value' => true, 'section' => 'discovery']);

        Sanctum::actingAs($this->user);
        $this->putJson('/api/discovery-probe/config', [
            'mode' => 'active',
            'cidr_ranges' => ['10.0.0.0/30'],
            'approve_active_scan' => true,
        ])->assertOk();

        $config = DiscoveryProbeConfig::withoutGlobalScope('org')->first();
        $this->assertNotNull($config->active_scan_approved_at);

        // Persetujuan atas satu rentang bukan persetujuan atas rentang lain.
        $this->putJson('/api/discovery-probe/config', [
            'cidr_ranges' => ['192.168.1.0/24'],
        ])->assertOk();

        $this->assertNull($config->fresh()->active_scan_approved_at);
        $this->assertSame('approval', $this->postJson('/api/discovery-probe/scan')->json('gate'));
    }

    #[Test]
    public function penguraian_cidr_menolak_rentang_yang_terlalu_luas(): void
    {
        $service = app(ActiveDiscoveryService::class);

        // /8 berisi 16 juta alamat — tidak ada keadaan wajar di mana seseorang
        // benar-benar bermaksud memindainya.
        $this->assertSame([], $service->expand('10.0.0.0/8', 1000));
        $this->assertSame([], $service->expand('bukan-ip/24', 1000));

        // /30 = 4 alamat, dikurangi alamat jaringan dan siaran.
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $service->expand('10.0.0.0/30', 1000));

        // Alamat tunggal tetap diterima.
        $this->assertSame(['10.0.0.5'], $service->expand('10.0.0.5', 10));

        // Batas jumlah host dihormati.
        $this->assertCount(3, $service->expand('192.168.0.0/24', 3));
    }

    #[Test]
    public function pemindaian_berjalan_ketika_seluruh_lapis_terlewati(): void
    {
        SystemSetting::updateOrCreate(['key' => 'discovery.active_scan_allowed'], ['value' => true, 'section' => 'discovery']);
        SystemSetting::updateOrCreate(['key' => 'discovery.active_scan_max_hosts'], ['value' => 4, 'section' => 'discovery']);

        // Ketukan porta digantikan supaya test tidak pernah menyentuh jaringan
        // sungguhan — yang diuji adalah lapis penjagaan dan pencatatannya,
        // bukan tumpukan TCP milik sistem operasi.
        $fake = new class extends ActiveDiscoveryService
        {
            protected function probe(string $host, int $port, int $timeoutMs): bool
            {
                return $host === '10.0.0.1' && $port === 3306;
            }
        };
        $this->app->instance(ActiveDiscoveryService::class, $fake);

        Sanctum::actingAs($this->user);
        $this->putJson('/api/discovery-probe/config', [
            'mode' => 'active',
            'cidr_ranges' => ['10.0.0.0/30'],
            'ports' => [3306, 5432],
            'approve_active_scan' => true,
        ])->assertOk();

        $res = $this->postJson('/api/discovery-probe/scan')->assertOk();

        $this->assertSame(2, $res->json('data.scanned_hosts'));
        $this->assertSame(1, $res->json('data.open'));
        $this->assertSame(1, $res->json('data.new'));

        $candidate = DiscoveryCandidate::withoutGlobalScope('org')->first();
        $this->assertSame('10.0.0.1', $candidate->host);
        $this->assertSame(3306, $candidate->port);
        $this->assertSame('network_scan', $candidate->source);
        $this->assertSame('mysql', $candidate->service_hint);
    }

    #[Test]
    public function kandidat_satu_organisasi_tidak_terlihat_organisasi_lain(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/discovery-probe/ingest', [
            'source' => 'config_file',
            'text' => 'postgres://a:b@rahasia.internal:5432/db',
        ])->assertOk();

        $otherOrg = Organization::create(['name' => 'Bank Lain', 'slug' => 'lain-'.uniqid()]);
        $otherRole = TenantRole::create([
            'org_id' => $otherOrg->id,
            'name' => 'DPO',
            'permissions' => ['data_discovery:read'],
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
        $this->assertEmpty($this->getJson('/api/discovery-probe/candidates')->assertOk()->json('data.data'));
    }

    #[Test]
    public function tanpa_izin_data_discovery_penemuan_ditolak(): void
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
        $this->getJson('/api/discovery-probe/config')->assertStatus(403);
        $this->postJson('/api/discovery-probe/scan')->assertStatus(403);
    }
}
