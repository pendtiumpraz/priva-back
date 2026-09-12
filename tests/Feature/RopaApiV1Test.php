<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PartnerApiKey;
use App\Models\Ropa;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API mitra v1 untuk RoPA (baca).
 *
 * Janji yang dijaga di sini:
 *   1. kunci API hanya menjangkau organisasinya sendiri;
 *   2. izin `ropa.read` benar-benar diperiksa, bukan sekadar kunci yang sah;
 *   3. `wizard_data` tidak ikut terkirim kecuali diminta — isinya besar dan
 *      memuat rincian internal;
 *   4. peran pihak ketiga yang dilaporkan diambil dari pivot `ropa_vendor`,
 *      bukan dari kolom peran bawaan di baris pihak ketiganya;
 *   5. kunci urut dari klien dibatasi pada kolom yang memang ada.
 */
class RopaApiV1Test extends TestCase
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

    private function apiKey(array $permissions, ?string $orgId = null): array
    {
        $kunci = PartnerApiKey::generateKey([
            'org_id' => $orgId ?? $this->org->id,
            'name' => 'Papan Pantau GRC',
            'permissions' => $permissions,
            'environment' => 'live',
            'rate_limit_per_minute' => 120,
            'is_active' => true,
            'created_by' => $this->user->id,
        ])['key'];

        return ['X-Api-Key' => $kunci];
    }

    private function ropa(array $override = []): Ropa
    {
        return Ropa::create(array_merge([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-'.str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT),
            'processing_activity' => 'Pembukaan Rekening',
            'purpose' => 'Onboarding nasabah',
            'legal_basis' => 'kontrak',
            'division' => 'Retail',
            'risk_level' => 'high',
            'status' => 'draft',
            'wizard_data' => ['dpo_team' => ['dpo_email' => 'dpo@contoh.co.id']],
        ], $override));
    }

    public function test_menolak_tanpa_kunci_dan_tanpa_izin(): void
    {
        $this->getJson('/api/v1/ropa')->assertStatus(401);
        $this->getJson('/api/v1/ropa', $this->apiKey(['breach.read']))->assertStatus(403);
        $this->getJson('/api/v1/ropa/stats', $this->apiKey(['breach.read']))->assertStatus(403);
    }

    public function test_daftar_hanya_milik_organisasi_pemilik_kunci(): void
    {
        $this->ropa(['processing_activity' => 'Milik Kami']);

        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        $punyaTetangga = $this->ropa(['org_id' => $lain->id, 'processing_activity' => 'Milik Tetangga']);

        $res = $this->getJson('/api/v1/ropa', $this->apiKey(['ropa.read']))->assertOk();

        $res->assertJsonPath('meta.total', 1);
        $res->assertJsonPath('data.0.processing_activity', 'Milik Kami');

        $this->getJson("/api/v1/ropa/{$punyaTetangga->id}", $this->apiKey(['ropa.read']))
            ->assertStatus(404);
    }

    public function test_daftar_tidak_mengirim_wizard_data(): void
    {
        $this->ropa();

        $baris = $this->getJson('/api/v1/ropa', $this->apiKey(['ropa.read']))->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('wizard_data', $baris, 'wizard_data tidak boleh ikut di daftar.');
        $this->assertArrayHasKey('registration_number', $baris);
    }

    public function test_detail_menyembunyikan_wizard_data_kecuali_diminta(): void
    {
        $ropa = $this->ropa();
        $kunci = $this->apiKey(['ropa.read']);

        $tanpa = $this->getJson("/api/v1/ropa/{$ropa->id}", $kunci)->assertOk()->json('data');
        $this->assertArrayNotHasKey('wizard_data', $tanpa);

        $dengan = $this->getJson("/api/v1/ropa/{$ropa->id}?include=wizard_data", $kunci)->assertOk()->json('data');
        $this->assertArrayHasKey('wizard_data', $dengan);
        $this->assertSame('dpo@contoh.co.id', $dengan['wizard_data']['dpo_team']['dpo_email']);
    }

    public function test_detail_melaporkan_peran_pihak_ketiga_dari_pivot(): void
    {
        $ropa = $this->ropa();
        // Peran bawaan di baris pihak ketiga sengaja dibuat BERBEDA dari peran
        // pada kegiatan ini — yang dilaporkan harus yang dari pivot.
        $vendor = Vendor::create([
            'org_id' => $this->org->id,
            'name' => 'PT Cloud Mitra',
            'country' => 'Indonesia',
            'type' => Vendor::ROLE_PROCESSOR,
        ]);
        $ropa->vendors()->attach($vendor->id, [
            'org_id' => $this->org->id,
            'role' => Vendor::ROLE_JOINT_CONTROLLER,
            'purpose' => 'Analitik bersama',
        ]);

        $data = $this->getJson("/api/v1/ropa/{$ropa->id}", $this->apiKey(['ropa.read']))->assertOk()->json('data');

        $this->assertCount(1, $data['third_parties']);
        $this->assertSame(Vendor::ROLE_JOINT_CONTROLLER, $data['third_parties'][0]['role']);
        $this->assertSame('PT Cloud Mitra', $data['third_parties'][0]['name']);
        // Istilah "vendor" tidak boleh bocor ke payload yang dikonsumsi tenant.
        $this->assertArrayNotHasKey('vendors', $data);
    }

    public function test_ringkasan_menghitung_per_risiko_dan_status(): void
    {
        $this->ropa(['risk_level' => 'high', 'status' => 'draft']);
        $this->ropa(['risk_level' => 'low', 'status' => 'approved']);
        Organization::factory()->create(); // organisasi lain tanpa RoPA

        $data = $this->getJson('/api/v1/ropa/stats', $this->apiKey(['ropa.read']))->assertOk()->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['high_risk']);
        $this->assertSame(1, $data['by_status']['draft']);
    }

    public function test_kunci_urut_dari_klien_dibatasi(): void
    {
        $this->ropa();

        // Kolom yang tidak ada di daftar harus jatuh ke urutan bawaan,
        // bukan diteruskan mentah-mentah ke SQL.
        $this->getJson('/api/v1/ropa?sort=wizard_data', $this->apiKey(['ropa.read']))->assertOk();
        $this->getJson('/api/v1/ropa?sort=(select+1)', $this->apiKey(['ropa.read']))->assertOk();
    }
}
