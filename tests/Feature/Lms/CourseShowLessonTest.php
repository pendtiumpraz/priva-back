<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\UserLessonProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseShowLessonTest extends TestCase
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
        $m = Module::create(['course_id' => $course->id, 'slug' => 'm1', 'title' => 'M1', 'description' => '', 'order' => 1]);
        $l1 = Lesson::create(['module_id' => $m->id, 'slug' => 'l1', 'title' => 'L1', 'body' => 'body 1', 'order' => 1, 'tags' => ['x']]);
        $l2 = Lesson::create(['module_id' => $m->id, 'slug' => 'l2', 'title' => 'L2', 'body' => 'body 2', 'order' => 2]);
        return [$m, $l1, $l2];
    }

    public function test_first_lesson_returned_with_steps_tips_tags(): void
    {
        $this->authedEntitled();
        $this->buildCourse();
        $r = $this->getJson('/api/lms/courses/c1/modules/m1/lessons/l1');
        $r->assertOk()
          ->assertJsonPath('data.slug', 'l1')
          ->assertJsonPath('data.body', 'body 1')
          ->assertJsonPath('data.tags.0', 'x');
    }

    public function test_show_lesson_includes_bookmarked_and_note_body(): void
    {
        $user = $this->authedEntitled();
        $this->buildCourse();

        // Default: not bookmarked, no note
        $r = $this->getJson('/api/lms/courses/c1/modules/m1/lessons/l1');
        $r->assertOk()
          ->assertJsonPath('data.bookmarked', false)
          ->assertJsonPath('data.note_body', null);

        // Bookmark + note the lesson
        \App\Lms\Models\UserBookmark::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $r->json('data.id')]);
        \App\Lms\Models\UserNote::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $r->json('data.id'), 'body' => 'my note']);

        $r2 = $this->getJson('/api/lms/courses/c1/modules/m1/lessons/l1');
        $r2->assertOk()
           ->assertJsonPath('data.bookmarked', true)
           ->assertJsonPath('data.note_body', 'my note');
    }

    public function test_second_lesson_locked_until_first_completed(): void
    {
        $this->authedEntitled();
        $this->buildCourse();
        $r = $this->getJson('/api/lms/courses/c1/modules/m1/lessons/l2');
        $r->assertStatus(403)->assertJsonPath('code', 'LMS_LOCKED');
    }

    public function test_second_lesson_unlocks_after_first_completed(): void
    {
        $user = $this->authedEntitled();
        [, $l1] = $this->buildCourse();
        UserLessonProgress::create([
            'user_id' => $user->id, 'org_id' => $user->org_id,
            'lesson_id' => $l1->id, 'completed_at' => now(), 'watched_seconds' => 0,
        ]);
        $r = $this->getJson('/api/lms/courses/c1/modules/m1/lessons/l2');
        $r->assertOk();
    }

    public function test_show_lesson_serializes_linked_video(): void
    {
        $user = $this->authedEntitled();
        $this->buildCourse();
        $video = \App\Lms\Models\Video::create([
            'source' => 'youtube', 'external_id' => 'dQw4w9WgXcQ',
            'duration_seconds' => 212, 'uploaded_by' => $user->id,
        ]);
        Lesson::where('slug', 'l1')->update(['video_id' => $video->id]);

        $r = $this->getJson('/api/lms/courses/c1/modules/m1/lessons/l1');
        $r->assertOk()
          ->assertJsonPath('data.video.source', 'youtube')
          ->assertJsonPath('data.video.external_id', 'dQw4w9WgXcQ')
          ->assertJsonPath('data.video.duration_seconds', 212);
    }

    public function test_show_lesson_video_null_when_unset(): void
    {
        $this->authedEntitled();
        $this->buildCourse();
        $r = $this->getJson('/api/lms/courses/c1/modules/m1/lessons/l1');
        $r->assertOk()->assertJsonPath('data.video', null);
    }
}
