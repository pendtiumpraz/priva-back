<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Badge;
use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Lms\Models\UserBadge;
use App\Lms\Models\UserModuleProgress;
use App\Lms\Models\XpLog;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests for Task 5.4-BE — GET /api/lms/admin/users.
 *
 * Spec §3.10: read-only viewer with org scoping, search, role filter,
 * pagination, and per-row aggregations (enrolled_courses, total_xp,
 * badges_count, last_activity_at). Permission gate: lms.user_admin.
 */
class UserAdminListTest extends LmsAdminTestCase
{
    /**
     * Root user — no tenant_role required, role='root' bypasses CheckPermission.
     */
    private function actingAsRoot(?Organization $org = null): User
    {
        config(['lms.enabled' => true]);

        $org = $org ?: Organization::factory()->create();

        $user = User::factory()->create([
            'org_id' => $org->id,
            'role'   => 'root',
        ]);

        $this->ensureLmsEntitled($org);

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Build a user inside an org without authenticating as them. Used to
     * populate the table the admin will list.
     */
    private function makeMember(Organization $org, string $name, string $email, string $role = 'user'): User
    {
        return User::factory()->create([
            'org_id' => $org->id,
            'name'   => $name,
            'email'  => $email,
            'role'   => $role,
        ]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/lms/admin/users')->assertStatus(401);
    }

    public function test_user_admin_lists_only_own_org_members(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $this->makeMember($orgA, 'Alice One',  'alice@a.com');
        $this->makeMember($orgA, 'Bob Two',    'bob@a.com');
        $this->makeMember($orgB, 'Carol Three', 'carol@b.com');

        $admin = $this->actingAsUserAdmin($orgA);

        $r = $this->getJson('/api/lms/admin/users');
        $r->assertOk();

        $emails = collect($r->json('data'))->pluck('email')->all();
        // Admin themselves is in orgA so they appear too — expected.
        $this->assertContains('alice@a.com', $emails);
        $this->assertContains('bob@a.com', $emails);
        $this->assertNotContains('carol@b.com', $emails);

        // Tenant rows must NOT expose org_id / org_name (root-only fields).
        $first = $r->json('data.0');
        $this->assertArrayNotHasKey('org_id', $first);
        $this->assertArrayNotHasKey('org_name', $first);

        $r->assertJsonStructure([
            'data' => [['id', 'name', 'email', 'role', 'enrolled_courses', 'total_xp', 'badges_count', 'last_activity_at']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);
    }

    public function test_search_matches_email_substring(): void
    {
        $org = Organization::factory()->create();
        $this->makeMember($org, 'Alice', 'alice@example.com');
        $this->makeMember($org, 'Bob',   'bob@example.com');

        $this->actingAsUserAdmin($org);

        $r = $this->getJson('/api/lms/admin/users?search=alice');
        $r->assertOk();

        $emails = collect($r->json('data'))->pluck('email')->all();
        $this->assertContains('alice@example.com', $emails);
        $this->assertNotContains('bob@example.com', $emails);
    }

    public function test_search_is_case_insensitive_on_email(): void
    {
        $org = Organization::factory()->create();
        $this->makeMember($org, 'Dana', 'dana@x.com');

        $this->actingAsUserAdmin($org);

        // Default sqlite LIKE is case-insensitive for ASCII; mirrors prod behaviour.
        $r = $this->getJson('/api/lms/admin/users?search=DANA');
        $r->assertOk();

        $emails = collect($r->json('data'))->pluck('email')->all();
        $this->assertContains('dana@x.com', $emails);
    }

    public function test_role_filter_narrows_to_exact_match(): void
    {
        $org = Organization::factory()->create();
        $this->makeMember($org, 'U1', 'u1@a.com', 'user');
        $this->makeMember($org, 'U2', 'u2@a.com', 'user');
        $this->makeMember($org, 'D1', 'd1@a.com', 'dpo');

        $this->actingAsUserAdmin($org);

        $r = $this->getJson('/api/lms/admin/users?role=user');
        $r->assertOk();

        $roles = collect($r->json('data'))->pluck('role')->unique()->values()->all();
        $this->assertEquals(['user'], $roles);

        // The acting admin (role='admin') should not appear in this slice.
        $emails = collect($r->json('data'))->pluck('email')->all();
        $this->assertContains('u1@a.com', $emails);
        $this->assertContains('u2@a.com', $emails);
        $this->assertNotContains('d1@a.com', $emails);
    }

    public function test_pagination_respects_20_per_page(): void
    {
        $org = Organization::factory()->create();
        // Create 25 members; the acting admin is created last by actingAsUserAdmin.
        for ($i = 1; $i <= 25; $i++) {
            $this->makeMember($org, "Member {$i}", "m{$i}@a.com");
        }

        $this->actingAsUserAdmin($org);

        $r1 = $this->getJson('/api/lms/admin/users?page=1');
        $r1->assertOk();
        $this->assertCount(20, $r1->json('data'));
        $this->assertSame(1, $r1->json('meta.current_page'));
        $this->assertSame(2, $r1->json('meta.last_page'));
        // 25 members + 1 admin = 26 total.
        $this->assertSame(26, $r1->json('meta.total'));

        $r2 = $this->getJson('/api/lms/admin/users?page=2');
        $r2->assertOk();
        $this->assertCount(6, $r2->json('data'));
        $this->assertSame(2, $r2->json('meta.current_page'));
    }

    public function test_aggregations_are_computed_per_user(): void
    {
        $org = Organization::factory()->create();

        $alice = $this->makeMember($org, 'Alice', 'alice@agg.com');
        $bob   = $this->makeMember($org, 'Bob',   'bob@agg.com');

        // Build 2 courses and 3 modules so we can verify distinct course_id.
        $courseA = Course::create([
            'org_id' => $org->id, 'slug' => 'ca', 'title' => 'CA', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $courseB = Course::create([
            'org_id' => $org->id, 'slug' => 'cb', 'title' => 'CB', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'published' => true, 'order' => 2, 'created_by' => null,
        ]);

        $modA1 = Module::create(['course_id' => $courseA->id, 'slug' => 'a1', 'title' => 'A1', 'order' => 1, 'published' => true]);
        $modA2 = Module::create(['course_id' => $courseA->id, 'slug' => 'a2', 'title' => 'A2', 'order' => 2, 'published' => true]);
        $modB1 = Module::create(['course_id' => $courseB->id, 'slug' => 'b1', 'title' => 'B1', 'order' => 1, 'published' => true]);

        // Alice: progress on both CA modules (1 distinct course) AND CB module (=> 2).
        UserModuleProgress::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'module_id' => $modA1->id, 'status' => 'completed',
        ]);
        UserModuleProgress::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'module_id' => $modA2->id, 'status' => 'in_progress',
        ]);
        UserModuleProgress::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'module_id' => $modB1->id, 'status' => 'in_progress',
        ]);

        // Bob: only one module on CA => 1 distinct course.
        UserModuleProgress::create([
            'user_id' => $bob->id, 'org_id' => $org->id, 'module_id' => $modA1->id, 'status' => 'in_progress',
        ]);

        // Badges. Two distinct badges for Alice; none for Bob.
        $badge1 = Badge::create([
            'org_id' => $org->id, 'slug' => 'b-one', 'name' => 'B1', 'description' => 'd',
            'icon' => 'Award', 'criteria_type' => 'custom', 'criteria_json' => ['theme' => 'blue', 'params' => []],
        ]);
        $badge2 = Badge::create([
            'org_id' => $org->id, 'slug' => 'b-two', 'name' => 'B2', 'description' => 'd',
            'icon' => 'Award', 'criteria_type' => 'custom', 'criteria_json' => ['theme' => 'gold', 'params' => []],
        ]);
        UserBadge::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'badge_id' => $badge1->id, 'awarded_at' => now(),
        ]);
        UserBadge::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'badge_id' => $badge2->id, 'awarded_at' => now(),
        ]);

        // XP logs for Alice (3 events => sum=170, last_activity = newest).
        XpLog::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'action' => 'lesson_complete',
            'xp_amount' => 50, 'created_at' => now()->subDays(2),
        ]);
        XpLog::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'action' => 'quiz_pass',
            'xp_amount' => 80, 'created_at' => now()->subDay(),
        ]);
        $latest = now()->subHour();
        XpLog::create([
            'user_id' => $alice->id, 'org_id' => $org->id, 'action' => 'badge_earn',
            'xp_amount' => 40, 'created_at' => $latest,
        ]);

        $this->actingAsUserAdmin($org);

        $r = $this->getJson('/api/lms/admin/users?search=alice');
        $r->assertOk();

        $row = collect($r->json('data'))->firstWhere('email', 'alice@agg.com');
        $this->assertNotNull($row, 'Alice row should be present');
        $this->assertSame(2,   $row['enrolled_courses'], 'distinct course count');
        $this->assertSame(170, $row['total_xp'],         'sum of xp_amount');
        $this->assertSame(2,   $row['badges_count'],     'distinct badge count');
        $this->assertNotNull($row['last_activity_at']);

        // Bob has 1 enrolled course, 0 xp/badges, null activity.
        $r2 = $this->getJson('/api/lms/admin/users?search=bob');
        $bobRow = collect($r2->json('data'))->firstWhere('email', 'bob@agg.com');
        $this->assertSame(1, $bobRow['enrolled_courses']);
        $this->assertSame(0, $bobRow['total_xp']);
        $this->assertSame(0, $bobRow['badges_count']);
        $this->assertNull($bobRow['last_activity_at']);
    }

    public function test_root_sees_users_across_all_orgs_with_org_metadata(): void
    {
        $orgA = Organization::factory()->create(['name' => 'Org Alpha']);
        $orgB = Organization::factory()->create(['name' => 'Org Beta']);

        $this->makeMember($orgA, 'A One', 'a1@alpha.com');
        $this->makeMember($orgB, 'B One', 'b1@beta.com');

        $this->actingAsRoot($orgA);

        $r = $this->getJson('/api/lms/admin/users');
        $r->assertOk();

        $emails = collect($r->json('data'))->pluck('email')->all();
        $this->assertContains('a1@alpha.com', $emails);
        $this->assertContains('b1@beta.com',  $emails);

        // Every row carries org_id + org_name for root.
        foreach ($r->json('data') as $row) {
            $this->assertArrayHasKey('org_id', $row);
            $this->assertArrayHasKey('org_name', $row);
            $this->assertNotNull($row['org_id']);
        }

        $alphaRow = collect($r->json('data'))->firstWhere('email', 'a1@alpha.com');
        $this->assertSame('Org Alpha', $alphaRow['org_name']);
    }

    public function test_content_admin_without_user_admin_is_forbidden(): void
    {
        // Build a tenant_admin who has lms.content_admin but NOT lms.user_admin.
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $role = TenantRole::create([
            'org_id'      => $org->id,
            'name'        => 'content_only',
            'is_system'   => true,
            'description' => 'content only',
            'permissions' => ['lms.content_admin', 'lms.learner'],
        ]);
        $user = User::factory()->create([
            'org_id'         => $org->id,
            'role'           => 'admin',
            'tenant_role_id' => $role->id,
        ]);
        $this->ensureLmsEntitled($org);
        Sanctum::actingAs($user);

        $this->getJson('/api/lms/admin/users')->assertStatus(403);

        // Sanity: the same caller can still hit the content-admin index.
        $this->getJson('/api/lms/admin/courses')->assertStatus(200);
    }

    public function test_learner_role_blocked_from_user_admin_endpoint(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        $this->getJson('/api/lms/admin/users')->assertStatus(403);
    }
}
