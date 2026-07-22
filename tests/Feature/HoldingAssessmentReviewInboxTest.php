<?php

namespace Tests\Feature;

use App\Models\HoldingAssessmentInstance;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET /api/holding/assessments/review/inbox — parameter `status` opsional.
 *
 * Default (tanpa parameter) HARUS tetap seperti sebelumnya: hanya
 * submitted + review_in_progress. Dengan `?status=` reviewer bisa menelusuri
 * riwayat yang sudah difinalisasi (approved / rejected) tanpa deep-link.
 */
class HoldingAssessmentReviewInboxTest extends TestCase
{
    use RefreshDatabase;

    private Organization $holding;

    private Organization $otherHolding;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->holding = Organization::create([
            'name' => 'Holding A',
            'slug' => 'holding-a-'.Str::random(6),
            'org_level' => 'holding',
        ]);
        $this->otherHolding = Organization::create([
            'name' => 'Holding B',
            'slug' => 'holding-b-'.Str::random(6),
            'org_level' => 'holding',
        ]);

        $this->reviewer = User::factory()->create(['org_id' => $this->holding->id, 'role' => 'admin']);

        foreach ([
            ['Sub A submitted', 'submitted'],
            ['Sub A in progress', 'review_in_progress'],
            ['Sub A approved', 'approved'],
            ['Sub A rejected', 'rejected'],
            ['Sub A draft', 'draft'],
        ] as [$title, $status]) {
            $this->makeInstance($this->holding->id, $title, $status);
        }

        // Milik holding lain — tidak boleh pernah bocor.
        $this->makeInstance($this->otherHolding->id, 'Holding B approved', 'approved');
        $this->makeInstance($this->otherHolding->id, 'Holding B submitted', 'submitted');
    }

    private function makeInstance(string $orgId, string $title, string $status): HoldingAssessmentInstance
    {
        return HoldingAssessmentInstance::create([
            'org_id' => $orgId,
            'target_org_name' => 'Anak Perusahaan',
            'title' => $title,
            'regulation_code' => 'UU_PDP',
            'regulation_name' => 'UU PDP',
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }

    private function titles($response): array
    {
        return collect($response->json('data'))->pluck('title')->sort()->values()->all();
    }

    public function test_inbox_default_behavior_unchanged(): void
    {
        Sanctum::actingAs($this->reviewer);

        $res = $this->getJson('/api/holding/assessments/review/inbox');
        $res->assertOk();

        $this->assertSame(['Sub A in progress', 'Sub A submitted'], $this->titles($res));
    }

    public function test_inbox_status_approved_returns_only_approved(): void
    {
        Sanctum::actingAs($this->reviewer);

        $res = $this->getJson('/api/holding/assessments/review/inbox?status=approved');
        $res->assertOk();

        $this->assertSame(['Sub A approved'], $this->titles($res));
    }

    public function test_inbox_status_selesai_returns_finalized_only(): void
    {
        Sanctum::actingAs($this->reviewer);

        $res = $this->getJson('/api/holding/assessments/review/inbox?status=selesai');
        $res->assertOk();

        $this->assertSame(['Sub A approved', 'Sub A rejected'], $this->titles($res));
    }

    public function test_inbox_status_all_excludes_draft(): void
    {
        Sanctum::actingAs($this->reviewer);

        $res = $this->getJson('/api/holding/assessments/review/inbox?status=all');
        $res->assertOk();

        $this->assertSame(
            ['Sub A approved', 'Sub A in progress', 'Sub A rejected', 'Sub A submitted'],
            $this->titles($res)
        );
    }

    public function test_inbox_accepts_comma_separated_statuses(): void
    {
        Sanctum::actingAs($this->reviewer);

        $res = $this->getJson('/api/holding/assessments/review/inbox?status=submitted,approved');
        $res->assertOk();

        $this->assertSame(['Sub A approved', 'Sub A submitted'], $this->titles($res));
    }

    public function test_inbox_rejects_unknown_status(): void
    {
        Sanctum::actingAs($this->reviewer);

        $this->getJson('/api/holding/assessments/review/inbox?status=deleted')
            ->assertStatus(422);
    }

    public function test_inbox_status_filter_stays_org_scoped(): void
    {
        Sanctum::actingAs($this->reviewer);

        foreach (['', '?status=approved', '?status=selesai', '?status=all'] as $q) {
            $res = $this->getJson('/api/holding/assessments/review/inbox'.$q);
            $res->assertOk();
            foreach ($this->titles($res) as $title) {
                $this->assertStringNotContainsString('Holding B', $title);
            }
        }

        // Reviewer holding B hanya melihat miliknya sendiri.
        $reviewerB = User::factory()->create(['org_id' => $this->otherHolding->id, 'role' => 'admin']);
        Sanctum::actingAs($reviewerB);

        $res = $this->getJson('/api/holding/assessments/review/inbox?status=approved');
        $res->assertOk();
        $this->assertSame(['Holding B approved'], $this->titles($res));
    }

    public function test_non_holding_org_cannot_access_inbox(): void
    {
        $plainOrg = Organization::create([
            'name' => 'Tenant Biasa',
            'slug' => 'tenant-'.Str::random(6),
            'org_level' => 'single',
        ]);
        $user = User::factory()->create(['org_id' => $plainOrg->id, 'role' => 'admin']);
        Sanctum::actingAs($user);

        $this->getJson('/api/holding/assessments/review/inbox?status=approved')->assertStatus(403);
    }
}
