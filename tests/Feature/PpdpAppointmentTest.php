<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PpdpAppointment;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Registri penunjukan PPDP (PP 33/2026 Pasal 142-143).
 *
 * Yang paling menentukan dan karena itu diuji: evaluasi kewajiban Pasal 142
 * (wajib bila salah satu pemicu terpenuhi), deteksi gap (wajib tapi tidak ada
 * penunjukan aktif), isolasi antar tenant, dan gate izin.
 */
class PpdpAppointmentTest extends TestCase
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
            'permissions' => ['ppdp:read', 'ppdp:write'],
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

    #[Test]
    public function pemicu_pasal_142_membuat_penunjukan_wajib_dan_menutup_gap(): void
    {
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/ppdp', [
            'appointee_name' => 'Budi Santoso',
            'appointee_email' => 'budi@bank.id',
            'trigger_large_scale_monitoring' => true,
            'competency_professional' => true,
            'competency_legal_knowledge' => true,
            'competency_pdp_practice' => true,
            'status' => 'active',
        ])->assertStatus(201)->json('data');

        $this->assertTrue($created['is_mandatory']);
        $this->assertTrue($created['competency_complete']);
        $this->assertNotEmpty($created['mandatory_reasons']);

        $ob = $this->getJson('/api/ppdp')->assertOk()->json('meta.obligation');
        $this->assertTrue($ob['mandatory']);
        $this->assertTrue($ob['has_active_ppdp']);
        $this->assertFalse($ob['gap']);
        $this->assertTrue($ob['competency_complete']);
    }

    #[Test]
    public function wajib_tapi_tidak_ada_penunjukan_aktif_terdeteksi_sebagai_gap(): void
    {
        Sanctum::actingAs($this->user);

        // Ada pemicu (wajib) tapi status bukan aktif → gap.
        $this->postJson('/api/ppdp', [
            'appointee_name' => 'Mantan PPDP',
            'trigger_public_service' => true,
            'status' => 'revoked',
        ])->assertStatus(201);

        $ob = $this->getJson('/api/ppdp')->json('meta.obligation');
        $this->assertTrue($ob['mandatory']);
        $this->assertFalse($ob['has_active_ppdp']);
        $this->assertTrue($ob['gap']);
    }

    #[Test]
    public function tanpa_pemicu_penunjukan_bersifat_opsional(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/ppdp', [
            'appointee_name' => 'PPDP Sukarela',
            'status' => 'active',
        ])->assertStatus(201);

        $ob = $this->getJson('/api/ppdp')->json('meta.obligation');
        $this->assertFalse($ob['mandatory']);
        $this->assertFalse($ob['gap']);
        $this->assertTrue($ob['has_active_ppdp']);
    }

    #[Test]
    public function penunjukan_satu_tenant_tidak_terlihat_tenant_lain(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/ppdp', ['appointee_name' => 'Rahasia Bank Uji', 'status' => 'active'])->assertStatus(201);

        $otherOrg = Organization::create(['name' => 'Bank Lain', 'slug' => 'lain-'.uniqid()]);
        $otherRole = TenantRole::create([
            'org_id' => $otherOrg->id,
            'name' => 'DPO',
            'permissions' => ['ppdp:read', 'ppdp:write'],
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
        $names = array_column($this->getJson('/api/ppdp')->json('data'), 'appointee_name');
        $this->assertNotContains('Rahasia Bank Uji', $names);
    }

    #[Test]
    public function tanpa_izin_ppdp_ditolak(): void
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
        $this->getJson('/api/ppdp')->assertStatus(403);
        $this->postJson('/api/ppdp', ['appointee_name' => 'X'])->assertStatus(403);
        $this->assertSame(0, PpdpAppointment::withoutGlobalScope('org')->where('appointee_name', 'X')->count());
    }

    #[Test]
    public function update_dan_hapus_penunjukan(): void
    {
        Sanctum::actingAs($this->user);

        $id = $this->postJson('/api/ppdp', ['appointee_name' => 'Awal', 'status' => 'active'])
            ->assertStatus(201)->json('data.id');

        $this->putJson("/api/ppdp/{$id}", ['appointee_position' => 'Kepala Kepatuhan'])
            ->assertOk()->assertJsonPath('data.appointee_position', 'Kepala Kepatuhan');

        $this->deleteJson("/api/ppdp/{$id}")->assertOk();
        $this->assertSame(0, PpdpAppointment::where('org_id', $this->org->id)->count());
    }
}
