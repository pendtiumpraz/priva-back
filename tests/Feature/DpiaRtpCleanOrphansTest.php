<?php

namespace Tests\Feature;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /api/dpia/{id}/rtp/clean-orphans tidak boleh membuang pekerjaan user.
 *
 * Aturan: orphan (risk event-nya tidak lagi 'mitigate' di wizard) yang masih
 * polos → dihapus. Orphan yang punya tanda kerja manual (treatment_type diubah,
 * notes, bukti, owner manual, due_date, status maju, residual, timestamp
 * milestone) → DILEWATI dan dilaporkan di response 'skipped'.
 */
class DpiaRtpCleanOrphansTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::create(['name' => 'Tenant A', 'slug' => 'tenant-a-'.Str::random(6)]);
        $this->orgB = Organization::create(['name' => 'Tenant B', 'slug' => 'tenant-b-'.Str::random(6)]);

        $this->adminA = User::factory()->create(['org_id' => $this->orgA->id, 'role' => 'admin']);
        $this->adminB = User::factory()->create(['org_id' => $this->orgB->id, 'role' => 'admin']);
    }

    /**
     * Wizard hanya menyisakan satu risk event 'mitigate' — dua lainnya sudah
     * bukan mitigate sehingga jadi orphan di RTP.
     */
    private function makeDpia(string $orgId, array $tracking): Dpia
    {
        return Dpia::withoutGlobalScope('org')->create([
            'org_id' => $orgId,
            'registration_number' => 'DPIA-2026-'.Str::random(4),
            'risk_level' => 'high',
            'status' => 'draft',
            'description' => 'Test DPIA',
            'wizard_data' => [
                'potensi_risiko' => [
                    'Retensi' => [
                        'risk_events' => [
                            ['risk_event' => 'Data disimpan melebihi masa retensi', 'penanganan' => 'mitigate', 'dampak' => 4, 'probabilitas' => 3],
                            ['risk_event' => 'Risiko yang diterima apa adanya', 'penanganan' => 'accept', 'dampak' => 2, 'probabilitas' => 2],
                            ['risk_event' => 'Risiko dialihkan ke asuransi', 'penanganan' => 'transfer', 'dampak' => 2, 'probabilitas' => 2],
                        ],
                    ],
                ],
            ],
            'mitigation_tracking' => $tracking,
        ]);
    }

    private function baseItem(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'risk_event' => 'Risk event',
            'category' => 'Retensi',
            'treatment_type' => 'reduce',
            'action' => 'Tindakan',
            'rationale' => null,
            'owner_user_id' => null,
            'priority' => 'medium',
            'due_date' => null,
            'status' => 'planned',
            'inherent_likelihood' => 2,
            'inherent_impact' => 2,
            'residual_likelihood' => null,
            'residual_impact' => null,
            'evidence_files' => [],
            'notes' => '',
            'started_at' => null,
            'completed_at' => null,
            'verified_at' => null,
            'verified_by' => null,
        ], $overrides);
    }

    public function test_orphan_polos_dihapus_dan_item_valid_tetap(): void
    {
        $plain = $this->baseItem(['risk_event' => 'Risiko yang diterima apa adanya']);
        $valid = $this->baseItem(['risk_event' => 'Data disimpan melebihi masa retensi']);

        $dpia = $this->makeDpia($this->orgA->id, [$valid, $plain]);

        Sanctum::actingAs($this->adminA);
        $res = $this->postJson("/api/dpia/{$dpia->id}/rtp/clean-orphans");

        $res->assertStatus(200)
            ->assertJsonPath('removed_count', 1)
            ->assertJsonPath('skipped_count', 0);

        $dpia->refresh();
        $ids = array_column($dpia->mitigation_tracking, 'id');
        $this->assertSame([$valid['id']], $ids, 'Hanya item valid yang tersisa.');
    }

    #[DataProvider('manualWorkProvider')]
    public function test_orphan_dengan_tanda_kerja_manual_dilewati(array $overrides, string $expectedReasonFragment): void
    {
        $worked = $this->baseItem(array_merge(['risk_event' => 'Risiko dialihkan ke asuransi'], $overrides));
        $plain = $this->baseItem(['risk_event' => 'Risiko yang diterima apa adanya']);

        $dpia = $this->makeDpia($this->orgA->id, [$worked, $plain]);

        Sanctum::actingAs($this->adminA);
        $res = $this->postJson("/api/dpia/{$dpia->id}/rtp/clean-orphans");

        $res->assertStatus(200)
            ->assertJsonPath('removed_count', 1)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped.0.id', $worked['id']);

        $reasons = implode(' | ', $res->json('skipped.0.reasons'));
        $this->assertStringContainsString($expectedReasonFragment, $reasons);

        $dpia->refresh();
        $ids = array_column($dpia->mitigation_tracking, 'id');
        $this->assertContains($worked['id'], $ids, 'Item bertanda kerja manual TIDAK boleh dihapus.');
        $this->assertNotContains($plain['id'], $ids, 'Orphan polos tetap dihapus.');
    }

    public static function manualWorkProvider(): array
    {
        return [
            'treatment_type diubah' => [['treatment_type' => 'transfer'], 'treatment_type'],
            'ada catatan' => [['notes' => 'Sudah dibahas dengan legal'], 'catatan'],
            'ada bukti' => [['evidence_files' => [['id' => 'e1', 'path' => 'x.pdf']]], 'bukti'],
            'owner manual' => [['owner_user_id' => (string) Str::uuid()], 'penanggung jawab'],
            'due date diisi' => [['due_date' => '2026-12-31'], 'tenggat'],
            'status maju' => [['status' => 'in_progress', 'started_at' => null], 'status'],
            'residual dinilai' => [['residual_likelihood' => 1, 'residual_impact' => 1], 'residual'],
            'sudah diverifikasi' => [['verified_at' => '2026-01-01T00:00:00+00:00'], 'diverifikasi'],
        ];
    }

    public function test_status_overdue_bukan_tanda_kerja_manual(): void
    {
        // 'overdue' di-set otomatis oleh sistem (recalcOverdue), bukan user —
        // jadi item overdue tanpa jejak lain tetap boleh dibersihkan.
        $overdue = $this->baseItem(['risk_event' => 'Risiko yang diterima apa adanya', 'status' => 'overdue']);
        $dpia = $this->makeDpia($this->orgA->id, [$overdue]);

        Sanctum::actingAs($this->adminA);
        $res = $this->postJson("/api/dpia/{$dpia->id}/rtp/clean-orphans");

        $res->assertStatus(200)
            ->assertJsonPath('removed_count', 1)
            ->assertJsonPath('skipped_count', 0);
    }

    public function test_tidak_bisa_clean_orphans_dpia_org_lain(): void
    {
        $item = $this->baseItem(['risk_event' => 'Risiko yang diterima apa adanya']);
        $dpia = $this->makeDpia($this->orgA->id, [$item]);

        Sanctum::actingAs($this->adminB);
        $this->postJson("/api/dpia/{$dpia->id}/rtp/clean-orphans")->assertStatus(404);

        $dpia->refresh();
        $this->assertCount(1, $dpia->mitigation_tracking, 'Data tenant lain tidak boleh tersentuh.');
    }
}
