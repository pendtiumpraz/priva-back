<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\UserLessonProgress;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * /me/progress per-course breakdown: correctness + N+1 guard.
 *
 * The breakdown loop previously ran 2 queries per started course; these tests
 * pin the output values and assert query count does not grow with course count.
 */
class MeProgressBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function authedEntitledUser(): User
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $menuItem = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $menuItem->id, 'is_entitled' => true]);
        Sanctum::actingAs($user);
        return $user;
    }

    /** Course with $total published lessons, $completed of them completed by $user (and "started"). */
    private function seedStartedCourse(User $user, string $slug, int $total, int $completed): void
    {
        $course = Course::create([
            'org_id' => null, 'slug' => $slug, 'title' => strtoupper($slug),
            'description' => '', 'level' => 'beginner', 'duration_minutes' => 60,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => $slug.'-m', 'title' => 'M',
            'description' => '', 'order' => 1, 'published' => true,
        ]);
        for ($i = 0; $i < $total; $i++) {
            $lesson = Lesson::create([
                'module_id' => $module->id, 'slug' => $slug.'-l'.$i, 'title' => 'L'.$i,
                'body' => '', 'order' => $i, 'duration_seconds' => 60, 'published' => true,
            ]);
            UserLessonProgress::create([
                'user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $lesson->id,
                'watched_seconds' => 10, 'completed_at' => $i < $completed ? now() : null,
            ]);
        }
    }

    public function test_progress_breakdown_values_are_accurate(): void
    {
        $user = $this->authedEntitledUser();
        $this->seedStartedCourse($user, 'course-a', 2, 1); // 50%
        $this->seedStartedCourse($user, 'course-b', 4, 0); // 0%, started

        $r = $this->getJson('/api/lms/me/progress')->assertOk();
        $courses = collect($r->json('data.courses'))->keyBy('slug');

        $this->assertSame(1, $courses['course-a']['completed_lessons']);
        $this->assertSame(2, $courses['course-a']['total_lessons']);
        $this->assertSame(50, $courses['course-a']['percent']);
        $this->assertSame(0, $courses['course-b']['completed_lessons']);
        $this->assertSame(4, $courses['course-b']['total_lessons']);
        $this->assertSame(0, $courses['course-b']['percent']);
    }

    public function test_progress_breakdown_has_no_n_plus_one(): void
    {
        $user = $this->authedEntitledUser();
        $this->seedStartedCourse($user, 'c1', 2, 1);
        $this->seedStartedCourse($user, 'c2', 2, 1);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/lms/me/progress')->assertOk();
        $withTwo = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (['c3', 'c4', 'c5', 'c6'] as $slug) {
            $this->seedStartedCourse($user, $slug, 2, 1);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/lms/me/progress')->assertOk();
        $withSix = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Adding 4 more started courses must not add ~2 queries per course.
        $this->assertLessThanOrEqual(
            $withTwo + 2,
            $withSix,
            "N+1 in /me/progress breakdown: queries grew with course count ({$withTwo} -> {$withSix})."
        );
    }
}
