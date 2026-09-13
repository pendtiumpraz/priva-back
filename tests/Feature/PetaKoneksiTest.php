<?php

namespace Tests\Feature;

use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\InformationSystem;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantRole;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Peta koneksi per record dan per modul.
 *
 * Bedanya dengan peta DSPM: yang ini DIHITUNG SAAT DIMINTA, bukan dibaca dari
 * hasil scan tersimpan. Tombolnya ada di baris tabel dan ditekan tepat sesudah
 * orang membuat tautan — peta yang hanya sesegar scan terakhir akan menampilkan
 * keadaan lama dan terasa rusak.
 *
 * Yang dikunci di sini:
 *   1. tepi hanya lahir dari relasi yang benar-benar ada, dengan ARAH yang benar;
 *   2. record tanpa tautan tetap menampilkan dirinya sendiri — kosong yang jujur,
 *      bukan tampilan yang seperti gagal memuat;
 *   3. peta global dikelompokkan per tahap, dengan urutan tahap sesuai alurnya;
 *   4. record tenant lain tidak pernah masuk, bahkan bila ada baris pivot yang
 *      salah scope;
 *   5. modul tanpa relasi tidak punya peta sama sekali;
 *   6. label DSR tidak pernah membawa data pribadi pemohon.
 */
class PetaKoneksiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'dpo',
            'slug' => 'role-'.uniqid(),
            'permissions' => ['*'],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id,
            'role' => 'dpo',
            'tenant_role_id' => $role->id,
        ]));
    }

    private function ropa(string $nomor, string $kegiatan, array $extra = []): Ropa
    {
        return Ropa::create(array_merge([
            'org_id' => $this->org->id,
            'registration_number' => $nomor,
            'processing_activity' => $kegiatan,
            'status' => 'approved',
        ], $extra));
    }

    private function hasEdge(array $g, string $from, string $to): bool
    {
        return collect($g['edges'])->contains(fn ($e) => $e['from'] === $from && $e['to'] === $to);
    }

    private function ids(array $g): array
    {
        return collect($g['nodes'])->pluck('id')->all();
    }

    public function test_peta_satu_ropa_menampilkan_tetangganya_dengan_arah_benar(): void
    {
        $ropa = $this->ropa('ROPA-2026-001', 'Pembukaan Rekening');
        $sistem = InformationSystem::create(['org_id' => $this->org->id, 'name' => 'Core Banking', 'source_type' => 'postgresql']);
        $ropa->informationSystems()->attach($sistem->id, ['org_id' => $this->org->id]);

        $consent = ConsentCollectionPoint::create([
            'org_id' => $this->org->id, 'collection_id' => 'cp-1', 'name' => 'Form Onboarding', 'kind' => 'form',
        ]);
        $ropa->consentPoints()->attach($consent->id, ['org_id' => $this->org->id]);

        $dpia = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id,
            'registration_number' => 'DPIA-2026-001', 'status' => 'draft',
        ]);
        $pihak = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Awan Data']);
        DB::table('ropa_vendor')->insert([
            'ropa_id' => $ropa->id, 'vendor_id' => $pihak->id, 'org_id' => $this->org->id,
            'role' => Vendor::ROLE_PROCESSOR, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $transfer = CrossBorderTransfer::create([
            'org_id' => $this->org->id, 'destination_country' => 'SG',
            'destination_entity' => 'Mitra SG', 'linked_ropa_id' => $ropa->id,
        ]);
        $breach = BreachIncident::create([
            'org_id' => $this->org->id, 'incident_code' => 'BRC-2026-001',
            'title' => 'Kebocoran Form', 'linked_ropa_ids' => [$ropa->id],
        ]);

        $g = $this->getJson("/api/peta-koneksi/ropa/{$ropa->id}")->assertOk()->json('data');

        $r = 'ropa:'.$ropa->id;
        $this->assertSame($r, $g['center']);
        $this->assertSame('ropa', $g['center_type']);

        // Sumber mengalir MASUK; konsekuensi mengalir KELUAR.
        $this->assertTrue($this->hasEdge($g, 'system:'.$sistem->id, $r), 'sistem → RoPA');
        $this->assertTrue($this->hasEdge($g, 'consent:'.$consent->id, $r), 'consent → RoPA');
        $this->assertTrue($this->hasEdge($g, $r, 'dpia:'.$dpia->id), 'RoPA → DPIA');
        $this->assertTrue($this->hasEdge($g, $r, 'thirdparty:'.$pihak->id), 'RoPA → pihak ketiga');
        $this->assertTrue($this->hasEdge($g, $r, 'crossborder:'.$transfer->id), 'RoPA → transfer');
        $this->assertTrue($this->hasEdge($g, $r, 'breach:'.$breach->id), 'RoPA → insiden');

        // Tidak boleh ada tepi menggantung.
        $ids = $this->ids($g);
        foreach ($g['edges'] as $e) {
            $this->assertContains($e['from'], $ids);
            $this->assertContains($e['to'], $ids);
        }
    }

    public function test_record_tanpa_tautan_tetap_menampilkan_dirinya(): void
    {
        $ropa = $this->ropa('ROPA-2026-002', 'Belum Tertaut');

        $g = $this->getJson("/api/peta-koneksi/ropa/{$ropa->id}")->assertOk()->json('data');

        $this->assertSame(['ropa:'.$ropa->id], $this->ids($g));
        $this->assertSame([], $g['edges']);
        $this->assertSame('ropa:'.$ropa->id, $g['center'], 'pusat harus tetap ada agar tidak terlihat gagal memuat');
    }

    public function test_pihak_ketiga_pemegang_sistem_muncul_di_peta(): void
    {
        // Relasi yang baru ditambahkan lewat pivot information_system_vendor —
        // sebelumnya tidak ada peta mana pun yang mengetahuinya.
        $sistem = InformationSystem::create(['org_id' => $this->org->id, 'name' => 'CRM', 'source_type' => 'mysql']);
        $saas = Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Awan Data']);
        DB::table('information_system_vendor')->insert([
            'id' => (string) Str::uuid(), 'org_id' => $this->org->id,
            'information_system_id' => $sistem->id, 'vendor_id' => $saas->id,
            'role' => Vendor::ROLE_PROCESSOR, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $g = $this->getJson("/api/peta-koneksi/data-discovery/{$sistem->id}")->assertOk()->json('data');

        $this->assertTrue($this->hasEdge($g, 'thirdparty:'.$saas->id, 'system:'.$sistem->id));
    }

    public function test_sistem_terdampak_insiden_muncul_di_peta(): void
    {
        $sistem = InformationSystem::create(['org_id' => $this->org->id, 'name' => 'CRM', 'source_type' => 'mysql']);
        $breach = BreachIncident::create([
            'org_id' => $this->org->id, 'incident_code' => 'BRC-2026-002', 'title' => 'Akses tidak sah',
            'affected_systems' => [['information_system_id' => $sistem->id, 'system_name' => 'CRM', 'tables' => []]],
        ]);

        $g = $this->getJson("/api/peta-koneksi/breach/{$breach->id}")->assertOk()->json('data');

        $this->assertTrue($this->hasEdge($g, 'system:'.$sistem->id, 'breach:'.$breach->id));
    }

    public function test_peta_global_tprm_dikelompokkan_per_tahap_sesuai_alur(): void
    {
        Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Masih Screening', 'lifecycle_status' => 'prospective']);
        Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Sudah Aktif', 'lifecycle_status' => 'active']);
        Vendor::create(['org_id' => $this->org->id, 'name' => 'PT Sedang Offboarding', 'lifecycle_status' => 'offboarding']);

        $g = $this->getJson('/api/peta-koneksi/vendor-risk')->assertOk()->json('data');

        $this->assertNull($g['center'], 'peta global tidak punya satu pusat');
        $this->assertSame('third_party', $g['center_type']);
        // Urutan mengikuti alur tahap, bukan urutan kemunculan data.
        $this->assertSame(['Screening', 'Aktif', 'Offboarding'], $g['lanes']);

        $lanePer = collect($g['nodes'])->pluck('lane', 'label');
        $this->assertSame('Screening', $lanePer['PT Masih Screening']);
        $this->assertSame('Offboarding', $lanePer['PT Sedang Offboarding']);
    }

    public function test_peta_global_memuat_record_yang_belum_tertaut(): void
    {
        $sendirian = $this->ropa('ROPA-2026-003', 'Menggantung Sendiri');

        $g = $this->getJson('/api/peta-koneksi/ropa')->assertOk()->json('data');

        $this->assertContains('ropa:'.$sendirian->id, $this->ids($g),
            'justru yang belum tertaut yang ingin terlihat di peta global');
    }

    public function test_record_tenant_lain_tidak_pernah_masuk(): void
    {
        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        $sistemLain = InformationSystem::create(['org_id' => $lain->id, 'name' => 'Sistem Tetangga', 'source_type' => 'mysql']);
        $ropaSaya = $this->ropa('ROPA-2026-004', 'Payroll');

        // Tautan salah scope: pivot milik org saya menunjuk sistem tenant lain.
        DB::table('information_system_ropa')->insert([
            'information_system_id' => $sistemLain->id, 'ropa_id' => $ropaSaya->id,
            'org_id' => $this->org->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $g = $this->getJson("/api/peta-koneksi/ropa/{$ropaSaya->id}")->assertOk()->json('data');

        $this->assertNotContains('system:'.$sistemLain->id, $this->ids($g));
        $this->assertStringNotContainsString('Sistem Tetangga', json_encode($g));
    }

    public function test_modul_tanpa_relasi_tidak_punya_peta(): void
    {
        $this->getJson('/api/peta-koneksi/gap')->assertStatus(404);
        $this->getJson('/api/peta-koneksi/maturity')->assertStatus(404);

        $tersedia = $this->getJson('/api/peta-koneksi/modul-tersedia')->assertOk()->json();
        $this->assertContains('ropa', $tersedia['data']);
        $this->assertNotContains('gap', $tersedia['data']);
    }

    /** Cabut entitlement satu modul untuk org ini. */
    private function cabut(string $menuKey): void
    {
        $menu = MenuItem::create([
            'menu_key' => $menuKey,
            'label' => strtoupper($menuKey),
            'href' => '/'.$menuKey,
            'icon' => 'Shield',
            'section' => 'PDP Modules',
            'sort_order' => 100,
        ]);
        TenantModuleEntitlement::create([
            'org_id' => $this->org->id,
            'menu_id' => $menu->id,
            'is_entitled' => false,
        ]);

        // EntitlementService diikat `scoped` dan menyimpan cache menu tercabut.
        // Di produksi batas request membuang instansnya; harness uji tidak
        // menyediakan batas itu, jadi ditiru di sini. Ini BUKAN melonggarkan uji
        // — fase "terlihat sebelum dicabut" justru yang membuktikan pencabutan
        // benar-benar mengubah keadaan.
        $this->app->forgetScopedInstances();
    }

    public function test_simpul_membawa_keterangan_bukan_kosong(): void
    {
        // Peta per-record dulu mengirim `meta` kosong sementara peta DSPM
        // membawa risk/status/severity — kartu simpulnya jadi miskin tanpa
        // alasan. Keduanya kini membaca spesifikasi yang sama dari katalog.
        $ropa = $this->ropa('ROPA-2026-020', 'Profiling Kredit', ['risk_level' => 'high']);
        $breach = BreachIncident::create([
            'org_id' => $this->org->id, 'incident_code' => 'BRC-2026-020',
            'title' => 'Kebocoran', 'severity' => 'critical', 'status' => 'detected',
            'linked_ropa_ids' => [$ropa->id],
        ]);

        $g = $this->getJson("/api/peta-koneksi/ropa/{$ropa->id}")->assertOk()->json('data');
        $nodes = collect($g['nodes'])->keyBy('id');

        $this->assertSame('high', $nodes['ropa:'.$ropa->id]['meta']['risk']);
        $this->assertSame('approved', $nodes['ropa:'.$ropa->id]['meta']['status']);
        $this->assertSame('critical', $nodes['breach:'.$breach->id]['meta']['severity']);
    }

    public function test_meta_dsr_tidak_pernah_memuat_identitas_pemohon(): void
    {
        $sistem = InformationSystem::create(['org_id' => $this->org->id, 'name' => 'CRM', 'source_type' => 'mysql']);
        $dsr = DsrRequest::create([
            'org_id' => $this->org->id, 'request_id' => 'DSR-2026-020', 'request_type' => 'erasure',
            'requester_name' => 'Siti Rahmawati', 'requester_email' => 'siti@contoh.id',
            'status' => 'new',
        ]);
        DB::table('dsr_request_scopes')->insert([
            'id' => (string) Str::uuid(), 'dsr_request_id' => $dsr->id,
            'information_system_id' => $sistem->id, 'request_types' => json_encode(['erasure']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $g = $this->getJson("/api/peta-koneksi/dsr/{$dsr->id}")->assertOk()->json('data');
        $meta = collect($g['nodes'])->firstWhere('id', 'dsr:'.$dsr->id)['meta'];

        // Kunci meta DSR dikunci ke `status` saja. Grafnya dapat berakhir sebagai
        // berkas JSON di storage dan diunduh, jadi menambah kolom apa pun di sini
        // harus keputusan sadar — bukan efek samping menambah spesifikasi meta.
        $this->assertSame(['status'], array_keys($meta));
    }

    public function test_modul_yang_dicabut_tidak_muncul_sebagai_tetangga(): void
    {
        $ropa = $this->ropa('ROPA-2026-010', 'Punya DPIA');
        $dpia = Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $ropa->id,
            'registration_number' => 'DPIA-2026-010', 'status' => 'draft',
        ]);

        // Sebelum dicabut: DPIA terlihat sebagai tetangga.
        $sebelum = $this->getJson("/api/peta-koneksi/ropa/{$ropa->id}")->assertOk()->json('data');
        $this->assertContains('dpia:'.$dpia->id, $this->ids($sebelum));

        $this->cabut('dpia');

        $sesudah = $this->getJson("/api/peta-koneksi/ropa/{$ropa->id}")->assertOk()->json('data');

        $this->assertNotContains('dpia:'.$dpia->id, $this->ids($sesudah),
            'modul yang dicabut tidak boleh muncul walau sebagai tetangga');

        // Tidak boleh ada tepi yatim yang tetap membocorkan keberadaannya.
        foreach ($sesudah['edges'] as $e) {
            $this->assertStringNotContainsString('dpia:', $e['from'].$e['to']);
        }

        // Jumlah yang disembunyikan sengaja TIDAK dilaporkan — itu sendiri
        // membocorkan berapa record yang tenant tidak lagi berhak lihat.
        $this->assertStringNotContainsString('disembunyikan', json_encode($sesudah));
    }

    public function test_modul_yang_dicabut_tidak_bisa_dibuka_petanya_sendiri(): void
    {
        $this->cabut('dpia');

        // Gerbang lama (CheckPermission) tetap berlaku untuk modul yang dipusatkan.
        $this->getJson('/api/peta-koneksi/dpia')->assertStatus(403);
    }

    public function test_label_dsr_tidak_membawa_data_pribadi_pemohon(): void
    {
        $sistem = InformationSystem::create(['org_id' => $this->org->id, 'name' => 'CRM', 'source_type' => 'mysql']);
        $dsr = DsrRequest::create([
            'org_id' => $this->org->id, 'request_id' => 'DSR-2026-001', 'request_type' => 'erasure',
            'requester_name' => 'Siti Rahmawati', 'requester_email' => 'siti@contoh.id',
        ]);
        DB::table('dsr_request_scopes')->insert([
            'id' => (string) Str::uuid(), 'dsr_request_id' => $dsr->id,
            'information_system_id' => $sistem->id, 'request_types' => json_encode(['erasure']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $g = $this->getJson("/api/peta-koneksi/dsr/{$dsr->id}")->assertOk()->json('data');
        $json = json_encode($g);

        foreach (['Siti Rahmawati', 'siti@contoh.id'] as $pii) {
            $this->assertStringNotContainsString($pii, $json, "Data pribadi pemohon bocor: {$pii}");
        }
        $this->assertSame('DSR-2026-001', collect($g['nodes'])->firstWhere('id', 'dsr:'.$dsr->id)['label']);
        $this->assertTrue($this->hasEdge($g, 'dsr:'.$dsr->id, 'system:'.$sistem->id));
    }
}
