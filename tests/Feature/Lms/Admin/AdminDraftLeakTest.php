<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wave 5.1 BE checkpoint regression: lock in that learner-side reads NEVER
 * surface drafts (modules or lessons), even though admin endpoints can now
 * create published=false content.
 *
 * Asymmetry under test:
 *   - Learner endpoints (GET /api/lms/courses/...) -> filter published=true
 *   - Admin endpoints   (GET /api/lms/admin/...)   -> still see drafts
 */
class AdminDraftLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an org-entitled learner (no content_admin permission) and acting-as.
     * Mirrors CourseShowTest::authedEntitled() but reusable across this class.
     */
    private function actingAsLearner(?Organization $org = null): User
    {
        config(['lms.enabled' => true]);
        $org = $org ?: Organization::factory()->create();

        $role = TenantRole::create([
            'org_id'      => $org->id,
            'name'        => 'user',
            'is_system'   => true,
            'description' => 'Learner (test)',
            'permissions' => ['lms.learner'],
        ]);

        $user = User::factory()->create([
            'org_id'         => $org->id,
            'role'           => 'user',
            'tenant_role_id' => $role->id,
        ]);

        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id'    => null,
            'label'             => 'Learn',
            'href'              => '/learn',
            'icon'              => 'GraduationCap',
            'section'           => 'Menu Utama',
            'sort_order'        => 100,
            'hideable'          => true,
            'required_packages' => [],
        ]);
        TenantModuleEntitlement::firstOrCreate(
            ['org_id' => $org->id, 'menu_id' => $mi->id],
            ['is_entitled' => true],
        );

        Sanctum::actingAs($user);
        return $user;
    }

    /**
     * Build an org-entitled content admin (lms.content_admin permission).
     * Used for the asymmetry test (admin endpoints still expose drafts).
     */
    private function actingAsContentAdmin(?Organization $org = null): User
    {
        config(['lms.enabled' => true]);
        $org = $org ?: Organization::factory()->create();

        $role = TenantRole::create([
            'org_id'      => $org->id,
            'name'        => 'tenant_admin',
            'is_system'   => true,
            'description' => 'Tenant admin (test)',
            'permissions' => ['lms.content_admin', 'lms.learner'],
        ]);

        $user = User::factory()->create([
            'org_id'         => $org->id,
            'role'           => 'admin',
            'tenant_role_id' => $role->id,
        ]);

        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id'    => null,
            'label'             => 'Learn',
            'href'              => '/learn',
            'icon'              => 'GraduationCap',
            'section'           => 'Menu Utama',
            'sort_order'        => 100,
            'hideable'          => true,
            'required_packages' => [],
        ]);
        TenantModuleEntitlement::firstOrCreate(
            ['org_id' => $org->id, 'menu_id' => $mi->id],
            ['is_entitled' => true],
        );

        Sanctum::actingAs($user);
        return $user;
    }

    private function makeCourse(?Organization $org, string $slug = 'c1'): Course
    {
        return Course::create([
            'org_id'           => $org?->id,
            'slug'             => $slug,
            'title'            => "Course {$slug}",
            'description'      => '',
            'level'            => null,
            'duration_minutes' => 0,
            'thumbnail_url'    => null,
            'published'        => true,
            'order'            => 1,
            'created_by'       => null,
        ]);
    }

    public function test_learner_does_not_see_draft_module_in_course_show(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        $course = $this->makeCourse($org, 'leak-course');

        // 1 published module (with 1 published lesson) + 1 draft module.
        $publishedModule = Module::create([
            'course_id' => $course->id, 'slug' => 'pub-m', 'title' => 'Published Module',
            'description' => '', 'order' => 1, 'published' => true,
        ]);
        Lesson::create([
            'module_id' => $publishedModule->id, 'slug' => 'pub-l', 'title' => 'Published Lesson',
            'body' => '', 'order' => 1, 'duration_seconds' => 60, 'published' => true,
        ]);
        Module::create([
            'course_id' => $course->id, 'slug' => 'draft-m', 'title' => 'Draft Module',
            'description' => '', 'order' => 2, 'published' => false,
        ]);

        $r = $this->getJson('/api/lms/courses/leak-course');
        $r->assertOk();

        $slugs = collect($r->json('data.modules'))->pluck('slug')->all();
        $this->assertContains('pub-m', $slugs);
        $this->assertNotContains('draft-m', $slugs);
        $this->assertCount(1, $slugs);
    }

    public function test_learner_does_not_see_draft_lesson_in_module_show(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        $course = $this->makeCourse($org, 'leak-course');
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1',
            'description' => '', 'order' => 1, 'published' => true,
        ]);
        Lesson::create([
            'module_id' => $module->id, 'slug' => 'pub-l', 'title' => 'Published Lesson',
            'body' => '', 'order' => 1, 'duration_seconds' => 60, 'published' => true,
        ]);
        Lesson::create([
            'module_id' => $module->id, 'slug' => 'draft-l', 'title' => 'Draft Lesson',
            'body' => '', 'order' => 2, 'duration_seconds' => 60, 'published' => false,
        ]);

        $r = $this->getJson('/api/lms/courses/leak-course/modules/m1');
        $r->assertOk();

        $slugs = collect($r->json('data.lessons'))->pluck('slug')->all();
        $this->assertContains('pub-l', $slugs);
        $this->assertNotContains('draft-l', $slugs);
        $this->assertCount(1, $slugs);
    }

    public function test_learner_cannot_open_draft_module_directly(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        $course = $this->makeCourse($org, 'leak-course');
        Module::create([
            'course_id' => $course->id, 'slug' => 'draft-m', 'title' => 'Draft Module',
            'description' => '', 'order' => 1, 'published' => false,
        ]);

        $r = $this->getJson('/api/lms/courses/leak-course/modules/draft-m');
        $r->assertStatus(404);
    }

    public function test_learner_cannot_open_draft_lesson_directly(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        $course = $this->makeCourse($org, 'leak-course');
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1',
            'description' => '', 'order' => 1, 'published' => true,
        ]);
        Lesson::create([
            'module_id' => $module->id, 'slug' => 'draft-l', 'title' => 'Draft Lesson',
            'body' => '', 'order' => 1, 'duration_seconds' => 60, 'published' => false,
        ]);

        $r = $this->getJson('/api/lms/courses/leak-course/modules/m1/lessons/draft-l');
        $r->assertStatus(404);
    }

    public function test_admin_endpoints_still_show_drafts(): void
    {
        // Locks in the asymmetry: learner side filters; admin side does NOT.
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'admin-sees-drafts');
        $draftModule = Module::create([
            'course_id' => $course->id, 'slug' => 'draft-m', 'title' => 'Draft Module',
            'description' => '', 'order' => 1, 'published' => false,
        ]);
        Lesson::create([
            'module_id' => $draftModule->id, 'slug' => 'draft-l', 'title' => 'Draft Lesson',
            'body' => '', 'order' => 1, 'duration_seconds' => 60, 'published' => false,
        ]);

        // Course detail (admin) returns the draft module.
        $r = $this->getJson("/api/lms/admin/courses/{$course->id}");
        $r->assertOk();
        $slugs = collect($r->json('data.modules'))->pluck('slug')->all();
        $this->assertContains('draft-m', $slugs);

        // Module's lesson list (admin) returns the draft lesson.
        $r2 = $this->getJson("/api/lms/admin/modules/{$draftModule->id}/lessons");
        $r2->assertOk();
        $lessonSlugs = collect($r2->json('data'))->pluck('slug')->all();
        $this->assertContains('draft-l', $lessonSlugs);
    }
}
