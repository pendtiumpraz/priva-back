<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\ConnectionMapScan;
use App\Models\ConsentCollectionPoint;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use App\Models\InformationSystem;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantRole;
use App\Models\TiaAssessment;
use App\Models\User;
use App\Models\Vendor;
use App\Services\TenantStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Peta Koneksi seluruh modul (DSPM).
 *
 * Janji yang dijaga di sini:
 *   1. tepi hanya lahir dari relasi sungguhan di basis data;
 *   2. hasil scan ditulis ke storage bila terpasang, ke backend bila tidak —
 *      dan tetap tersimpan bila storage gagal ditulis;
 *   3. catatan tenant lain maupun data pribadi pemohon DSR tidak pernah
 *      masuk ke hasil scan (JSON-nya dapat berakhir di storage & diunduh).
 */
class ConnectionMapScanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->actingAsRole($this->org, ['security:read', 'security:write']);
    }

    private function actingAsRole(Organization $org, array $permissions): User
    {
        $role = TenantRole::create([
            'org_id' => $org->id,
            'name' => 'Peran Uji',
            'slug' => 'uji-'.uniqid(),
            'permissions' => $permissions,
        ]);
        $user = User::factory()->create(['org_id' => $org->id, 'tenant_role_id' => $role->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function ropa(Organization $org, string $number, string $activity, array $extra = []): Ropa
    {
        return Ropa::create(array_merge([
            'org_id' => $org->id,
            'registration_number' => $number,
            'processing_activity' => $activity,
            'risk_level' => 'low',
            'status' => 'approved',
        ], $extra));
    }

    private function system(Organization $org, string $name): InformationSystem
    {
        return InformationSystem::create(['org_id' => $org->id, 'name' => $name, 'source_type' => 'postgresql']);
    }

    private function scan(): array
    {
        return $this->postJson('/api/security/connection-map/scan')->assertCreated()->json();
    }

    private function hasEdge(array $graph, string $from, string $to): bool
    {
        return collect($graph['edges'])->contains(fn ($e) => $e['from'] === $from && $e['to'] === $to);
    }

    /** Storage eksternal palsu: TenantStorageService melaporkan S3 dan menulis ke disk fake. */
    private function fakeExternalStorage(): FilesystemAdapter
    {
        $disk = Storage::fake('connection-map-uji');
        $this->partialMock(TenantStorageService::class, function (MockInterface $m) use ($disk) {
            $m->shouldReceive('externalDriver')->andReturn('s3');
            $m->shouldReceive('getDisk')->andReturn($disk);
        });

        return $disk;
    }

    public function test_scan_merangkai_seluruh_modul_dari_relasi_nyata(): void
    {
        $thirdParty = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Cloud Mitra']);
        $ropa = $this->ropa($this->org, 'ROPA-2026-001', 'Onboarding Nasabah', [
            'risk_level' => 'high',
            'wizard_data' => ['penggunaan_penyimpanan' => ['vendor_ids' => [$thirdParty->id]]],
        ]);
        $sys = $this->system($this->org, 'Core Banking');
        $ropa->informationSystems()->attach($sys->id, ['org_id' => $this->org->id]);

        $consent = ConsentCollectionPoint::create([
            'org_id' => $this->org->id, 'collection_id' => 'cp-onboarding', 'name' => 'Form Onboarding', 'kind' => 'form',
        ]);
        $ropa->consentPoints()->attach($consent->id, ['org_id' => $this->org->id]);

        $dpia = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id, 'registration_number' => 'DPIA-2026-001',
            'risk_level' => 'high', 'status' => 'draft',
            'mitigation_tracking' => [['action' => 'Enkripsi', 'status' => 'completed']],
        ]);
        $transfer = CrossBorderTransfer::create([
            'org_id' => $this->org->id, 'destination_country' => 'SG', 'destination_entity' => 'Mitra SG',
            'linked_ropa_id' => $ropa->id, 'vendor_id' => $thirdParty->id,
        ]);
        $tia = TiaAssessment::create([
            'org_id' => $this->org->id, 'title' => 'TIA Singapura', 'linked_cross_border_id' => $transfer->id,
        ]);
        $breach = BreachIncident::create([
            'org_id' => $this->org->id, 'incident_code' => 'BRC-2026-001', 'title' => 'Kebocoran Form',
            'linked_ropa_ids' => [$ropa->id],
        ]);
        $dsr = DsrRequest::create([
            'org_id' => $this->org->id, 'request_id' => 'DSR-2026-001', 'request_type' => 'access',
            'requester_name' => 'Budi', 'requester_email' => 'budi@contoh.id',
        ]);
        DsrRequestScope::create(['dsr_request_id' => $dsr->id, 'information_system_id' => $sys->id, 'request_types' => ['access']]);

        $g = $this->scan()['graph'];

        // Nama perusahaan di pusat peta.
        $center = collect($g['nodes'])->firstWhere('id', $g['center']);
        $this->assertSame('org:'.$this->org->id, $g['center']);
        $this->assertSame('PT Nusantara Sejahtera', $center['label']);

        $r = 'ropa:'.$ropa->id;
        $this->assertTrue($this->hasEdge($g, 'system:'.$sys->id, $r), 'sistem → RoPA');
        $this->assertTrue($this->hasEdge($g, 'consent:'.$consent->id, $r), 'consent → RoPA');
        $this->assertTrue($this->hasEdge($g, $r, 'dpia:'.$dpia->id), 'RoPA → DPIA');
        $this->assertTrue($this->hasEdge($g, 'dpia:'.$dpia->id, 'rtp:'.$dpia->id), 'DPIA → RTP');
        $this->assertTrue($this->hasEdge($g, $r, 'thirdparty:'.$thirdParty->id), 'RoPA → pihak ketiga (wizard)');
        $this->assertTrue($this->hasEdge($g, $r, 'crossborder:'.$transfer->id), 'RoPA → transfer');
        $this->assertTrue($this->hasEdge($g, 'crossborder:'.$transfer->id, 'thirdparty:'.$thirdParty->id), 'transfer → penerima');
        $this->assertTrue($this->hasEdge($g, 'crossborder:'.$transfer->id, 'tia:'.$tia->id), 'transfer → TIA');
        $this->assertTrue($this->hasEdge($g, $r, 'breach:'.$breach->id), 'RoPA → insiden');
        $this->assertTrue($this->hasEdge($g, 'dsr:'.$dsr->id, 'system:'.$sys->id), 'DSR → sistem');

        $ids = collect($g['nodes'])->pluck('id')->all();
        foreach ($g['edges'] as $e) {
            $this->assertContains($e['from'], $ids, "Tepi menggantung: {$e['from']}");
            $this->assertContains($e['to'], $ids, "Tepi menggantung: {$e['to']}");
        }
    }

    public function test_tanpa_storage_eksternal_hasil_disimpan_di_backend(): void
    {
        $this->ropa($this->org, 'ROPA-2026-001', 'Payroll');

        $res = $this->scan();

        $this->assertSame('database', $res['scan']['storage_location']);
        $row = ConnectionMapScan::findOrFail($res['scan']['id']);
        $this->assertNotNull($row->payload);
        $this->assertNull($row->storage_path);

        $latest = $this->getJson('/api/security/connection-map')->assertOk()->json();
        $this->assertSame($res['scan']['id'], $latest['scan']['id']);
        $this->assertNull($latest['error']);
        $this->assertEquals($res['graph'], $latest['graph']);
    }

    public function test_storage_eksternal_terpasang_hasil_ditulis_sebagai_file_json(): void
    {
        $this->ropa($this->org, 'ROPA-2026-001', 'Payroll');
        $disk = $this->fakeExternalStorage();

        $res = $this->scan();

        $this->assertSame('storage', $res['scan']['storage_location']);
        $this->assertSame('s3', $res['scan']['storage_driver']);

        $path = "tenants/{$this->org->id}/connection-maps/{$res['scan']['id']}.json";
        $disk->assertExists($path);
        $this->assertEquals($res['graph'], json_decode($disk->get($path), true));
        $this->assertNull(ConnectionMapScan::findOrFail($res['scan']['id'])->payload, 'Graf tidak boleh diduplikasi ke DB.');

        // Tampilan membaca JSON terakhir dari storage.
        $latest = $this->getJson('/api/security/connection-map')->assertOk()->json();
        $this->assertNull($latest['error']);
        $this->assertEquals($res['graph'], $latest['graph']);
    }

    public function test_storage_gagal_ditulis_hasil_tetap_tersimpan_di_backend(): void
    {
        $this->ropa($this->org, 'ROPA-2026-001', 'Payroll');
        $broken = Mockery::mock(FilesystemAdapter::class);
        $broken->shouldReceive('put')->andThrow(new RuntimeException('bucket tidak dapat dijangkau'));
        $this->partialMock(TenantStorageService::class, function (MockInterface $m) use ($broken) {
            $m->shouldReceive('externalDriver')->andReturn('minio');
            $m->shouldReceive('getDisk')->andReturn($broken);
        });

        $res = $this->scan();

        $this->assertSame('database', $res['scan']['storage_location']);
        $this->assertStringContainsString('minio', (string) $res['scan']['storage_note']);

        $latest = $this->getJson('/api/security/connection-map')->assertOk()->json();
        $this->assertNull($latest['error']);
        $this->assertNotEmpty($latest['graph']['nodes']);
    }

    public function test_file_di_storage_yang_berubah_setelah_scan_tidak_ditampilkan(): void
    {
        $this->ropa($this->org, 'ROPA-2026-001', 'Payroll');
        $disk = $this->fakeExternalStorage();
        $res = $this->scan();

        $disk->put("tenants/{$this->org->id}/connection-maps/{$res['scan']['id']}.json", json_encode(['nodes' => [], 'edges' => []]));

        $latest = $this->getJson('/api/security/connection-map')->assertOk()->json();
        $this->assertNull($latest['graph']);
        $this->assertStringContainsString('sidik jari', $latest['error']);
    }

    public function test_catatan_tenant_lain_tidak_pernah_masuk_peta(): void
    {
        $other = Organization::factory()->create(['name' => 'PT Tetangga']);
        $otherRopa = $this->ropa($other, 'ROPA-2026-900', 'Rahasia Tetangga');
        $otherSys = $this->system($other, 'Sistem Tetangga');
        $otherRopa->informationSystems()->attach($otherSys->id, ['org_id' => $other->id]);
        $otherThirdParty = Vendor::create(['org_id' => $other->id, 'name' => 'Mitra Tetangga']);

        // Tautan salah-scope: RoPA org ini menunjuk sistem & pihak ketiga milik tenant lain.
        $mine = $this->ropa($this->org, 'ROPA-2026-001', 'Payroll', [
            'wizard_data' => ['penggunaan_penyimpanan' => ['vendor_ids' => [$otherThirdParty->id]]],
        ]);
        DB::table('information_system_ropa')->insert([
            'information_system_id' => $otherSys->id, 'ropa_id' => $mine->id, 'org_id' => $this->org->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $json = json_encode($this->scan()['graph']);

        foreach ([$otherRopa->id, $otherSys->id, $otherThirdParty->id, 'Rahasia Tetangga', 'Sistem Tetangga', 'Mitra Tetangga', 'PT Tetangga'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "Bocor lintas tenant: {$leak}");
        }
    }

    public function test_hasil_scan_tenant_lain_tidak_dapat_dibuka(): void
    {
        $other = Organization::factory()->create();
        $theirs = ConnectionMapScan::create([
            'org_id' => $other->id,
            'storage_location' => ConnectionMapScan::LOCATION_DATABASE,
            'payload' => ['nodes' => [], 'edges' => []],
            'scanned_at' => now(),
        ]);

        $this->getJson("/api/security/connection-map/scans/{$theirs->id}")->assertNotFound();
        $this->getJson('/api/security/connection-map')->assertOk()->assertJsonPath('scan', null);
    }

    public function test_catatan_terhapus_dan_insiden_simulasi_tidak_dipetakan(): void
    {
        $ropa = $this->ropa($this->org, 'ROPA-2026-001', 'Payroll');
        $gone = $this->ropa($this->org, 'ROPA-2026-002', 'Sudah Dihapus');
        $gone->delete();
        $drill = new BreachIncident;
        $drill->forceFill([
            'org_id' => $this->org->id, 'incident_code' => 'SIM-2026-001', 'title' => 'Latihan Tabletop',
            'is_simulation' => true, 'linked_ropa_ids' => [$ropa->id],
        ])->save();

        $ids = collect($this->scan()['graph']['nodes'])->pluck('id')->all();

        $this->assertContains('ropa:'.$ropa->id, $ids);
        $this->assertNotContains('ropa:'.$gone->id, $ids);
        $this->assertNotContains('breach:'.$drill->id, $ids);
    }

    public function test_dsr_tidak_membawa_data_pribadi_pemohon(): void
    {
        $sys = $this->system($this->org, 'CRM');
        $dsr = DsrRequest::create([
            'org_id' => $this->org->id, 'request_id' => 'DSR-2026-001', 'request_type' => 'erasure',
            'requester_name' => 'Siti Rahmawati', 'requester_email' => 'siti@contoh.id',
            'requester_phone' => '081234567890', 'description' => 'Tolong hapus data saya',
        ]);
        DsrRequestScope::create(['dsr_request_id' => $dsr->id, 'information_system_id' => $sys->id, 'request_types' => ['erasure']]);

        $g = $this->scan()['graph'];
        $json = json_encode($g);

        foreach (['Siti Rahmawati', 'siti@contoh.id', '081234567890', 'hapus data saya'] as $pii) {
            $this->assertStringNotContainsString($pii, $json, "Data pribadi pemohon bocor: {$pii}");
        }
        $this->assertSame('DSR-2026-001', collect($g['nodes'])->firstWhere('id', 'dsr:'.$dsr->id)['label']);
    }

    public function test_insight_menandai_celah_postur(): void
    {
        $risky = $this->ropa($this->org, 'ROPA-2026-001', 'Profiling Kredit', ['risk_level' => 'high']);
        $orphan = $this->system($this->org, 'Server Lama');

        $insights = collect($this->scan()['graph']['insights'])->keyBy('key');

        $this->assertContains('ropa:'.$risky->id, $insights['high_risk_ropa_without_dpia']['node_ids']);
        $this->assertContains('system:'.$orphan->id, $insights['system_without_ropa']['node_ids']);
    }

    public function test_modul_yang_dicabut_tidak_ikut_dipetakan(): void
    {
        $ropa = $this->ropa($this->org, 'ROPA-2026-001', 'Payroll');
        $dpia = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id,
            'registration_number' => 'DPIA-2026-001', 'status' => 'draft',
            'mitigation_tracking' => [['action' => 'Enkripsi', 'status' => 'completed']],
        ]);

        // Sebelum dicabut: DPIA dan ringkasan RTP-nya terpetakan.
        $sebelum = collect($this->scan()['graph']['nodes'])->pluck('id')->all();
        $this->assertContains('dpia:'.$dpia->id, $sebelum);
        $this->assertContains('rtp:'.$dpia->id, $sebelum);

        $menu = MenuItem::create([
            'menu_key' => 'dpia', 'label' => 'DPIA', 'href' => '/dpia',
            'icon' => 'Shield', 'section' => 'PDP Modules', 'sort_order' => 100,
        ]);
        TenantModuleEntitlement::create([
            'org_id' => $this->org->id, 'menu_id' => $menu->id, 'is_entitled' => false,
        ]);
        $this->app->forgetScopedInstances();

        $g = $this->scan()['graph'];
        $ids = collect($g['nodes'])->pluck('id')->all();

        $this->assertNotContains('dpia:'.$dpia->id, $ids, 'modul tercabut tidak boleh dipetakan');
        $this->assertNotContains('rtp:'.$dpia->id, $ids,
            'RTP hidup di dalam DPIA — mencabut DPIA tidak boleh menyisakan simpulnya');

        // Tidak ada tepi yatim yang tetap memperlihatkan keberadaannya.
        foreach ($g['edges'] as $e) {
            $this->assertStringNotContainsString('dpia:', $e['from'].$e['to']);
        }

        // Jumlahnya pun tidak dilaporkan — angka itu sendiri membocorkan berapa
        // record yang tenant tidak lagi berhak lihat.
        $modul = collect($g['modules'])->keyBy('type');
        $this->assertSame(0, $modul['dpia']['total']);
    }

    public function test_izin_modul_security_menjaga_baca_dan_scan(): void
    {
        $this->actingAsRole($this->org, ['security:read']);
        $this->getJson('/api/security/connection-map')->assertOk();
        $this->postJson('/api/security/connection-map/scan')->assertForbidden();

        $this->actingAsRole($this->org, []);
        $this->getJson('/api/security/connection-map')->assertForbidden();
    }

    public function test_riwayat_scan_terbaru_di_atas_tanpa_graf(): void
    {
        $first = $this->scan()['scan']['id'];
        $this->travel(5)->minutes();
        $second = $this->scan()['scan']['id'];

        $rows = $this->getJson('/api/security/connection-map/scans')->assertOk()->json('data');

        $this->assertSame([$second, $first], array_column($rows, 'id'));
        $this->assertArrayNotHasKey('graph', $rows[0]);
        $this->assertArrayNotHasKey('payload', $rows[0]);

        // Scan lama tetap dapat dibuka.
        $this->getJson("/api/security/connection-map/scans/{$first}")->assertOk()->assertJsonPath('scan.id', $first);
    }
}
