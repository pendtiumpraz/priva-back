<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookmarkNoteTest extends TestCase
{
    use RefreshDatabase;

    private function setupAuthed(): User
    {
        config(['lms.enabled' => true]);
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function buildLesson(): Lesson
    {
        $course = Course::create(['org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        return Lesson::create(['module_id' => $module->id, 'slug' => 'l', 'title' => 'L', 'body' => '', 'order' => 1]);
    }

    public function test_create_bookmark_then_delete(): void
    {
        $user = $this->setupAuthed();
        $lesson = $this->buildLesson();

        $this->postJson('/api/lms/me/bookmarks', ['lesson_id' => $lesson->id])->assertOk();
        $this->assertDatabaseHas('lms_user_bookmarks', ['user_id' => $user->id, 'lesson_id' => $lesson->id]);

        $this->deleteJson("/api/lms/me/bookmarks/{$lesson->id}")->assertOk();
        $this->assertDatabaseMissing('lms_user_bookmarks', ['user_id' => $user->id, 'lesson_id' => $lesson->id]);
    }

    public function test_bookmark_idempotent(): void
    {
        $this->setupAuthed();
        $lesson = $this->buildLesson();

        $this->postJson('/api/lms/me/bookmarks', ['lesson_id' => $lesson->id]);
        $this->postJson('/api/lms/me/bookmarks', ['lesson_id' => $lesson->id]);

        $this->assertDatabaseCount('lms_user_bookmarks', 1);
    }

    public function test_note_upsert(): void
    {
        $this->setupAuthed();
        $lesson = $this->buildLesson();

        $this->putJson("/api/lms/me/notes/{$lesson->id}", ['body' => 'first note'])->assertOk();
        $this->assertDatabaseHas('lms_user_notes', ['lesson_id' => $lesson->id, 'body' => 'first note']);

        $this->putJson("/api/lms/me/notes/{$lesson->id}", ['body' => 'updated'])->assertOk();
        $this->assertDatabaseHas('lms_user_notes', ['lesson_id' => $lesson->id, 'body' => 'updated']);
        $this->assertDatabaseCount('lms_user_notes', 1);
    }
}
