<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\UserModuleProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseShowModuleTest extends TestCase
{
    use RefreshDatabase;

    private function authedEntitled(): \App\Models\User
    {
        config(['lms.enabled' => true]);
        $org = \App\Models\Organization::factory()->create();
        $user = \App\Models\User::factory()->create(['org_id' => $org->id]);
        $mi = \App\Models\MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        \App\Models\TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($user);
        return $user;
    }

    private function buildCourse(): array
    {
        $course = Course::create([
            'org_id' => null, 'slug' => 'c1', 'title' => 'C1', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $m1 = Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'description' => '', 'order' => 1]);
        $m2 = Module::create(['course_id' => $course->id, 'slug' => 'm2', 'title' => 'M2', 'description' => '', 'order' => 2, 'unlock_after_module_id' => $m1->id]);
        Lesson::create(['module_id' => $m1->id, 'slug' => 'l1', 'title' => 'L1', 'body' => '', 'order' => 1]);
        Lesson::create(['module_id' => $m2->id, 'slug' => 'l1', 'title' => 'L1m2', 'body' => '', 'order' => 1]);
        return [$course, $m1, $m2];
    }

    public function test_first_module_unlocked(): void
    {
        $this->authedEntitled();
        $this->buildCourse();
        $r = $this->getJson('/api/lms/courses/c1/modules/m1');
        $r->assertOk()->assertJsonPath('data.slug', 'm1')->assertJsonCount(1, 'data.lessons');
    }

    public function test_second_module_locked_until_first_completed(): void
    {
        $this->authedEntitled();
        $this->buildCourse();
        $r = $this->getJson('/api/lms/courses/c1/modules/m2');
        $r->assertStatus(403)->assertJsonPath('code', 'LMS_LOCKED');
    }

    public function test_second_module_unlocks_after_first_completed(): void
    {
        $user = $this->authedEntitled();
        [, $m1] = $this->buildCourse();
        UserModuleProgress::create([
            'user_id' => $user->id, 'org_id' => $user->org_id, 'module_id' => $m1->id,
            'status' => 'completed', 'completed_at' => now(),
        ]);
        $r = $this->getJson('/api/lms/courses/c1/modules/m2');
        $r->assertOk();
    }
}
