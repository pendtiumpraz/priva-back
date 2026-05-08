<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseIndexTest extends TestCase
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

    public function test_returns_published_global_courses(): void
    {
        $this->authedEntitled();
        Course::create([
            'org_id' => null, 'slug' => 'pub', 'title' => 'Pub', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'published' => true, 'order' => 1, 'created_by' => null,
        ]);

        $r = $this->getJson('/api/lms/courses');
        $r->assertOk();
        $this->assertContains('Pub', collect($r->json('data'))->pluck('title')->all());
        $r->assertJsonPath('data.0.regulation_code', null);  // test course has no regulation_code set
    }

    public function test_returns_regulation_code_when_set(): void
    {
        $this->authedEntitled();
        Course::create([
            'org_id' => null, 'slug' => 'pub2', 'title' => 'Pub2', 'description' => '',
            'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null,
            'regulation_code' => 'UU_PDP',
            'published' => true, 'order' => 1, 'created_by' => null,
        ]);

        $r = $this->getJson('/api/lms/courses');
        $r->assertOk();
        $codes = collect($r->json('data'))->pluck('regulation_code');
        $this->assertContains('UU_PDP', $codes);
    }

    public function test_excludes_other_orgs_private_courses(): void
    {
        $this->authedEntitled();
        $otherOrg = Organization::factory()->create();
        Course::create([
            'org_id' => $otherOrg->id, 'slug' => 'priv', 'title' => 'Priv',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);

        $r = $this->getJson('/api/lms/courses');
        $r->assertOk();
        $titles = collect($r->json('data'))->pluck('title');
        $this->assertNotContains('Priv', $titles);
    }
}
