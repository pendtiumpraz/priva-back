<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Badge;
use App\Lms\Models\UserBadge;
use App\Lms\Models\UserModuleProgress;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Feature tests for Task 4-BE — GET /api/lms/admin/stats.
 *
 * Spec: root/superadmin only; range enum 7d|30d|90d|all (default 30d);
 * envelope { data: { range, as_of, totals, deltas, rates, top_courses,
 * recent_activity } }.
 */
class LmsAdminStatsTest extends LmsAdminTestCase
{
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

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/lms/admin/stats')->assertStatus(401);
    }

    public function test_non_root_returns_403(): void
    {
        $this->actingAsContentAdmin(); // tenant_admin with lms.content_admin

        $this->getJson('/api/lms/admin/stats')->assertStatus(403);
    }

    public function test_invalid_range_returns_422(): void
    {
        $this->actingAsRoot();

        $this->getJson('/api/lms/admin/stats?range=bogus')->assertStatus(422);
    }

    public function test_empty_db_returns_zeroed_envelope_without_500(): void
    {
        $this->actingAsRoot();

        $r = $this->getJson('/api/lms/admin/stats?range=30d');
        $r->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'range',
                    'as_of',
                    'totals' => ['courses', 'active_learners', 'quizzes', 'badges_awarded'],
                    'deltas' => ['courses', 'active_learners', 'quizzes', 'badges_awarded'],
                    'rates'  => ['enrollment_rate', 'completion_rate'],
                    'top_courses',
                    'recent_activity',
                ],
            ]);

        $this->assertSame('30d', $r->json('data.range'));
        $this->assertSame(0, $r->json('data.totals.courses'));
        $this->assertSame(0, $r->json('data.totals.active_learners'));
        $this->assertSame(0, $r->json('data.totals.quizzes'));
        $this->assertSame(0, $r->json('data.totals.badges_awarded'));
        // JSON serialisation collapses 0.0 → 0; compare numerically.
        $this->assertEquals(0, $r->json('data.rates.completion_rate'));
        $this->assertEquals(0, $r->json('data.rates.enrollment_rate'));
        $this->assertSame([], $r->json('data.top_courses'));
        $this->assertSame([], $r->json('data.recent_activity'));
    }

    public function test_default_range_is_30d(): void
    {
        $this->actingAsRoot();

        $r = $this->getJson('/api/lms/admin/stats');
        $r->assertOk();
        $this->assertSame('30d', $r->json('data.range'));
    }

    public function test_all_range_zeroes_deltas(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsRoot($org);

        $course = $this->makeCourse($org, 'c1', true);

        $r = $this->getJson('/api/lms/admin/stats?range=all');
        $r->assertOk();
        $this->assertSame('all', $r->json('data.range'));
        $this->assertSame(1, $r->json('data.totals.courses'));
        $this->assertSame(0, $r->json('data.deltas.courses'));
    }

    public function test_totals_and_top_courses_reflect_seeded_data(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsRoot($org);

        $courseA = $this->makeCourse($org, 'alpha', true);
        $courseB = $this->makeCourse($org, 'beta',  false);
        $modA1   = $this->makeModule($courseA, 'a-m1');
        $modA2   = $this->makeModule($courseA, 'a-m2');
        $modB1   = $this->makeModule($courseB, 'b-m1');

        $learner1 = User::factory()->create(['org_id' => $org->id, 'role' => 'user']);
        $learner2 = User::factory()->create(['org_id' => $org->id, 'role' => 'user']);

        // courseA: 2 learners, 1 of 3 progress rows completed.
        UserModuleProgress::create([
            'user_id'      => $learner1->id,
            'org_id'       => $org->id,
            'module_id'    => $modA1->id,
            'status'       => 'completed',
            'completed_at' => Carbon::now(),
        ]);
        UserModuleProgress::create([
            'user_id'   => $learner1->id,
            'org_id'    => $org->id,
            'module_id' => $modA2->id,
            'status'    => 'in_progress',
        ]);
        UserModuleProgress::create([
            'user_id'   => $learner2->id,
            'org_id'    => $org->id,
            'module_id' => $modA1->id,
            'status'    => 'in_progress',
        ]);

        // courseB: 1 learner.
        UserModuleProgress::create([
            'user_id'   => $learner2->id,
            'org_id'    => $org->id,
            'module_id' => $modB1->id,
            'status'    => 'in_progress',
        ]);

        // 1 quiz, 1 badge award.
        DB::table('lms_quizzes')->insert([
            'owner_type'    => 'module',
            'owner_key'     => (string) $modA1->id,
            'passing_score' => 70,
            'created_at'    => Carbon::now(),
            'updated_at'    => Carbon::now(),
        ]);
        $badge = Badge::create([
            'slug'          => 'first-step',
            'name'          => 'First Step',
            'criteria_type' => 'completion',
        ]);
        UserBadge::create([
            'user_id'    => $learner1->id,
            'org_id'     => $org->id,
            'badge_id'   => $badge->id,
            'awarded_at' => Carbon::now(),
        ]);

        $r = $this->getJson('/api/lms/admin/stats?range=30d');
        $r->assertOk();

        $this->assertSame(2,   $r->json('data.totals.courses'));
        $this->assertSame(2,   $r->json('data.totals.active_learners'));
        $this->assertSame(1,   $r->json('data.totals.quizzes'));
        $this->assertSame(1,   $r->json('data.totals.badges_awarded'));

        // top_courses: courseA first (2 learners), then courseB (1).
        $top = $r->json('data.top_courses');
        $this->assertCount(2, $top);
        $this->assertSame($courseA->id, $top[0]['id']);
        $this->assertSame(2, $top[0]['enrolled_count']);
        $this->assertSame('published', $top[0]['status']);
        $this->assertSame($courseB->id, $top[1]['id']);
        $this->assertSame(1, $top[1]['enrolled_count']);
        $this->assertSame('draft', $top[1]['status']);

        // rates.completion_rate = 1 completed / 4 total progress rows.
        $this->assertSame(0.25, $r->json('data.rates.completion_rate'));
        $this->assertGreaterThan(0, $r->json('data.rates.enrollment_rate'));
        $this->assertLessThanOrEqual(1, $r->json('data.rates.enrollment_rate'));

        // recent_activity includes badge.awarded + course.created entries.
        $types = collect($r->json('data.recent_activity'))->pluck('type')->all();
        $this->assertContains('course.created',  $types);
        $this->assertContains('course.published', $types); // courseA is published
        $this->assertContains('badge.awarded',    $types);
    }
}
