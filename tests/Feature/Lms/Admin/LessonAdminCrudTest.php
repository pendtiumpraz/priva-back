<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Lesson;
use App\Lms\Models\Video;
use App\Models\Organization;

class LessonAdminCrudTest extends LmsAdminTestCase
{
    private function makeVideo(string $externalId = 'abc123', string $source = 'youtube', int $duration = 600): Video
    {
        return Video::create([
            'source'           => $source,
            'external_id'      => $externalId,
            'duration_seconds' => $duration,
            'uploaded_by'      => null,
        ]);
    }

    public function test_index_returns_lessons_for_own_org_module_ordered_by_order(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'host');
        $module = $this->makeModule($course, 'm1');

        Lesson::create(['module_id' => $module->id, 'slug' => 'b', 'title' => 'B', 'body' => '', 'order' => 2, 'duration_seconds' => 120]);
        Lesson::create(['module_id' => $module->id, 'slug' => 'a', 'title' => 'A', 'body' => '', 'order' => 1, 'duration_seconds' => 60]);
        Lesson::create(['module_id' => $module->id, 'slug' => 'c', 'title' => 'C', 'body' => '', 'order' => 3, 'duration_seconds' => 180]);

        $r = $this->getJson("/api/lms/admin/modules/{$module->id}/lessons");
        $r->assertOk();

        $titles = collect($r->json('data'))->pluck('title')->all();
        $this->assertEquals(['A', 'B', 'C'], $titles);

