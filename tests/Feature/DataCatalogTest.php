<?php

namespace Tests\Feature;

use App\Models\DataCatalogAsset;
use App\Models\DataCatalogLineage;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Katalog metadata terpusat dan silsilah antar aset data.
 *
 * Dua sifat yang paling menentukan: sinkronisasi ulang tidak boleh membuang
 * aset dan tepi yang dibuat manusia, dan penelusuran silsilah tidak boleh
 * berputar pada graf yang mengandung siklus.
 */
class DataCatalogTest extends TestCase
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
            'permissions' => ['data_discovery:read', 'data_discovery:write', 'ropa:read', 'ropa:write'],
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

    private function seedDiscovery(): InformationSystem
    {
        return InformationSystem::create([
            'org_id' => $this->org->id,
            'name' => 'Core Banking',
            'source_type' => 'mysql',
            'scan_results' => [
                'tables' => [
                    [
                        'name' => 'nasabah',
                        'row_count' => 1_200_000,
                        'size_mb' => 840,
                        'columns' => [
                            ['name' => 'id', 'type' => 'uuid', 'pii_detected' => false, 'classification' => 'internal'],
                            ['name' => 'nik', 'type' => 'varchar(16)', 'pii_detected' => true, 'classification' => 'sensitive', 'pdp_category' => 'spesifik', 'encryption_required' => true, 'pii_reason' => 'Pola NIK terdeteksi'],
                            ['name' => 'nama', 'type' => 'varchar(255)', 'pii_detected' => true, 'classification' => 'pii', 'pdp_category' => 'umum'],
                        ],
                    ],
                    [
                        'name' => 'audit_trail',
                        'row_count' => 50_000,
                        'columns' => [
                            ['name' => 'action', 'type' => 'varchar(50)', 'pii_detected' => false, 'classification' => 'internal'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function katalog_dibangun_dari_hasil_pemindaian(): void
    {
        $system = $this->seedDiscovery();
        Sanctum::actingAs($this->user);

        $res = $this->postJson('/api/data-catalog/sync')->assertOk();

        $this->assertSame(1, $res->json('data.systems'));
        $this->assertSame(2, $res->json('data.datasets'));
        $this->assertSame(4, $res->json('data.fields'));

        $assets = DataCatalogAsset::withoutGlobalScope('org')->get()->keyBy('asset_key');

        $this->assertTrue($assets->has('system:'.$system->id));
        $this->assertTrue($assets->has('dataset:'.$system->id.'/nasabah'));
        $this->assertTrue($assets->has('field:'.$system->id.'/nasabah/nik'));

        // Dataset mewarisi klasifikasi kolom paling sensitif di dalamnya:
        // dataset yang memuat satu kolom sensitif tidak dapat diperlakukan
        // sebagai internal biasa hanya karena sisanya tidak sensitif.
        $this->assertSame('sensitive', $assets['dataset:'.$system->id.'/nasabah']->classification);
        $this->assertSame('internal', $assets['dataset:'.$system->id.'/audit_trail']->classification);

        $nik = $assets['field:'.$system->id.'/nasabah/nik'];
        $this->assertSame('spesifik', $nik->pdp_category);
        $this->assertTrue($nik->encryption_required);
        $this->assertSame('Core Banking.nasabah.nik', $nik->qualified_name);
    }

    #[Test]
    public function silsilah_diturunkan_dari_keterkaitan_ropa(): void
    {
        $system = $this->seedDiscovery();

        $ropa = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-001',
            'processing_activity' => 'Pembukaan Rekening',
            'purpose' => 'Onboarding nasabah',
            'risk_level' => 'high',
            'recipients' => ['Biro Kredit'],
        ]);
        $ropa->informationSystems()->attach($system->id, ['org_id' => $this->org->id]);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/data-catalog/sync')->assertOk();

        $edges = DataCatalogLineage::withoutGlobalScope('org')->get();
        $activityKey = 'report:ropa/'.$ropa->id;

        // Arah tepi mengikuti aliran DATA, bukan keterkaitan administratif:
        // sistem memasok kegiatan, kegiatan mengirim ke penerima.
        $this->assertTrue($edges->contains(
            fn ($e) => $e->from_key === 'system:'.$system->id && $e->to_key === $activityKey && $e->relation === 'feeds'
        ));
        $this->assertTrue($edges->contains(
            fn ($e) => $e->from_key === $activityKey && $e->relation === 'exports' && str_contains($e->to_key, 'biro-kredit')
        ));
    }

    #[Test]
    public function penelusuran_silsilah_mencapai_hulu_dan_hilir(): void
    {
        $system = $this->seedDiscovery();
        $ropa = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-001',
            'processing_activity' => 'Pembukaan Rekening',
            'recipients' => ['Biro Kredit'],
        ]);
        $ropa->informationSystems()->attach($system->id, ['org_id' => $this->org->id]);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/data-catalog/sync')->assertOk();

        $res = $this->getJson('/api/data-catalog/trace?asset_key=report:ropa/'.$ropa->id.'&depth=3')
            ->assertOk()->json('data');

        $keys = array_column($res['nodes'], 'asset_key');

        // Ke hulu sampai sistem, ke hilir sampai penerima eksternal.
        $this->assertContains('system:'.$system->id, $keys);
        $this->assertTrue((bool) array_filter($keys, fn ($k) => str_contains($k, 'biro-kredit')));
        $this->assertGreaterThanOrEqual(2, count($res['edges']));
    }

    #[Test]
    public function penelusuran_tidak_berputar_pada_graf_bersiklus(): void
    {
        Sanctum::actingAs($this->user);

        // Siklus A → B → C → A. Tanpa penanda kunjungan, penelusuran akan
        // berputar sampai kehabisan memori.
        foreach ([['a', 'b'], ['b', 'c'], ['c', 'a']] as [$from, $to]) {
            $this->postJson('/api/data-catalog/lineage', [
                'from_key' => 'system:'.$from,
                'to_key' => 'system:'.$to,
                'relation' => 'feeds',
            ])->assertStatus(201);
        }

        $res = $this->getJson('/api/data-catalog/trace?asset_key=system:a&depth=6')->assertOk()->json('data');

        $this->assertCount(3, $res['nodes']);
        $this->assertCount(3, $res['edges']);
    }

    #[Test]
    public function aset_dan_tepi_manual_selamat_dari_sinkronisasi_ulang(): void
    {
        $this->seedDiscovery();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/data-catalog/sync')->assertOk();

        // Aset yang tidak dapat diturunkan dari pemindaian — mis. arsip fisik.
        $this->postJson('/api/data-catalog/assets', [
            'asset_key' => 'file:arsip-fisik-cabang',
            'asset_type' => 'file',
            'name' => 'Arsip Formulir Pembukaan Rekening',
            'classification' => 'sensitive',
            'steward' => 'Kepala Cabang',
        ])->assertStatus(201);

        $this->postJson('/api/data-catalog/lineage', [
            'from_key' => 'file:arsip-fisik-cabang',
            'to_key' => 'system:manual-entry',
            'relation' => 'feeds',
            'description' => 'Dipetakan saat kunjungan lapangan',
        ])->assertStatus(201);

        // Sinkronisasi ulang tidak boleh membuang pekerjaan manusia.
        $this->postJson('/api/data-catalog/sync')->assertOk();

        $manual = DataCatalogAsset::withoutGlobalScope('org')->where('asset_key', 'file:arsip-fisik-cabang')->first();
        $this->assertNotNull($manual, 'Aset manual tidak boleh hilang saat sinkronisasi ulang.');
        $this->assertSame('Kepala Cabang', $manual->steward);

        $this->assertSame(1, DataCatalogLineage::withoutGlobalScope('org')
            ->where('source', 'manual')->count());
    }

    #[Test]
    public function impor_dari_katalog_luar_tidak_bentrok_dengan_kunci_internal(): void
    {
        $system = $this->seedDiscovery();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/data-catalog/sync')->assertOk();

        $res = $this->postJson('/api/data-catalog/import', [
            'source' => 'collibra',
            'rows' => [
                ['id' => 'system:'.$system->id, 'name' => 'Aset Bernama Sama', 'type' => 'dataset'],
                ['id' => 'ASSET-002', 'name' => 'Customer Master', 'type' => 'dataset', 'parent_id' => 'ASSET-001'],
                ['name' => 'Tanpa ID'],
            ],
        ])->assertOk();

        $this->assertSame(2, $res->json('data.imported'));
        $this->assertSame(1, $res->json('data.skipped'));

        // Kunci dari luar diberi awalan sumbernya, sehingga tidak menimpa aset
        // internal meski nilainya kebetulan sama persis.
        $internal = DataCatalogAsset::withoutGlobalScope('org')->where('asset_key', 'system:'.$system->id)->first();
        $this->assertSame('Core Banking', $internal->name);
        $this->assertSame('internal', $internal->source);

        $imported = DataCatalogAsset::withoutGlobalScope('org')
            ->where('asset_key', 'collibra:system:'.$system->id)->first();
        $this->assertNotNull($imported);
        $this->assertSame('collibra', $imported->source);
    }

    #[Test]
    public function pencarian_katalog_menjangkau_nama_bertingkat_dan_steward(): void
    {
        $this->seedDiscovery();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/data-catalog/sync')->assertOk();

        $byQualified = $this->getJson('/api/data-catalog?q=nasabah.nik')->assertOk()->json('data.data');
        $this->assertNotEmpty($byQualified);

        $sensitiveOnly = $this->getJson('/api/data-catalog?classification=sensitive')->assertOk()->json('data.data');
        foreach ($sensitiveOnly as $asset) {
            $this->assertSame('sensitive', $asset['classification']);
        }
    }

    #[Test]
    public function ringkasan_melaporkan_isi_katalog(): void
    {
        $this->seedDiscovery();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/data-catalog/sync')->assertOk();

        $summary = $this->getJson('/api/data-catalog/summary')->assertOk()->json('data');

        $this->assertSame(7, $summary['total_assets']);
        $this->assertSame(1, $summary['by_type']['system']);
        $this->assertSame(2, $summary['by_type']['dataset']);
        $this->assertGreaterThan(0, $summary['sensitive_assets']);
        $this->assertNotNull($summary['last_synced_at']);
    }

    #[Test]
    public function tepi_menuju_aset_tak_dikenal_tetap_dikembalikan(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/data-catalog/lineage', [
            'from_key' => 'system:internal',
            'to_key' => 'system:pihak-ketiga-tak-terdaftar',
            'relation' => 'exports',
        ])->assertStatus(201);

        $res = $this->getJson('/api/data-catalog/trace?asset_key=system:internal')->assertOk()->json('data');

        // Tepi yang menunjuk ke luar batas katalog justru yang paling menarik
        // saat menelusuri kebocoran — ia tidak boleh disembunyikan hanya karena
        // asetnya belum terdaftar.
        $unknown = collect($res['nodes'])->firstWhere('asset_key', 'system:pihak-ketiga-tak-terdaftar');
        $this->assertNotNull($unknown);
        $this->assertSame('unknown', $unknown['asset_type']);
    }

    #[Test]
    public function katalog_satu_organisasi_tidak_terlihat_organisasi_lain(): void
    {
        $this->seedDiscovery();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/data-catalog/sync')->assertOk();

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
        $this->assertEmpty($this->getJson('/api/data-catalog')->assertOk()->json('data.data'));
        $this->assertSame(0, $this->getJson('/api/data-catalog/summary')->json('data.total_assets'));
    }

    #[Test]
    public function tanpa_izin_katalog_ditolak(): void
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'Tanpa Akses',
            'permissions' => ['consent:read'],
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
        $this->getJson('/api/data-catalog')->assertStatus(403);
        $this->postJson('/api/data-catalog/sync')->assertStatus(403);
    }
}
