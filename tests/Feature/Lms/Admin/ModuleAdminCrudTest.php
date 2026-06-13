<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Models\Organization;

class ModuleAdminCrudTest extends LmsAdminTestCase
{
    public function test_index_returns_modules_for_own_org_course_ordered_by_order(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'mine');

        Module::create(['course_id' => $course->id, 'slug' => 'b', 'title' => 'B', 'order' => 2]);
        Module::create(['course_id' => $course->id, 'slug' => 'a', 'title' => 'A', 'order' => 1]);
        Module::create(['course_id' => $course->id, 'slug' => 'c', 'title' => 'C', 'order' => 3]);

        $r = $this->getJson("/api/lms/admin/courses/{$course->id}/modules");
        $r->assertOk();

        $titles = collect($r->json('data'))->pluck('title')->all();
        $this->assertEquals(['A', 'B', 'C'], $titles);

        $r->assertJsonStructure([
            'data' => [['id', 'course_id', 'slug', 'title', 'sort_order', 'status', 'lessons_count']],
        ]);
        $r->assertJsonPath('data.0.sort_order', 1);
        $r->assertJsonPath('data.0.status', 'published');
    }

    public function test_index_returns_404_when_parent_course_belongs_to_other_org(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $otherOrg = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($otherOrg, 'theirs');

        $r = $this->getJson("/api/lms/admin/courses/{$foreignCourse->id}/modules");
        $r->assertStatus(404);
    }

    public function test_store_creates_module_with_auto_slug_and_default_sort_order(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'host');

        // existing module with order = 5
        Module::create(['course_id' => $course->id, 'slug' => 'm5', 'title' => 'M5', 'order' => 5]);

        $r = $this->postJson("/api/lms/admin/courses/{$course->id}/modules", [
            'title'  => 'Modul Satu',
            'status' => 'published',
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.title', 'Modul Satu');
        $r->assertJsonPath('data.slug', 'modul-satu');
        $r->assertJsonPath('data.sort_order', 6); // max(5) + 1
        $r->assertJsonPath('data.course_id', $course->id);

        $this->assertDatabaseHas('lms_modules', [
            'course_id' => $course->id,
            'slug'      => 'modul-satu',
            'order'     => 6,
        ]);
    }

    public function test_store_validation_errors(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        $course = $this->makeCourse($org, 'host2');

        // missing title
        $r1 = $this->postJson("/api/lms/admin/courses/{$course->id}/modules", [
            'status' => 'draft',
        ]);
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['title']);

        // bad status
        $r2 = $this->postJson("/api/lms/admin/courses/{$course->id}/modules", [
            'title'  => 'X',
            'status' => 'live',
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['status']);
    }

    public function test_store_blocked_for_global_or_other_org_course(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        // tenant admin cannot add module under a null-org global course
        $globalCourse = $this->makeCourse(null, 'global-c');
        $r1 = $this->postJson("/api/lms/admin/courses/{$globalCourse->id}/modules", [
            'title'  => 'Forbidden',
            'status' => 'draft',
        ]);
        $r1->assertStatus(403);
    }

    public function test_show_returns_module_with_lessons_and_404_cross_org(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'show-host');
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'order' => 1]);

        Lesson::create([
            'module_id' => $module->id, 'slug' => 'l1', 'title' => 'L1',
            'body' => '', 'order' => 1, 'duration_seconds' => 120,
        ]);
        Lesson::create([
            'module_id' => $module->id, 'slug' => 'l2', 'title' => 'L2',
            'body' => '', 'order' => 2, 'duration_seconds' => 360,
        ]);

        $r = $this->getJson("/api/lms/admin/modules/{$module->id}");
        $r->assertOk();
        $r->assertJsonPath('data.id', $module->id);
        $r->assertJsonPath('data.course_id', $course->id);
        $r->assertJsonCount(2, 'data.lessons');
        $r->assertJsonPath('data.lessons.0.slug', 'l1');
        $r->assertJsonPath('data.lessons.0.estimated_minutes', 2);
        $r->assertJsonPath('data.lessons.1.estimated_minutes', 6);

        // cross-org -> 404
        $otherOrg = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($otherOrg, 'foreign-host');
        $foreignModule = Module::create([
            'course_id' => $foreignCourse->id, 'slug' => 'fm', 'title' => 'FM', 'order' => 1,
        ]);

        $r2 = $this->getJson("/api/lms/admin/modules/{$foreignModule->id}");
        $r2->assertStatus(404);
    }

    public function test_update_modifies_title_and_sort_order_and_blocks_global_parent(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'upd-host');
        $module = Module::create(['course_id' => $course->id, 'slug' => 'mu', 'title' => 'Old', 'order' => 5]);

        $r = $this->putJson("/api/lms/admin/modules/{$module->id}", [
            'title'      => 'New Title',
            'sort_order' => 9,
        ]);
        $r->assertOk();
        $r->assertJsonPath('data.title', 'New Title');
        $r->assertJsonPath('data.sort_order', 9);
        $this->assertDatabaseHas('lms_modules', ['id' => $module->id, 'title' => 'New Title', 'order' => 9]);

        // module under a global (org_id null) course -> tenant admin cannot mutate
        $globalCourse = $this->makeCourse(null, 'glob-host');
        $globalModule = Module::create([
            'course_id' => $globalCourse->id, 'slug' => 'gm', 'title' => 'GM', 'order' => 1,
        ]);
        $r2 = $this->putJson("/api/lms/admin/modules/{$globalModule->id}", ['title' => 'Hacked']);
        $r2->assertStatus(403);
        $this->assertDatabaseHas('lms_modules', ['id' => $globalModule->id, 'title' => 'GM']);
    }

    public function test_destroy_returns_204_and_blocks_cross_org(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'del-host');
        $module = Module::create(['course_id' => $course->id, 'slug' => 'dm', 'title' => 'DM', 'order' => 1]);

        $r = $this->deleteJson("/api/lms/admin/modules/{$module->id}");
        $r->assertNoContent();
        $this->assertDatabaseMissing('lms_modules', ['id' => $module->id]);

        // cross-org -> 404
        $otherOrg = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($otherOrg, 'foreign-del');
        $foreignModule = Module::create([
            'course_id' => $foreignCourse->id, 'slug' => 'fdm', 'title' => 'FDM', 'order' => 1,
        ]);
        $r2 = $this->deleteJson("/api/lms/admin/modules/{$foreignModule->id}");
        $r2->assertStatus(404);
        $this->assertDatabaseHas('lms_modules', ['id' => $foreignModule->id]);
    }

    public function test_slug_uniqueness_scoped_per_course(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $courseA = $this->makeCourse($org, 'cA');
        $courseB = $this->makeCourse($org, 'cB');

        // Same slug 'shared' in courseA -> ok
        $r1 = $this->postJson("/api/lms/admin/courses/{$courseA->id}/modules", [
            'title'  => 'Shared',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r1->assertCreated();

        // Same slug again in courseA -> 422
        $r2 = $this->postJson("/api/lms/admin/courses/{$courseA->id}/modules", [
            'title'  => 'Shared 2',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['slug']);

        // Same slug in courseB -> ok (different course)
        $r3 = $this->postJson("/api/lms/admin/courses/{$courseB->id}/modules", [
            'title'  => 'Shared B',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r3->assertCreated();

        $this->assertEquals(2, Module::where('slug', 'shared')->count());
    }

    public function test_reorder_rearranges_modules_and_rejects_foreign_ids(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'ro-host');
        $m1 = Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'order' => 1]);
        $m2 = Module::create(['course_id' => $course->id, 'slug' => 'm2', 'title' => 'M2', 'order' => 2]);
        $m3 = Module::create(['course_id' => $course->id, 'slug' => 'm3', 'title' => 'M3', 'order' => 3]);

        // Reorder: [m3, m1, m2]
        $r = $this->postJson("/api/lms/admin/courses/{$course->id}/modules/reorder", [
            'order' => [$m3->id, $m1->id, $m2->id],
        ]);
        $r->assertOk();

        $orderInResp = collect($r->json('data'))->pluck('id')->all();
        $this->assertEquals([$m3->id, $m1->id, $m2->id], $orderInResp);

        $this->assertDatabaseHas('lms_modules', ['id' => $m3->id, 'order' => 1]);
        $this->assertDatabaseHas('lms_modules', ['id' => $m1->id, 'order' => 2]);
        $this->assertDatabaseHas('lms_modules', ['id' => $m2->id, 'order' => 3]);

        // Reject when an id belongs to another course
        $otherCourse = $this->makeCourse($org, 'other-c');
        $foreignMod = Module::create([
            'course_id' => $otherCourse->id, 'slug' => 'fm', 'title' => 'FM', 'order' => 1,
        ]);

        $r2 = $this->postJson("/api/lms/admin/courses/{$course->id}/modules/reorder", [
            'order' => [$m1->id, $foreignMod->id, $m2->id],
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['order']);
    }

    public function test_reorder_rejects_partial_id_set(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'partial-host');
        $m1 = Module::create(['course_id' => $course->id, 'slug' => 'p1', 'title' => 'P1', 'order' => 1]);
        $m2 = Module::create(['course_id' => $course->id, 'slug' => 'p2', 'title' => 'P2', 'order' => 2]);
        $m3 = Module::create(['course_id' => $course->id, 'slug' => 'p3', 'title' => 'P3', 'order' => 3]);

        // Send only 2 of 3 module ids — must be rejected.
        $r = $this->postJson("/api/lms/admin/courses/{$course->id}/modules/reorder", [
            'order' => [$m3->id, $m1->id],
        ]);
        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['order']);
        $this->assertStringContainsString(
            'all modules',
            strtolower((string) $r->json('errors.order.0')),
        );

        // DB state must be unchanged.
        $this->assertDatabaseHas('lms_modules', ['id' => $m1->id, 'order' => 1]);
        $this->assertDatabaseHas('lms_modules', ['id' => $m2->id, 'order' => 2]);
        $this->assertDatabaseHas('lms_modules', ['id' => $m3->id, 'order' => 3]);
    }

    public function test_permission_gate_blocks_learner_on_each_endpoint(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        // Even if the course/module exists, learner gets 403 from middleware
        $course = $this->makeCourse($org, 'lc');
        $module = Module::create(['course_id' => $course->id, 'slug' => 'lm', 'title' => 'LM', 'order' => 1]);

        $this->getJson("/api/lms/admin/courses/{$course->id}/modules")->assertStatus(403);
        $this->postJson("/api/lms/admin/courses/{$course->id}/modules", [
            'title' => 'X', 'status' => 'draft',
        ])->assertStatus(403);
        $this->getJson("/api/lms/admin/modules/{$module->id}")->assertStatus(403);
        $this->putJson("/api/lms/admin/modules/{$module->id}", ['title' => 'Y'])->assertStatus(403);
        $this->deleteJson("/api/lms/admin/modules/{$module->id}")->assertStatus(403);
        $this->postJson("/api/lms/admin/courses/{$course->id}/modules/reorder", [
            'order' => [$module->id],
        ])->assertStatus(403);
    }

    public function test_status_draft_persists_independent_of_course_published(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'pub-host', published: true);

        $r = $this->postJson("/api/lms/admin/courses/{$course->id}/modules", [
            'title'  => 'Draft Module',
            'status' => 'draft',
        ]);

        $r->assertCreated();
        $moduleId = $r->json('data.id');

        $show = $this->getJson("/api/lms/admin/modules/{$moduleId}");
        $show->assertOk();
        $show->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('lms_modules', [
            'id'        => $moduleId,
            'published' => false,
        ]);
    }

    public function test_module_show_returns_lesson_status_from_lesson_published_not_course_published(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        // Published course + published module + 1 published lesson + 1 draft lesson.
        $course = $this->makeCourse($org, 'mixed-host', published: true);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'mm', 'title' => 'MM',
            'order' => 1, 'published' => true,
        ]);

        $pubLesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'lp', 'title' => 'LP',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
            'published' => true,
        ]);
        $draftLesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'ld', 'title' => 'LD',
            'body' => '', 'order' => 2, 'duration_seconds' => 60,
            'published' => false,
        ]);

        $r = $this->getJson("/api/lms/admin/modules/{$module->id}");
        $r->assertOk();
        $r->assertJsonCount(2, 'data.lessons');

        $lessons = collect($r->json('data.lessons'))->keyBy('id');
        $this->assertSame('published', $lessons[$pubLesson->id]['status']);
        $this->assertSame('draft', $lessons[$draftLesson->id]['status']);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $org = Organization::factory()->create();
        $course = $this->makeCourse($org, 'anon-c');

        $r = $this->getJson("/api/lms/admin/courses/{$course->id}/modules");
        $r->assertStatus(401);
    }
}
