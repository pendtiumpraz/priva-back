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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeReadHandlersTest extends TestCase
{
    use RefreshDatabase;

    private function authedEntitledUser(): User
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $menuItem = MenuItem::firstOrCreate(
            ['menu_key' => 'lms'],
            [
                'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
                'icon' => 'GraduationCap', 'section' => 'Menu Utama',
                'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
            ]
        );
        TenantModuleEntitlement::create([
            'org_id' => $org->id, 'menu_id' => $menuItem->id, 'is_entitled' => true,
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function seedCourseWithLesson(User $user): Course
    {
        $course = Course::create([
            'org_id' => null, 'slug' => 'test-course', 'title' => 'Test',
            'description' => '...', 'level' => 'beginner', 'duration_minutes' => 60,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1',
            'description' => '', 'order' => 1,
        ]);
        $lesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'l1', 'title' => 'L1',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);
        UserLessonProgress::create([
            'user_id' => $user->id,
            'org_id' => $user->org_id,
            'lesson_id' => $lesson->id,
            'watched_seconds' => 10,
            'completed_at' => null,
        ]);
        return $course;
    }

    public function test_me_dashboard_returns_continue_learning_pointer(): void
    {
        $this->authedEntitledUser();

        $r = $this->getJson('/api/lms/me/dashboard');
        $r->assertOk()
          ->assertJsonStructure(['data' => ['continue_learning', 'courses_total', 'courses_completed']]);
    }

    public function test_me_courses_returns_empty_when_no_progress(): void
    {
        $this->authedEntitledUser();
        Course::create([
            'org_id' => null, 'slug' => 'test-course', 'title' => 'Test',
            'description' => '...', 'level' => 'beginner', 'duration_minutes' => 60,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);

        $r = $this->getJson('/api/lms/me/courses');
        $r->assertOk();
        $this->assertCount(0, $r->json('data'));
    }

    public function test_me_courses_returns_started_courses_only(): void
    {
        $user = $this->authedEntitledUser();
        $this->seedCourseWithLesson($user);

        $r = $this->getJson('/api/lms/me/courses');
        $r->assertOk();
        $titles = collect($r->json('data'))->pluck('title');
        $this->assertContains('Test', $titles);
    }

    public function test_me_progress_returns_summary(): void
    {
        $this->authedEntitledUser();
        $r = $this->getJson('/api/lms/me/progress');
        $r->assertOk()->assertJsonStructure(['data' => ['lessons_completed', 'modules_completed', 'courses_completed']]);
    }

    public function test_me_courses_excludes_unpublished(): void
    {
        $user = $this->authedEntitledUser();

        $course = Course::create([
            'org_id' => null, 'slug' => 'draft', 'title' => 'Draft Course',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => false, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1',
            'description' => '', 'order' => 1,
        ]);
        $lesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'l1', 'title' => 'L1',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);
        // seed progress on unpublished course
        UserLessonProgress::create([
            'user_id' => $user->id,
            'org_id' => $user->org_id,
            'lesson_id' => $lesson->id,
            'watched_seconds' => 5,
            'completed_at' => null,
        ]);

        $r = $this->getJson('/api/lms/me/courses');
        $titles = collect($r->json('data'))->pluck('title');
        $this->assertNotContains('Draft Course', $titles);
    }

    public function test_me_dashboard_returns_xp_summary_block(): void
    {
        $user = $this->authedEntitledUser();
        \App\Lms\Models\OrgLeaderboard::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'xp_total' => 250, 'badges_count' => 2, 'courses_completed' => 0,
            'computed_at' => now(),
        ]);
        \App\Lms\Models\XpLog::create([
            'user_id' => $user->id, 'org_id' => $user->org_id,
            'action' => 'lesson.completed', 'xp_amount' => 10,
            'ref_type' => null, 'ref_id' => null,
        ]);

        $r = $this->getJson('/api/lms/me/dashboard');
        $r->assertOk()
          ->assertJsonStructure(['data' => ['xp_summary' => ['total_xp', 'rank_in_org', 'badges_count', 'recent_xp_events']]]);
        $this->assertEquals(250, $r->json('data.xp_summary.total_xp'));
        $this->assertEquals(2, $r->json('data.xp_summary.badges_count'));
        $this->assertCount(1, $r->json('data.xp_summary.recent_xp_events'));
    }
}
