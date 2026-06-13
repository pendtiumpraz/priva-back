<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Cross-cutting Wave 5.1 acceptance test for D5 (org scoping) across all
 * three admin resources: Course, Module, Lesson.
 *
 * Verifies the OrgScopedQuery trait correctly:
 *   - hides foreign-org content from tenant admins
 *   - exposes null-org (global) content to tenant admins read-only
 *   - blocks mutation of null-org or foreign-org content (403)
 *   - allows root/superadmin full access across all orgs (and to null-org)
 */
class AdminOrgScopeTest extends LmsAdminTestCase
{
    private function actingAsRoot(Organization $org): User
    {
        config(['lms.enabled' => true]);

        // Root user — no tenant_role required, role='root' bypasses permission middleware.
        $user = User::factory()->create([
            'org_id' => $org->id,
            'role'   => 'root',
        ]);

        $this->ensureLmsEntitled($org);

        Sanctum::actingAs($user);

        return $user;
    }

    private function makeLesson(Module $module, string $slug, int $order = 1): Lesson
    {
        return Lesson::create([
            'module_id'        => $module->id,
            'slug'             => $slug,
            'title'            => "Lesson {$slug}",
            'body'             => '',
            'order'            => $order,
            'duration_seconds' => 60,
        ]);
    }

    public function test_tenant_admin_index_excludes_foreign_org_courses(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $this->makeCourse($orgA, 'a-mine');
        $this->makeCourse($orgB, 'b-foreign');
        $this->makeCourse(null, 'globe'); // null-org / global

        $this->actingAsContentAdmin($orgA);

        $r = $this->getJson('/api/lms/admin/courses');
        $r->assertOk();

        $slugs = collect($r->json('data'))->pluck('slug')->all();
        $this->assertContains('a-mine', $slugs);
        $this->assertContains('globe', $slugs);
        $this->assertNotContains('b-foreign', $slugs);
    }

    public function test_tenant_admin_cannot_mutate_global_or_foreign_courses(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $globalCourse = $this->makeCourse(null, 'g-course');
        $foreignCourse = $this->makeCourse($orgB, 'f-course');

        $this->actingAsContentAdmin($orgA);

        // global course visible but PUT/DELETE blocked with 403
        $this->putJson("/api/lms/admin/courses/{$globalCourse->id}", ['title' => 'Hacked'])
            ->assertStatus(403);
        $this->deleteJson("/api/lms/admin/courses/{$globalCourse->id}")
            ->assertStatus(403);

        // POST module under global course -> 403 (cascades through parent)
        $this->postJson("/api/lms/admin/courses/{$globalCourse->id}/modules", [
            'title'  => 'X',
            'status' => 'draft',
        ])->assertStatus(403);

        // foreign-org course invisible -> 404 (not 403, per scope-then-find pattern)
        $this->putJson("/api/lms/admin/courses/{$foreignCourse->id}", ['title' => 'Hacked'])
            ->assertStatus(404);
        $this->deleteJson("/api/lms/admin/courses/{$foreignCourse->id}")
            ->assertStatus(404);

        // POST module under foreign course -> 404
        $this->postJson("/api/lms/admin/courses/{$foreignCourse->id}/modules", [
            'title'  => 'X',
            'status' => 'draft',
        ])->assertStatus(404);

        // DB unchanged
        $this->assertDatabaseHas('lms_courses', ['id' => $globalCourse->id, 'title' => 'Course g-course', 'deleted_at' => null]);
        $this->assertDatabaseHas('lms_courses', ['id' => $foreignCourse->id, 'title' => 'Course f-course', 'deleted_at' => null]);
    }

