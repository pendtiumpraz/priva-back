<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\UserBookmark;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeBookmarksListTest extends TestCase
{
    use RefreshDatabase;

    private function setupAuthed(?Organization $org = null): User
    {
        config(['lms.enabled' => true]);
        $org = $org ?? Organization::factory()->create();
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

    private function buildLesson(string $courseSuffix = 'a'): Lesson
    {
        $course = Course::create([
            'org_id' => null, 'slug' => "c-{$courseSuffix}", 'title' => "Course {$courseSuffix}",
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => "m-{$courseSuffix}",
            'title' => "Module {$courseSuffix}", 'description' => '', 'order' => 1,
        ]);
        return Lesson::create([
            'module_id' => $module->id, 'slug' => "l-{$courseSuffix}",
            'title' => "Lesson {$courseSuffix}", 'body' => '', 'order' => 1,
        ]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        config(['lms.enabled' => true]);
        $this->getJson('/api/lms/me/bookmarks')->assertUnauthorized();
    }

    public function test_returns_empty_array_when_no_bookmarks(): void
    {
        $this->setupAuthed();
        $res = $this->getJson('/api/lms/me/bookmarks')->assertOk();
        $res->assertJson(['data' => []]);
    }

    public function test_returns_bookmarks_with_full_lesson_context(): void
    {
        $user = $this->setupAuthed();
        $lesson = $this->buildLesson('x');

        UserBookmark::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $lesson->id]);

        $res = $this->getJson('/api/lms/me/bookmarks')->assertOk();
        $res->assertJsonPath('data.0.lesson_id', $lesson->id);
        $res->assertJsonPath('data.0.lesson_slug', 'l-x');
        $res->assertJsonPath('data.0.lesson_title', 'Lesson x');
        $res->assertJsonPath('data.0.module_slug', 'm-x');
        $res->assertJsonPath('data.0.module_title', 'Module x');
        $res->assertJsonPath('data.0.course_slug', 'c-x');
        $res->assertJsonPath('data.0.course_title', 'Course x');
        $this->assertArrayHasKey('created_at', $res->json('data.0'));
    }

    public function test_bookmarks_ordered_most_recent_first(): void
    {
        $user = $this->setupAuthed();
        $l1 = $this->buildLesson('ord1');
        $l2 = $this->buildLesson('ord2');

        $b1 = UserBookmark::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $l1->id]);
        $b1->timestamps = false;
        $b1->forceFill(['created_at' => now()->subDay()])->save();

        $b2 = UserBookmark::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $l2->id]);
        $b2->timestamps = false;
        $b2->forceFill(['created_at' => now()])->save();

        $res = $this->getJson('/api/lms/me/bookmarks')->assertOk();
        $this->assertEquals($l2->id, $res->json('data.0.lesson_id'));
        $this->assertEquals($l1->id, $res->json('data.1.lesson_id'));
    }

    public function test_bookmarks_capped_at_100(): void
    {
        $user = $this->setupAuthed();

        $course = Course::create([
            'org_id' => null, 'slug' => 'cap-course', 'title' => 'Cap Course',
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => 'cap-module',
            'title' => 'Cap Module', 'description' => '', 'order' => 1,
        ]);

        for ($i = 1; $i <= 105; $i++) {
            $lesson = Lesson::create([
                'module_id' => $module->id, 'slug' => "cap-l-{$i}",
                'title' => "Cap Lesson {$i}", 'body' => '', 'order' => $i,
            ]);
            UserBookmark::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $lesson->id]);
        }

        $res = $this->getJson('/api/lms/me/bookmarks')->assertOk();
        $this->assertCount(100, $res->json('data'));
    }

    public function test_other_org_bookmarks_not_returned(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $user1 = $this->setupAuthed($org1);
        $lesson = $this->buildLesson('iso');

        // Bookmark belongs to org2/user2
        $user2 = User::factory()->create(['org_id' => $org2->id]);
        UserBookmark::create(['user_id' => $user2->id, 'org_id' => $org2->id, 'lesson_id' => $lesson->id]);

        $res = $this->getJson('/api/lms/me/bookmarks')->assertOk();
        $this->assertEmpty($res->json('data'));
    }
}