        $r->assertJsonStructure([
            'data' => [['id', 'module_id', 'slug', 'title', 'sort_order', 'status', 'estimated_minutes']],
        ]);
        $r->assertJsonPath('data.0.sort_order', 1);
        $r->assertJsonPath('data.0.status', 'published');
        $r->assertJsonPath('data.0.estimated_minutes', 1);
    }

    public function test_index_returns_404_when_parent_module_belongs_to_other_org(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $otherOrg = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($otherOrg, 'theirs');
        $foreignModule = $this->makeModule($foreignCourse, 'fm');

        $r = $this->getJson("/api/lms/admin/modules/{$foreignModule->id}/lessons");
        $r->assertStatus(404);
    }

    public function test_store_creates_lesson_with_auto_slug_and_default_sort_order(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'host');
        $module = $this->makeModule($course, 'm1');

        // existing lesson with order = 5
        Lesson::create([
            'module_id' => $module->id, 'slug' => 'l5', 'title' => 'L5',
            'body' => '', 'order' => 5, 'duration_seconds' => 60,
        ]);

        $video = $this->makeVideo();

        $r = $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'title'             => 'Pelajaran Satu',
            'status'            => 'published',
            'video_id'          => $video->id,
            'estimated_minutes' => 15,
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.title', 'Pelajaran Satu');
        $r->assertJsonPath('data.slug', 'pelajaran-satu');
        $r->assertJsonPath('data.sort_order', 6); // max(5) + 1
        $r->assertJsonPath('data.module_id', $module->id);
        $r->assertJsonPath('data.estimated_minutes', 15);
        $r->assertJsonPath('data.video.id', $video->id);
        $r->assertJsonPath('data.video.source', 'youtube');

        $this->assertDatabaseHas('lms_lessons', [
            'module_id'        => $module->id,
            'slug'             => 'pelajaran-satu',
            'order'            => 6,
            'duration_seconds' => 900, // 15 * 60
            'video_id'         => $video->id,
        ]);
    }

    public function test_store_validation_errors(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        $course = $this->makeCourse($org, 'host2');
        $module = $this->makeModule($course, 'm2');

        // missing title
        $r1 = $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'status' => 'draft',
        ]);
        $r1->assertStatus(422);
        $r1->assertJsonValidationErrors(['title']);

        // bad status
        $r2 = $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'title'  => 'X',
            'status' => 'live',
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['status']);

        // non-existent video_id
        $r3 = $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'title'    => 'X',
            'status'   => 'draft',
            'video_id' => 999999,
        ]);
        $r3->assertStatus(422);
        $r3->assertJsonValidationErrors(['video_id']);

        // estimated_minutes out of range (high)
        $r4 = $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'title'             => 'X',
            'status'            => 'draft',
            'estimated_minutes' => 999,
        ]);
        $r4->assertStatus(422);
        $r4->assertJsonValidationErrors(['estimated_minutes']);

        // estimated_minutes out of range (low)
        $r5 = $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'title'             => 'X',
            'status'            => 'draft',
            'estimated_minutes' => 0,
        ]);
        $r5->assertStatus(422);
        $r5->assertJsonValidationErrors(['estimated_minutes']);
    }

    public function test_show_returns_lesson_with_video_object(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'show-host');
        $module = $this->makeModule($course, 'sm');

        $video = $this->makeVideo('xyz789', 'youtube', 1200);

        $lesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'l1', 'title' => 'L1',
            'body' => '# Hello', 'order' => 1, 'duration_seconds' => 900,
            'video_id' => $video->id,
        ]);

        $r = $this->getJson("/api/lms/admin/lessons/{$lesson->id}");
        $r->assertOk();
        $r->assertJsonPath('data.id', $lesson->id);
        $r->assertJsonPath('data.module_id', $module->id);
        $r->assertJsonPath('data.title', 'L1');
        $r->assertJsonPath('data.body', '# Hello');
        $r->assertJsonPath('data.estimated_minutes', 15);
        $r->assertJsonPath('data.video.id', $video->id);
        $r->assertJsonPath('data.video.source', 'youtube');
        $r->assertJsonPath('data.video.external_id', 'xyz789');
        $r->assertJsonPath('data.video.duration_seconds', 1200);

        // cross-org -> 404
        $otherOrg = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($otherOrg, 'foreign-host');
        $foreignModule = $this->makeModule($foreignCourse, 'fm');
        $foreignLesson = Lesson::create([
            'module_id' => $foreignModule->id, 'slug' => 'fl', 'title' => 'FL',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);

        $r2 = $this->getJson("/api/lms/admin/lessons/{$foreignLesson->id}");
        $r2->assertStatus(404);
    }

    public function test_show_returns_null_video_when_no_video_associated(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'novideo-host');
        $module = $this->makeModule($course, 'nv');

        $lesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'lv', 'title' => 'LV',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);

        $r = $this->getJson("/api/lms/admin/lessons/{$lesson->id}");
        $r->assertOk();
        $r->assertJsonPath('data.video', null);
    }

    public function test_update_modifies_fields_and_blocks_global_parent(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'upd-host');
        $module = $this->makeModule($course, 'um');
        $lesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'lu', 'title' => 'Old',
            'body' => 'old body', 'order' => 5, 'duration_seconds' => 300,
        ]);

        $video = $this->makeVideo('newvid', 'youtube', 720);

        $r = $this->putJson("/api/lms/admin/lessons/{$lesson->id}", [
            'title'    => 'New Title',
            'body'     => 'new body',
            'status'   => 'draft',
            'video_id' => $video->id,
        ]);
        $r->assertOk();
        $r->assertJsonPath('data.title', 'New Title');
        $r->assertJsonPath('data.body', 'new body');
        $r->assertJsonPath('data.status', 'draft');
        $r->assertJsonPath('data.video.id', $video->id);

        $this->assertDatabaseHas('lms_lessons', [
            'id'        => $lesson->id,
            'title'     => 'New Title',
            'body'      => 'new body',
            'published' => false,
            'video_id'  => $video->id,
        ]);

        // lesson under a global (org_id null) course -> tenant admin cannot mutate
        $globalCourse = $this->makeCourse(null, 'glob-host');
        $globalModule = $this->makeModule($globalCourse, 'gm');
        $globalLesson = Lesson::create([
            'module_id' => $globalModule->id, 'slug' => 'gl', 'title' => 'GL',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);

        $r2 = $this->putJson("/api/lms/admin/lessons/{$globalLesson->id}", ['title' => 'Hacked']);
        $r2->assertStatus(403);
        $this->assertDatabaseHas('lms_lessons', ['id' => $globalLesson->id, 'title' => 'GL']);

        // lesson under a foreign-org course -> 404 (not found via scope)
        $otherOrg = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($otherOrg, 'foreign-upd');
        $foreignModule = $this->makeModule($foreignCourse, 'fu');
        $foreignLesson = Lesson::create([
            'module_id' => $foreignModule->id, 'slug' => 'fl', 'title' => 'FL',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);
        $r3 = $this->putJson("/api/lms/admin/lessons/{$foreignLesson->id}", ['title' => 'Hacked']);
        $r3->assertStatus(404);
    }

    public function test_destroy_returns_204_and_blocks_cross_org(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'del-host');
        $module = $this->makeModule($course, 'dm');
        $lesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'dl', 'title' => 'DL',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);

        $r = $this->deleteJson("/api/lms/admin/lessons/{$lesson->id}");
        $r->assertNoContent();
        $this->assertDatabaseMissing('lms_lessons', ['id' => $lesson->id]);

        // cross-org -> 404
        $otherOrg = Organization::factory()->create();
        $foreignCourse = $this->makeCourse($otherOrg, 'foreign-del');
        $foreignModule = $this->makeModule($foreignCourse, 'fdm');
        $foreignLesson = Lesson::create([
            'module_id' => $foreignModule->id, 'slug' => 'fdl', 'title' => 'FDL',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);

        $r2 = $this->deleteJson("/api/lms/admin/lessons/{$foreignLesson->id}");
        $r2->assertStatus(404);
        $this->assertDatabaseHas('lms_lessons', ['id' => $foreignLesson->id]);
    }

    public function test_slug_uniqueness_scoped_per_module(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $course = $this->makeCourse($org, 'slug-host');
        $moduleA = $this->makeModule($course, 'mA', 1);
        $moduleB = $this->makeModule($course, 'mB', 2);

        // Same slug 'shared' in moduleA -> ok
        $r1 = $this->postJson("/api/lms/admin/modules/{$moduleA->id}/lessons", [
            'title'  => 'Shared',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r1->assertCreated();

        // Same slug again in moduleA -> 422
        $r2 = $this->postJson("/api/lms/admin/modules/{$moduleA->id}/lessons", [
            'title'  => 'Shared 2',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r2->assertStatus(422);
        $r2->assertJsonValidationErrors(['slug']);

        // Same slug in moduleB -> ok (different module)
        $r3 = $this->postJson("/api/lms/admin/modules/{$moduleB->id}/lessons", [
            'title'  => 'Shared B',
            'slug'   => 'shared',
            'status' => 'draft',
        ]);
        $r3->assertCreated();

        $this->assertEquals(2, Lesson::where('slug', 'shared')->count());
    }

    public function test_status_draft_persists_independent_of_parent(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        // Parent course + module are both published
        $course = $this->makeCourse($org, 'pub-host', published: true);
        $module = $this->makeModule($course, 'pm', 1, published: true);

        $r = $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'title'  => 'Draft Lesson',
            'status' => 'draft',
        ]);

        $r->assertCreated();
        $lessonId = $r->json('data.id');

        $show = $this->getJson("/api/lms/admin/lessons/{$lessonId}");
        $show->assertOk();
        $show->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('lms_lessons', [
            'id'        => $lessonId,
            'published' => false,
        ]);
    }

    public function test_permission_gate_blocks_learner_on_each_endpoint(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsLearner($org);

        $course = $this->makeCourse($org, 'lc');
        $module = $this->makeModule($course, 'lm');
        $lesson = Lesson::create([
            'module_id' => $module->id, 'slug' => 'll', 'title' => 'LL',
            'body' => '', 'order' => 1, 'duration_seconds' => 60,
        ]);

        $this->getJson("/api/lms/admin/modules/{$module->id}/lessons")->assertStatus(403);
        $this->postJson("/api/lms/admin/modules/{$module->id}/lessons", [
            'title' => 'X', 'status' => 'draft',
        ])->assertStatus(403);
        $this->getJson("/api/lms/admin/lessons/{$lesson->id}")->assertStatus(403);
        $this->putJson("/api/lms/admin/lessons/{$lesson->id}", ['title' => 'Y'])->assertStatus(403);
        $this->deleteJson("/api/lms/admin/lessons/{$lesson->id}")->assertStatus(403);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $org = Organization::factory()->create();
        $course = $this->makeCourse($org, 'anon-c');
        $module = $this->makeModule($course, 'anon-m');

        $r = $this->getJson("/api/lms/admin/modules/{$module->id}/lessons");
        $r->assertStatus(401);
    }
}