    public function test_module_and_lesson_mutate_block_cascades_through_parent_chain(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        // Setup: global content chain
        $globalCourse = $this->makeCourse(null, 'g-c');
        $globalModule = $this->makeModule($globalCourse, 'g-m');
        $globalLesson = $this->makeLesson($globalModule, 'g-l');

        // Setup: foreign-org content chain
        $foreignCourse = $this->makeCourse($orgB, 'f-c');
        $foreignModule = $this->makeModule($foreignCourse, 'f-m');
        $foreignLesson = $this->makeLesson($foreignModule, 'f-l');

        $this->actingAsContentAdmin($orgA);

        // Module under global course: visible (show works), but PUT/DELETE blocked
        $this->getJson("/api/lms/admin/modules/{$globalModule->id}")->assertOk();
        $this->putJson("/api/lms/admin/modules/{$globalModule->id}", ['title' => 'Hacked'])
            ->assertStatus(403);
        $this->deleteJson("/api/lms/admin/modules/{$globalModule->id}")
            ->assertStatus(403);

        // POST lesson under global module -> 403
        $this->postJson("/api/lms/admin/modules/{$globalModule->id}/lessons", [
            'title'  => 'X',
            'status' => 'draft',
        ])->assertStatus(403);

        // Lesson under global module: PUT/DELETE blocked with 403
        $this->getJson("/api/lms/admin/lessons/{$globalLesson->id}")->assertOk();
        $this->putJson("/api/lms/admin/lessons/{$globalLesson->id}", ['title' => 'Hacked'])
            ->assertStatus(403);
        $this->deleteJson("/api/lms/admin/lessons/{$globalLesson->id}")
            ->assertStatus(403);

        // Foreign-org module/lesson: 404 across the board
        $this->getJson("/api/lms/admin/modules/{$foreignModule->id}")->assertStatus(404);
        $this->putJson("/api/lms/admin/modules/{$foreignModule->id}", ['title' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/lms/admin/modules/{$foreignModule->id}")->assertStatus(404);
        $this->postJson("/api/lms/admin/modules/{$foreignModule->id}/lessons", [
            'title' => 'X', 'status' => 'draft',
        ])->assertStatus(404);

        $this->getJson("/api/lms/admin/lessons/{$foreignLesson->id}")->assertStatus(404);
        $this->putJson("/api/lms/admin/lessons/{$foreignLesson->id}", ['title' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/lms/admin/lessons/{$foreignLesson->id}")->assertStatus(404);

        // DB unchanged
        $this->assertDatabaseHas('lms_modules', ['id' => $globalModule->id, 'title' => 'Module g-m']);
        $this->assertDatabaseHas('lms_modules', ['id' => $foreignModule->id, 'title' => 'Module f-m']);
        $this->assertDatabaseHas('lms_lessons', ['id' => $globalLesson->id, 'title' => 'Lesson g-l']);
        $this->assertDatabaseHas('lms_lessons', ['id' => $foreignLesson->id, 'title' => 'Lesson f-l']);
    }

    public function test_root_user_sees_all_orgs_courses_in_index(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $this->makeCourse($orgA, 'a-c');
        $this->makeCourse($orgB, 'b-c');
        $this->makeCourse(null, 'globe-c');

        $this->actingAsRoot($orgA);

        $r = $this->getJson('/api/lms/admin/courses');
        $r->assertOk();

        $slugs = collect($r->json('data'))->pluck('slug')->all();
        $this->assertContains('a-c', $slugs);
        $this->assertContains('b-c', $slugs);
        $this->assertContains('globe-c', $slugs);
    }

    public function test_root_user_can_mutate_any_course_including_global_and_foreign(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $globalCourse = $this->makeCourse(null, 'g-mut');
        $foreignCourse = $this->makeCourse($orgB, 'f-mut');

        $this->actingAsRoot($orgA);

        // Root can update global course
        $r1 = $this->putJson("/api/lms/admin/courses/{$globalCourse->id}", ['title' => 'Root Updated G']);
        $r1->assertOk();
        $r1->assertJsonPath('data.title', 'Root Updated G');
        $this->assertDatabaseHas('lms_courses', ['id' => $globalCourse->id, 'title' => 'Root Updated G']);

        // Root can update foreign-org course
        $r2 = $this->putJson("/api/lms/admin/courses/{$foreignCourse->id}", ['title' => 'Root Updated F']);
        $r2->assertOk();
        $r2->assertJsonPath('data.title', 'Root Updated F');
        $this->assertDatabaseHas('lms_courses', ['id' => $foreignCourse->id, 'title' => 'Root Updated F']);

        // Root can post module under global / foreign course
        $r3 = $this->postJson("/api/lms/admin/courses/{$globalCourse->id}/modules", [
            'title' => 'Root Module G', 'status' => 'published',
        ]);
        $r3->assertCreated();

        $r4 = $this->postJson("/api/lms/admin/courses/{$foreignCourse->id}/modules", [
            'title' => 'Root Module F', 'status' => 'published',
        ]);
        $r4->assertCreated();

        // Root can post lesson under foreign module
        $foreignModuleId = $r4->json('data.id');
        $r5 = $this->postJson("/api/lms/admin/modules/{$foreignModuleId}/lessons", [
            'title' => 'Root Lesson F', 'status' => 'published',
        ]);
        $r5->assertCreated();

        // Root can delete foreign-org course
        $this->deleteJson("/api/lms/admin/courses/{$foreignCourse->id}")->assertNoContent();
        $this->assertSoftDeleted('lms_courses', ['id' => $foreignCourse->id]);
    }
}
