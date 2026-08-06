<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\InformationSystem;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Peta koneksi RoPA.
 *
 * Yang dijaga di sini bukan bentuk JSON-nya, melainkan janji yang membuat peta
 * ini layak dipercaya: sebuah tepi hanya muncul kalau ada relasi sungguhan di
 * basis data. Tanpa itu, peta blueprint hanya jadi gambar yang meyakinkan
 * tetapi salah.
 */
class RopaGraphTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Ropa $ropa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $role = TenantRole::create([
            'org_id' => $this->org->id,
            'name' => 'DPO Uji',
            'slug' => 'dpo-uji-'.uniqid(),
            'permissions' => ['ropa:read', 'ropa:write'],
        ]);

        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id,
            'tenant_role_id' => $role->id,
        ]));

        $this->ropa = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-001',
            'processing_activity' => 'Onboarding Nasabah',
            'risk_level' => 'high',
            'status' => 'approved',
        ]);
    }

    private function graph(): array
    {
        return $this->getJson("/api/ropa/{$this->ropa->id}/graph")
            ->assertOk()
            ->json('data');
    }

    public function test_ropa_tanpa_tautan_hanya_berisi_dirinya(): void
    {
        $g = $this->graph();

        $this->assertCount(1, $g['nodes']);
        $this->assertSame('ropa:'.$this->ropa->id, $g['nodes'][0]['id']);
        $this->assertEmpty($g['edges']);
    }

    public function test_sistem_informasi_menjadi_simpul_sumber_yang_mengalir_masuk(): void
    {
        $sys = InformationSystem::create([
            'org_id' => $this->org->id,
            'name' => 'Core Banking',
            'source_type' => 'oracle',
        ]);
        $this->ropa->informationSystems()->attach($sys->id, ['org_id' => $this->org->id]);

        $g = $this->graph();

        $node = collect($g['nodes'])->firstWhere('id', 'system:'.$sys->id);
        $this->assertNotNull($node);
        $this->assertSame('data_discovery', $node['type']);
        $this->assertStringContainsString('/data-discovery', $node['href']);

        // Sumber mengalir MASUK ke RoPA: tepinya dari sistem ke RoPA.
        $edge = collect($g['edges'])->firstWhere('from', 'system:'.$sys->id);
        $this->assertSame('ropa:'.$this->ropa->id, $edge['to']);
    }

    public function test_dpia_menjadi_konsekuensi_dan_membawa_simpul_rtp(): void
    {
        $dpia = Dpia::create([
            'org_id' => $this->org->id,
            'ropa_id' => $this->ropa->id,
            'registration_number' => 'DPIA-2026-001',
            'risk_level' => 'high',
            'status' => 'draft',
            'mitigation_tracking' => [
                ['action' => 'Enkripsi', 'status' => 'completed'],
                ['action' => 'Pembatasan akses', 'status' => 'in_progress'],
            ],
        ]);

        $g = $this->graph();

        // DPIA adalah konsekuensi: tepinya dari RoPA ke DPIA.
        $edge = collect($g['edges'])->firstWhere('to', 'dpia:'.$dpia->id);
        $this->assertSame('ropa:'.$this->ropa->id, $edge['from']);

        // Item RTP menjadi satu simpul ringkasan yang menggantung pada DPIA-nya.
        $rtp = collect($g['nodes'])->firstWhere('id', 'rtp:'.$dpia->id);
        $this->assertNotNull($rtp, 'Simpul RTP seharusnya terbentuk dari mitigation_tracking.');
        $this->assertSame(2, $rtp['meta']['total']);
        $this->assertSame(1, $rtp['meta']['done']);

        $rtpEdge = collect($g['edges'])->firstWhere('from', 'dpia:'.$dpia->id);
        $this->assertSame('rtp:'.$dpia->id, $rtpEdge['to']);
    }

    public function test_dpia_tanpa_rtp_tidak_memunculkan_simpul_rtp(): void
    {
        Dpia::create([
            'org_id' => $this->org->id,
            'ropa_id' => $this->ropa->id,
            'registration_number' => 'DPIA-2026-002',
            'risk_level' => 'low',
            'status' => 'draft',
        ]);

        $g = $this->graph();

        $this->assertNull(collect($g['nodes'])->first(fn ($n) => $n['type'] === 'rtp'));
    }

    public function test_setiap_tepi_menunjuk_simpul_yang_ada(): void
    {
        // Peta yang tepinya menggantung terlihat benar tetapi tidak dapat
        // digambar — dijaga di sini karena mudah lolos dari mata.
        $sys = InformationSystem::create([
            'org_id' => $this->org->id, 'name' => 'DWH', 'source_type' => 'postgresql',
        ]);
        $this->ropa->informationSystems()->attach($sys->id, ['org_id' => $this->org->id]);
        Dpia::create([
            'org_id' => $this->org->id, 'ropa_id' => $this->ropa->id,
            'registration_number' => 'DPIA-2026-003', 'risk_level' => 'high', 'status' => 'draft',
            'mitigation_tracking' => [['action' => 'x', 'status' => 'completed']],
        ]);

        $g = $this->graph();
        $ids = collect($g['nodes'])->pluck('id')->all();

        foreach ($g['edges'] as $e) {
            $this->assertContains($e['from'], $ids, "Tepi menggantung: {$e['from']}");
            $this->assertContains($e['to'], $ids, "Tepi menggantung: {$e['to']}");
        }
    }

    public function test_tanpa_izin_ropa_ditolak(): void
    {
        $role = TenantRole::create([
            'org_id' => $this->org->id, 'name' => 'Tanpa Izin',
            'slug' => 'no-'.uniqid(), 'permissions' => [],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'org_id' => $this->org->id, 'tenant_role_id' => $role->id,
        ]));

        $this->getJson("/api/ropa/{$this->ropa->id}/graph")->assertForbidden();
    }
}
