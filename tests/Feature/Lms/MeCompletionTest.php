<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\XpRule;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function setupEntitledUser(): User
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);
        XpRule::create(['action_key' => 'lesson.completed', 'xp_amount' => 10]);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_complete_lesson_writes_progress_and_xp(): void
    {
        $user = $this->setupEntitledUser();
        $course = Course::create(['org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        $lesson = Lesson::create(['module_id' => $module->id, 'slug' => 'l', 'title' => 'L', 'body' => '', 'order' => 1]);

        $r = $this->postJson("/api/lms/me/lessons/{$lesson->id}/complete");

        $r->assertOk();
        $this->assertDatabaseHas('lms_user_lesson_progress', ['user_id' => $user->id, 'lesson_id' => $lesson->id]);
        $this->assertNotNull(\App\Lms\Models\UserLessonProgress::where('lesson_id', $lesson->id)->value('completed_at'));
        $this->assertDatabaseHas('lms_xp_log', ['action' => 'lesson.completed']);
    }

    public function test_complete_lesson_idempotent(): void
    {
        $this->setupEntitledUser();
        $course = Course::create(['org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        $lesson = Lesson::create(['module_id' => $module->id, 'slug' => 'l', 'title' => 'L', 'body' => '', 'order' => 1]);

        $this->postJson("/api/lms/me/lessons/{$lesson->id}/complete");
        $this->postJson("/api/lms/me/lessons/{$lesson->id}/complete");

        $this->assertDatabaseCount('lms_user_lesson_progress', 1);
        $this->assertDatabaseCount('lms_xp_log', 1);
    }

    public function test_progress_updates_watched_seconds(): void
    {
        $this->setupEntitledUser();
        $course = Course::create(['org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        $lesson = Lesson::create(['module_id' => $module->id, 'slug' => 'l', 'title' => 'L', 'body' => '', 'order' => 1, 'duration_seconds' => 600]);

        $r = $this->postJson("/api/lms/me/lessons/{$lesson->id}/progress", ['watched_seconds' => 120]);
        $r->assertOk();
        $this->assertDatabaseHas('lms_user_lesson_progress', ['lesson_id' => $lesson->id, 'watched_seconds' => 120]);
    }

    public function test_module_flips_to_completed_when_all_lessons_done(): void
    {
        $this->setupEntitledUser();
        $course = Course::create(['org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        $l1 = Lesson::create(['module_id' => $module->id, 'slug' => 'l1', 'title' => 'L1', 'body' => '', 'order' => 1]);

        $this->postJson("/api/lms/me/lessons/{$l1->id}/complete");

        $this->assertDatabaseHas('lms_user_module_progress', ['module_id' => $module->id, 'status' => 'completed']);
    }
}
