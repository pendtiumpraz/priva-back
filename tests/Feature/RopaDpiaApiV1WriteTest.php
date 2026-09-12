<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\PartnerApiKey;
use App\Models\Ropa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jalur TULIS RoPA/DPIA lewat kunci API mitra.
 *
 * Inti yang dijaga: menulis lewat kunci API harus menghasilkan akibat yang SAMA
 * PERSIS dengan menulis dari aplikasi — penomoran, jejak perhitungan risiko,
 * DPIA draf otomatis, LIA draf otomatis, dan sinkronisasi pivot. Kalau dua jalur
 * ini berbeda, artinya logikanya tersalin, dan salinan cepat atau lambat
 * menyimpang (itulah sebab temuan F-03).
 *
 * Yang juga dikunci di sini: pada kunci API memang tidak ada pengguna yang
 * login, dan jejaknya harus jujur mengatakan itu — `created_by` null, bukan
 * ditambal pengguna palsu supaya kolomnya terisi.
 */
class RopaDpiaApiV1WriteTest extends TestCase
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

    /** @param array<int, string> $permissions */
    private function kunci(array $permissions, ?string $orgId = null): array
    {
        $raw = PartnerApiKey::generateKey([
            'org_id' => $orgId ?? $this->org->id,
            'name' => 'Sistem Pencatatan',
            'permissions' => $permissions,
            'environment' => 'live',
            'rate_limit_per_minute' => 120,
            'is_active' => true,
            'created_by' => $this->user->id,
        ])['key'];

        return ['X-Api-Key' => $raw];
    }

    public function test_menolak_tanpa_kunci_dan_izin_baca_tidak_memberi_hak_tulis(): void
    {
        $this->postJson('/api/v1/ropa', ['processing_activity' => 'Uji'])->assertStatus(401);

        $this->postJson('/api/v1/ropa', ['processing_activity' => 'Uji'], $this->kunci(['ropa.read']))
            ->assertStatus(403);

        $this->postJson('/api/v1/dpia', ['description' => 'Uji'], $this->kunci(['dpia.read']))
            ->assertStatus(403);
    }

    public function test_membuat_ropa_lewat_kunci_api_tanpa_pengguna(): void
    {
        $res = $this->postJson('/api/v1/ropa', [
            'processing_activity' => 'Pembukaan Rekening',
            'purpose' => 'Onboarding nasabah',
        ], $this->kunci(['ropa.write']))->assertCreated();

        $ropa = Ropa::withoutGlobalScope('org')->find($res->json('data.id'));

        $this->assertSame($this->org->id, $ropa->org_id);
        $this->assertMatchesRegularExpression('/^ROPA-'.date('Y').'-\d{3}$/', $ropa->registration_number);
        // Tidak ada yang login — jejaknya mengatakan apa adanya.
        $this->assertNull($ropa->created_by);
    }

    public function test_efek_samping_sama_persis_dengan_jalur_aplikasi(): void
    {
        $res = $this->postJson('/api/v1/ropa', [
            'processing_activity' => 'Skoring Kredit',
            'legal_basis' => 'kepentingan sah',
            'risk_level' => 'high',
            'risk_level_locked' => true,
        ], $this->kunci(['ropa.write']))->assertCreated();

        // DPIA draf otomatis, sama seperti menulis dari aplikasi.
        $this->assertNotNull($res->json('auto_dpia_id'));
        $dpia = Dpia::withoutGlobalScope('org')->find($res->json('auto_dpia_id'));
        $this->assertSame($res->json('data.id'), $dpia->ropa_id);
        $this->assertSame('draft', $dpia->status);

        // LIA draf otomatis untuk dasar hukum kepentingan sah.
        $this->assertNotNull($res->json('auto_lia_id'));

        // Jejak perhitungan risiko tetap ditulis ke wizard_data.
        $ropa = Ropa::withoutGlobalScope('org')->find($res->json('data.id'));
        $this->assertArrayHasKey('risk_triggers', $ropa->wizard_data ?? []);
    }

    public function test_org_id_dan_created_by_kiriman_klien_diabaikan(): void
    {
        $lain = Organization::factory()->create(['name' => 'PT Tetangga']);
        $orangLain = User::factory()->create(['org_id' => $lain->id]);

        $res = $this->postJson('/api/v1/ropa', [
            'processing_activity' => 'Percobaan Menyeberang',
            'org_id' => $lain->id,
            'created_by' => $orangLain->id,
        ], $this->kunci(['ropa.write']))->assertCreated();

        $ropa = Ropa::withoutGlobalScope('org')->find($res->json('data.id'));

        $this->assertSame($this->org->id, $ropa->org_id, 'kunci API terikat organisasinya sendiri');
        $this->assertNull($ropa->created_by, 'pelaku tidak boleh ditentukan dari payload');
    }

    public function test_membuat_dpia_dan_menautkan_ropa_dari_wizard(): void
    {
        $r1 = Ropa::create([
            'org_id' => $this->org->id,
            'registration_number' => 'ROPA-2026-901',
            'processing_activity' => 'Kegiatan Satu',
        ]);

        $res = $this->postJson('/api/v1/dpia', [
            'description' => 'DPIA Marketing Stack',
            'wizard_data' => ['koneksi_ropa' => ['connected_ropas' => [$r1->id]]],
        ], $this->kunci(['dpia.write']))->assertCreated();

        $dpiaId = $res->json('data.id');
        $this->assertMatchesRegularExpression(
            '/^DPIA-'.date('Y').'-\d{3}$/',
            Dpia::withoutGlobalScope('org')->find($dpiaId)->registration_number,
        );
        $this->assertSame(
            [$r1->id],
            DB::table('dpia_ropa')->where('dpia_id', $dpiaId)->pluck('ropa_id')->all(),
        );
    }

    public function test_validasi_menolak_payload_tanpa_kegiatan(): void
    {
        $this->postJson('/api/v1/ropa', ['purpose' => 'tanpa kegiatan'], $this->kunci(['ropa.write']))
            ->assertStatus(422);
    }
}
