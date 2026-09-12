<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\PartnerApiKey;
use App\Models\Ropa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API mitra v1 untuk DPIA (baca).
 *
 * Janji yang dijaga di sini sama seperti RoPA:
 *   1. kunci API hanya menjangkau organisasinya sendiri;
 *   2. izin `dpia.read` benar-benar diperiksa — izin RoPA tidak menular;
 *   3. `wizard_data` hanya terkirim bila diminta;
 *   4. seluruh RoPA yang dinaungi satu DPIA ikut dilaporkan, bukan hanya
 *      induk warisan di kolom `ropa_id`.
 */
class DpiaApiV1Test extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'PT Nusantara Sejahtera']);
        $this->user = User::factory()->create(['org_id' => $this->org->id]);
    }

    private function apiKey(array $permissions): array
    {
        $kunci = PartnerApiKey::generateKey([
            'org_id' => $this->org->id,
            'name' => 'Papan Pantau GRC',
            'permissions' => $permissions,
            'environment' => 'live',
            'rate_limit_per_minute' => 120,
            'is_active' => true,
            'created_by' => $this->user->id,
        ])['key'];

        return ['X-Api-Key' => $kunci];
    }

    private function dpia(array $override = []): Dpia
    {
        return Dpia::create(array_merge([
            'org_id' => $this->org->id,
            'registration_number' => 'DPIA-2026-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'risk_level' => 'high',
            'status' => 'draft',
            'description' => 'Penilaian dampak pembukaan rekening',
            'wizard_data' => ['potensi_risiko' => ['catatan' => 'rahasia internal']],
        ], $override));
    }

    public function test_menolak_tanpa_kunci_dan_izin_ropa_tidak_menular(): void
    {
        $this->getJson('/api/v1/dpia')->assertStatus(401);

        // Izin RoPA tidak boleh membuka DPIA — keduanya scope terpisah.
        $this->getJson('/api/v1/dpia', $this->apiKey(['ropa.read']))->assertStatus(403);
        $this->getJson('/api/v1/dpia/stats', $this->apiKey(['ropa.read']))->assertStatus(403);
    }

    public function test_daftar_hanya_milik_organisasi_pemilik_kunci(): void
    {
        $this->dpia(['description' => 'Milik Kami']);

        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        $punyaTetangga = $this->dpia(['org_id' => $lain->id, 'description' => 'Milik Tetangga']);

        $res = $this->getJson('/api/v1/dpia', $this->apiKey(['dpia.read']))->assertOk();

        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.description', 'Milik Kami');

        $this->getJson("/api/v1/dpia/{$punyaTetangga->id}", $this->apiKey(['dpia.read']))
            ->assertStatus(404);
    }

    public function test_wizard_data_hanya_terkirim_bila_diminta(): void
    {
        $dpia = $this->dpia();
        $kunci = $this->apiKey(['dpia.read']);

        $baris = $this->getJson('/api/v1/dpia', $kunci)->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('wizard_data', $baris);

        $tanpa = $this->getJson("/api/v1/dpia/{$dpia->id}", $kunci)->assertOk()->json('data');
        $this->assertArrayNotHasKey('wizard_data', $tanpa);

        $dengan = $this->getJson("/api/v1/dpia/{$dpia->id}?include=wizard_data", $kunci)->assertOk()->json('data');
        $this->assertSame('rahasia internal', $dengan['wizard_data']['potensi_risiko']['catatan']);
    }

    public function test_detail_melaporkan_seluruh_ropa_yang_dinaungi(): void
    {
        $dpia = $this->dpia();
        $ropaA = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-001',
            'processing_activity' => 'Pembukaan Rekening',
            'risk_level' => 'high',
        ]);
        $ropaB = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-002',
            'processing_activity' => 'Penagihan',
            'risk_level' => 'medium',
        ]);
        $dpia->ropas()->attach([
            $ropaA->id => ['org_id' => $this->org->id],
            $ropaB->id => ['org_id' => $this->org->id],
        ]);

        $data = $this->getJson("/api/v1/dpia/{$dpia->id}", $this->apiKey(['dpia.read']))->assertOk()->json('data');

        $this->assertCount(2, $data['linked_ropas']);
        $this->assertEqualsCanonicalizing(
            ['ROPA-2026-001', 'ROPA-2026-002'],
            array_column($data['linked_ropas'], 'registration_number')
        );
        $this->assertArrayNotHasKey('ropas', $data);
    }

    public function test_ringkasan_menghitung_per_risiko_dan_status(): void
    {
        $this->dpia(['risk_level' => 'high', 'status' => 'draft']);
        $this->dpia(['risk_level' => 'low', 'status' => 'approved']);

        $data = $this->getJson('/api/v1/dpia/stats', $this->apiKey(['dpia.read']))->assertOk()->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['high_risk']);
        $this->assertSame(1, $data['by_status']['approved']);
    }

    public function test_kunci_urut_dari_klien_dibatasi(): void
    {
        $this->dpia();

        $this->getJson('/api/v1/dpia?sort=wizard_data', $this->apiKey(['dpia.read']))->assertOk();
        $this->getJson('/api/v1/dpia?sort=(select+1)', $this->apiKey(['dpia.read']))->assertOk();
    }
}
