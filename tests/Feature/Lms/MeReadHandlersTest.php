<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
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

    public function test_me_dashboard_returns_continue_learning_pointer(): void
    {
        $this->authedEntitledUser();

        $r = $this->getJson('/api/lms/me/dashboard');
        $r->assertOk()
          ->assertJsonStructure(['data' => ['continue_learning', 'courses_total', 'courses_completed']]);
    }

    public function test_me_courses_lists_global_published_courses(): void
    {
        $this->authedEntitledUser();
        Course::create([
            'org_id' => null, 'slug' => 'test-course', 'title' => 'Test',
            'description' => '...', 'level' => 'beginner', 'duration_minutes' => 60,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);

        $r = $this->getJson('/api/lms/me/courses');
        $r->assertOk();
        $titles = collect($r->json('data'))->pluck('title');
        $this->assertContains('Test', $titles);
    }

    public function test_me_courses_excludes_unpublished(): void
    {
        $this->authedEntitledUser();
        Course::create([
            'org_id' => null, 'slug' => 'draft', 'title' => 'Draft Course',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => false, 'order' => 1, 'created_by' => null,
        ]);

        $r = $this->getJson('/api/lms/me/courses');
        $titles = collect($r->json('data'))->pluck('title');
        $this->assertNotContains('Draft Course', $titles);
    }
}
