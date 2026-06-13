<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Badge;
use App\Lms\Models\UserBadge;
use App\Models\Organization;
use App\Models\User;

/**
 * GET /admin/badges/{id}/awards — badge recipients, explicitly org-scoped
 * (LMS routes have no tenant.context, so BelongsToOrg auto-scope is a no-op).
 */
class BadgeAwardsTest extends LmsAdminTestCase
{
    private function makeGlobalBadge(): Badge
    {
        return Badge::create([
            'org_id' => null, 'slug' => 'completionist', 'name' => 'Completionist',
            'description' => 'd', 'icon' => 'trophy', 'criteria_type' => 'manual', 'criteria_json' => [],
        ]);
    }

    public function test_awards_are_scoped_to_admin_org(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        $badge = $this->makeGlobalBadge();

        $u1 = User::factory()->create(['org_id' => $org->id]);
        $u2 = User::factory()->create(['org_id' => $org->id]);
        UserBadge::create(['user_id' => $u1->id, 'org_id' => $org->id, 'badge_id' => $badge->id, 'awarded_at' => now()]);
        UserBadge::create(['user_id' => $u2->id, 'org_id' => $org->id, 'badge_id' => $badge->id, 'awarded_at' => now()->subDay()]);

        // recipient in ANOTHER org — must not leak.
        $other = Organization::factory()->create();
        $u3 = User::factory()->create(['org_id' => $other->id]);
        UserBadge::create(['user_id' => $u3->id, 'org_id' => $other->id, 'badge_id' => $badge->id, 'awarded_at' => now()]);

        $r = $this->getJson("/api/lms/admin/badges/{$badge->id}/awards")->assertOk();

        $r->assertJsonCount(2, 'data');
        $r->assertJsonStructure(['data' => [['id', 'user_id', 'user_name', 'user_email', 'awarded_at']]]);
        $userIds = collect($r->json('data'))->pluck('user_id')->all();
        $this->assertContains($u1->id, $userIds);
        $this->assertNotContains($u3->id, $userIds);
    }

    public function test_awards_empty_when_no_recipients(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        $badge = $this->makeGlobalBadge();

        $this->getJson("/api/lms/admin/badges/{$badge->id}/awards")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_awards_404_for_unknown_badge(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $this->getJson('/api/lms/admin/badges/999999/awards')->assertStatus(404);
    }

    public function test_root_sees_all_orgs_recipients(): void
    {
        $org = Organization::factory()->create();
        $badge = $this->makeGlobalBadge();
        $other = Organization::factory()->create();
        $u1 = User::factory()->create(['org_id' => $org->id]);
        $u2 = User::factory()->create(['org_id' => $other->id]);
        UserBadge::create(['user_id' => $u1->id, 'org_id' => $org->id, 'badge_id' => $badge->id, 'awarded_at' => now()]);
        UserBadge::create(['user_id' => $u2->id, 'org_id' => $other->id, 'badge_id' => $badge->id, 'awarded_at' => now()]);

        $root = User::factory()->create(['org_id' => null, 'role' => 'superadmin']);
        \Laravel\Sanctum\Sanctum::actingAs($root);
        config(['lms.enabled' => true]);

        $this->getJson("/api/lms/admin/badges/{$badge->id}/awards")
            ->assertOk()->assertJsonCount(2, 'data');
    }
}
