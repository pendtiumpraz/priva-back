<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseShowTest extends TestCase
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

    public function test_returns_course_with_ordered_modules(): void
    {
        $this->authedEntitled();
        $course = Course::create([
            'org_id' => null, 'slug' => 'c1', 'title' => 'C1',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'description' => '', 'order' => 1]);
        Module::create(['course_id' => $course->id, 'slug' => 'm2', 'title' => 'M2', 'description' => '', 'order' => 2]);

        $r = $this->getJson('/api/lms/courses/c1');
        $r->assertOk()
          ->assertJsonPath('data.slug', 'c1')
          ->assertJsonCount(2, 'data.modules');
        $this->assertEquals(['M1', 'M2'], collect($r->json('data.modules'))->pluck('title')->all());
    }

    public function test_returns_404_for_unknown_slug(): void
    {
        $this->authedEntitled();
        $this->getJson('/api/lms/courses/does-not-exist')->assertStatus(404);
    }
}
