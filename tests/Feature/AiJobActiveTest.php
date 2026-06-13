<?php

namespace Tests\Feature;

use App\Models\AiJob;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AI Jobs footer endpoints under a platform-role (null org_id) account.
 *
 * The footer polls GET /api/ai/jobs/active on every authed page. Platform
 * roles (root/superadmin) have org_id = NULL by design, and AiJob::scopeForOrg
 * is typed string (non-nullable), so passing the null org threw a TypeError →
 * 500 on every page. store() already refuses job creation without an org, so a
 * platform role can never own AI jobs; the correct read result is "empty".
 */
class AiJobActiveTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(string $orgId, string $userId, string $status = AiJob::STATUS_PENDING): AiJob
    {
        return AiJob::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'type' => 'analyzer',
            'module' => 'ropa',
            'label' => 'Analyze record',
            'status' => $status,
            'progress' => 0,
            'payload' => [],
        ]);
    }

    /** Core bug: platform role (org_id null) polling active jobs must not 500. */
    public function test_active_returns_empty_array_for_platform_role(): void
    {
        $superadmin = User::factory()->create(['org_id' => null, 'role' => 'superadmin']);
        Sanctum::actingAs($superadmin);

        $r = $this->getJson('/api/ai/jobs/active');

        $r->assertOk();
        $this->assertSame([], $r->json());
    }

    /** Happy path stays intact: an org user still sees only their active jobs. */
    public function test_active_returns_org_users_active_jobs(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        Sanctum::actingAs($user);

        $active = $this->makeJob($org->id, $user->id, AiJob::STATUS_PENDING);
        $this->makeJob($org->id, $user->id, AiJob::STATUS_DONE); // terminal — excluded

        $r = $this->getJson('/api/ai/jobs/active');

        $r->assertOk();
        $r->assertJsonCount(1);
        $r->assertJsonPath('0.id', $active->id);
    }

    /** History tab must not 500 for a platform role; returns the paginator shape. */
    public function test_history_returns_200_for_platform_role(): void
    {
        $superadmin = User::factory()->create(['org_id' => null, 'role' => 'superadmin']);
        Sanctum::actingAs($superadmin);

        $r = $this->getJson('/api/ai/jobs/history');

        $r->assertOk();
        $r->assertJsonPath('total', 0);
        $r->assertJsonPath('data', []);
    }

    /** Single-job lookup must not 500 for a platform role; 404 (no leak). */
    public function test_show_returns_404_for_platform_role(): void
    {
        $superadmin = User::factory()->create(['org_id' => null, 'role' => 'superadmin']);
        Sanctum::actingAs($superadmin);

        $r = $this->getJson('/api/ai/jobs/00000000-0000-0000-0000-000000000000');

        $r->assertNotFound();
    }
}
