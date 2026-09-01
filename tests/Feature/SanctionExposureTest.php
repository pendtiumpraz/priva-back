<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PpdpAppointment;
use App\Models\Ropa;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Paparan sanksi administratif (PP 33/2026 Pasal 184-186).
 *
 * Menautkan daftar pasal ber-sanksi dengan state platform: kewajiban ber-signal
 * dinilai at_risk/ok, dan denda 2% + jenis sanksi disajikan sebagai referensi.
 */
class SanctionExposureTest extends TestCase
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
            'permissions' => ['gap_assessment:read'],
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

    private function obligation(array $data, int $no): array
    {
        return collect($data['obligations'])->firstWhere('no', $no);
    }

    #[Test]
    public function registri_sanksi_lengkap_dengan_denda_dan_jenis(): void
    {
        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/sanctions')->assertOk()->json('data');

        $this->assertCount(36, $data['obligations']);
        $this->assertCount(4, $data['sanction_types']);
        $this->assertEqualsWithDelta(2.0, $data['fine']['max_percent'], 0.001);
        $this->assertCount(10, $data['fine']['variables']);
    }

    #[Test]
    public function tanpa_ropa_pasal_74_at_risk(): void
    {
        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/sanctions')->json('data');

        $this->assertSame('at_risk', $this->obligation($data, 74)['status'], 'Pasal 74(1) harus at_risk saat belum ada RoPA');
        $this->assertGreaterThanOrEqual(1, $data['summary']['at_risk']);
    }

    #[Test]
    public function ppdp_gap_membuat_pasal_142_at_risk(): void
    {
        // Ada pemicu wajib (Pasal 142) tapi tidak ada penunjukan aktif → gap.
        PpdpAppointment::create([
            'org_id' => $this->org->id,
            'appointee_name' => 'Mantan',
            'trigger_public_service' => true,
            'status' => 'revoked',
        ]);

        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/sanctions')->json('data');

        $this->assertSame('at_risk', $this->obligation($data, 142)['status']);
    }

    #[Test]
    public function retensi_terlampaui_membuat_pasal_80_at_risk(): void
    {
        $ropa = Ropa::create([
            'org_id' => $this->org->id,
            'processing_activity' => 'Data karyawan',
            'registration_number' => 'ROPA-2026-'.substr(uniqid(), -3),
            'status' => 'in_progress',
        ]);
        $ropa->retention_due_date = now()->subDays(10);
        $ropa->saveQuietly(); // bypass hook yang menghitung ulang retention_due_date

        Sanctum::actingAs($this->user);
        $data = $this->getJson('/api/sanctions')->json('data');

        $this->assertSame('at_risk', $this->obligation($data, 80)['status']);
        // Pasal 74 kini OK karena ada RoPA.
        $this->assertSame('ok', $this->obligation($data, 74)['status']);
    }

    #[Test]
    public function tanpa_izin_ditolak(): void
    {
        $role = TenantRole::create(['org_id' => $this->org->id, 'name' => 'X', 'permissions' => ['consent:read']]);
        $u = User::create([
            'org_id' => $this->org->id, 'name' => 'X', 'email' => 'x'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'staff', 'tenant_role_id' => $role->id,
        ]);
        Sanctum::actingAs($u);
        $this->getJson('/api/sanctions')->assertStatus(403);
    }
}
