<?php

namespace Tests\Feature;

use App\Models\DsrRequest;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Keberatan atas keputusan otomatis (PP 33/2026 Pasal 93-95).
 *
 * Menegakkan: penerimaan → campur tangan manusia (94(3)); penolakan hanya sah
 * bila kedua syarat Pasal 95(1) terpenuhi; tinjauan hanya untuk tipe ADM.
 */
class DsrAutomatedDecisionTest extends TestCase
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
            'permissions' => ['dsr:read', 'dsr:write'],
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

    private function dsr(string $type = DsrRequest::TYPE_AUTOMATED_DECISION): DsrRequest
    {
        return DsrRequest::create([
            'org_id' => $this->org->id,
            'request_id' => 'DSR-2026-'.substr(uniqid(), -4),
            'request_type' => $type,
            'requester_name' => 'Subjek Uji',
            'requester_email' => 'subjek@example.com',
            'description' => 'Keberatan atas skor kredit otomatis',
            'status' => 'verified',
        ]);
    }

    #[Test]
    public function penerimaan_mencatat_campur_tangan_manusia(): void
    {
        Sanctum::actingAs($this->user);
        $dsr = $this->dsr();

        $this->postJson("/api/dsr/{$dsr->id}/automated-decision-review", [
            'outcome' => 'human_intervention',
            'intervention_notes' => 'Ditinjau ulang oleh analis kredit manusia.',
            'alternative_processing' => 'Keputusan diambil ulang tanpa skor otomatis.',
        ])->assertOk();

        $fresh = $dsr->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('human_intervention', $fresh->subject_data['automated_decision_review']['outcome']);
        $this->assertSame($this->user->id, $fresh->subject_data['automated_decision_review']['reviewer_id']);
    }

    #[Test]
    public function penolakan_tanpa_kedua_syarat_pasal_95_ditolak(): void
    {
        Sanctum::actingAs($this->user);
        $dsr = $this->dsr();

        // Hanya satu syarat → tidak sah.
        $this->postJson("/api/dsr/{$dsr->id}/automated-decision-review", [
            'outcome' => 'rejected',
            'reason' => 'Sistem kami akurat.',
            'no_legal_or_significant_effect' => true,
            'accurate_system_and_mitigation' => false,
        ])->assertStatus(422);

        $this->assertSame('verified', $dsr->fresh()->status, 'Status tidak boleh berubah saat penolakan tak sah');
    }

    #[Test]
    public function penolakan_dengan_kedua_syarat_sah(): void
    {
        Sanctum::actingAs($this->user);
        $dsr = $this->dsr();

        $this->postJson("/api/dsr/{$dsr->id}/automated-decision-review", [
            'outcome' => 'rejected',
            'reason' => 'Tidak ada dampak signifikan & sistem tervalidasi + mitigasi tersedia.',
            'no_legal_or_significant_effect' => true,
            'accurate_system_and_mitigation' => true,
        ])->assertOk();

        $fresh = $dsr->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertNotEmpty($fresh->rejection_reason);
    }

    #[Test]
    public function tinjauan_hanya_untuk_tipe_keberatan_otomatis(): void
    {
        Sanctum::actingAs($this->user);
        $dsr = $this->dsr('access');

        $this->postJson("/api/dsr/{$dsr->id}/automated-decision-review", [
            'outcome' => 'human_intervention',
            'intervention_notes' => 'x',
        ])->assertStatus(422);
    }

    #[Test]
    public function tanpa_izin_write_ditolak(): void
    {
        $role = TenantRole::create(['org_id' => $this->org->id, 'name' => 'V', 'permissions' => ['dsr:read']]);
        $viewer = User::create([
            'org_id' => $this->org->id, 'name' => 'V', 'email' => 'v'.uniqid().'@uji.id',
            'password' => bcrypt('secret123'), 'role' => 'viewer', 'tenant_role_id' => $role->id,
        ]);
        $dsr = $this->dsr();

        Sanctum::actingAs($viewer);
        $this->postJson("/api/dsr/{$dsr->id}/automated-decision-review", [
            'outcome' => 'human_intervention', 'intervention_notes' => 'x',
        ])->assertStatus(403);
    }
}
