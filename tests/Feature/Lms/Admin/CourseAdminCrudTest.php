<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Models\Organization;

class CourseAdminCrudTest extends LmsAdminTestCase
{
    public function test_index_returns_own_org_and_global_courses_only(): void
    {
        $org = Organization::factory()->create();
        $user = $this->actingAsContentAdmin($org);

        // own-org course
        Course::create([
            'org_id' => $org->id, 'slug' => 'mine', 'title' => 'Mine',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);

        // global course (org_id null)
        Course::create([
            'org_id' => null, 'slug' => 'global', 'title' => 'Global',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 2, 'created_by' => null,
        ]);

        // OTHER org's course — must NOT appear
        $otherOrg = Organization::factory()->create();
        Course::create([
            'org_id' => $otherOrg->id, 'slug' => 'theirs', 'title' => 'Theirs',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 3, 'created_by' => null,
        ]);

        $r = $this->getJson('/api/lms/admin/courses');
        $r->assertOk();

        $titles = collect($r->json('data'))->pluck('title')->all();
        $this->assertContains('Mine', $titles);
        $this->assertContains('Global', $titles);
        $this->assertNotContains('Theirs', $titles);

        $r->assertJsonStructure([
            'data' => [['id', 'slug', 'title', 'status', 'modules_count', 'enrolled_count']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);
        $this->assertEquals(1, $r->json('meta.current_page'));
    }

    public function test_store_creates_course_with_auto_slug_and_defaults_org(): void
    {
        $org = Organization::factory()->create();
        $user = $this->actingAsContentAdmin($org);

        $r = $this->postJson('/api/lms/admin/courses', [
            'title'       => 'My New Course!',
            'description' => 'Welcome',
            'status'      => 'draft',
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.title', 'My New Course!');
        $r->assertJsonPath('data.slug', 'my-new-course');
        $r->assertJsonPath('data.status', 'draft');
        $r->assertJsonPath('data.org_id', $org->id);

        $this->assertDatabaseHas('lms_courses', [
            'title'  => 'My New Course!',
            'slug'   => 'my-new-course',
            'org_id' => $org->id,
        ]);
    }

    public function test_store_validation_errors(): void
    {
        $this->actingAsContentAdmin();

        // missing title
        $r1 = $this->postJson('/api/lms/admin/courses', [
            'status' => 'draft',
        ]);
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['title']);

        // bad status
        $r2 = $this->postJson('/api/lms/admin/courses', [
            'title'  => 'X',
            'status' => 'live',
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['status']);
    }

    public function test_show_returns_course_with_modules_array(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = Course::create([
            'org_id' => $org->id, 'slug' => 'show-me', 'title' => 'Show Me',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'description' => '', 'order' => 1]);
        Module::create(['course_id' => $course->id, 'slug' => 'm2', 'title' => 'M2', 'description' => '', 'order' => 2]);

        $r = $this->getJson("/api/lms/admin/courses/{$course->id}");
        $r->assertOk();
        $r->assertJsonPath('data.id', $course->id);
        $r->assertJsonPath('data.slug', 'show-me');
        $r->assertJsonCount(2, 'data.modules');
        $r->assertJsonPath('data.modules.0.title', 'M1');
        $r->assertJsonPath('data.modules.0.sort_order', 1);
    }

    public function test_update_respects_assert_mutable_for_global_content(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        // tenant admin can update own-org content
        $own = Course::create([
            'org_id' => $org->id, 'slug' => 'own', 'title' => 'Own',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => false, 'order' => 1, 'created_by' => null,
        ]);

        $r1 = $this->putJson("/api/lms/admin/courses/{$own->id}", [
            'title'  => 'Own Updated',
            'status' => 'published',
        ]);
        $r1->assertOk();
        $r1->assertJsonPath('data.title', 'Own Updated');
        $r1->assertJsonPath('data.status', 'published');
        $this->assertDatabaseHas('lms_courses', ['id' => $own->id, 'title' => 'Own Updated', 'published' => true]);

        // tenant admin CANNOT update global (org_id null) content
        $global = Course::create([
            'org_id' => null, 'slug' => 'glob', 'title' => 'Global',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);

        $r2 = $this->putJson("/api/lms/admin/courses/{$global->id}", [
            'title' => 'Hacked',
        ]);
        $r2->assertStatus(403);
        $this->assertDatabaseHas('lms_courses', ['id' => $global->id, 'title' => 'Global']);
    }

    public function test_destroy_returns_204_and_permission_gate_blocks_learner(): void
    {
        // Part A: content_admin can destroy own-org course
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = Course::create([
            'org_id' => $org->id, 'slug' => 'gone', 'title' => 'Gone',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => false, 'order' => 1, 'created_by' => null,
        ]);

        $r = $this->deleteJson("/api/lms/admin/courses/{$course->id}");
        $r->assertNoContent(); // 204
        $this->assertSoftDeleted('lms_courses', ['id' => $course->id]);

        // Part B: a user without lms.content_admin gets 403 from the permission gate
        $learnerOrg = Organization::factory()->create();
        $this->actingAsLearner($learnerOrg);

        $other = Course::create([
            'org_id' => $learnerOrg->id, 'slug' => 'stay', 'title' => 'Stay',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => false, 'order' => 1, 'created_by' => null,
        ]);

        $r2 = $this->deleteJson("/api/lms/admin/courses/{$other->id}");
        $r2->assertStatus(403);
        $this->assertDatabaseHas('lms_courses', ['id' => $other->id, 'deleted_at' => null]);

        // also: index endpoint blocked
        $this->getJson('/api/lms/admin/courses')->assertStatus(403);
    }

    public function test_slug_uniqueness_is_per_org(): void
    {
        // Org A admin creates 'shared'
        $orgA = Organization::factory()->create();
        $this->actingAsContentAdmin($orgA);

        $r1 = $this->postJson('/api/lms/admin/courses', [
            'title'  => 'Shared',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r1->assertCreated();

        // Same admin tries 'shared' again in same org -> 422
        $r2 = $this->postJson('/api/lms/admin/courses', [
            'title'  => 'Shared Two',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['slug']);

        // Org B admin creates 'shared' -> allowed (different org)
        $orgB = Organization::factory()->create();
        $this->actingAsContentAdmin($orgB);

        $r3 = $this->postJson('/api/lms/admin/courses', [
            'title'  => 'Shared B',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r3->assertCreated();
        $r3->assertJsonPath('data.slug', 'shared');
        $r3->assertJsonPath('data.org_id', $orgB->id);

        $this->assertEquals(2, Course::where('slug', 'shared')->count());
    }

    public function test_can_recreate_slug_after_soft_delete(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        // Create a course with a specific slug.
        $r1 = $this->postJson('/api/lms/admin/courses', [
            'title'  => 'Original',
            'slug'   => 'recyclable',
            'status' => 'draft',
        ]);
        $r1->assertCreated();
        $oldId = $r1->json('data.id');

        // Soft-delete it (DELETE -> 204).
        $this->deleteJson("/api/lms/admin/courses/{$oldId}")->assertNoContent();
        $this->assertSoftDeleted('lms_courses', ['id' => $oldId]);

        // Recreating with the same slug must succeed (soft-deleted row ignored).
        $r2 = $this->postJson('/api/lms/admin/courses', [
            'title'  => 'Recreated',
            'slug'   => 'recyclable',
            'status' => 'draft',
        ]);
        $r2->assertCreated();
        $r2->assertJsonPath('data.slug', 'recyclable');

        $newId = $r2->json('data.id');
        $this->assertNotEquals($oldId, $newId);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        // No Sanctum::actingAs — hit the index endpoint anonymously.
        $r = $this->getJson('/api/lms/admin/courses');
        $r->assertStatus(401);
    }
}
