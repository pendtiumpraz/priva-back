<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\UserNote;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeNotesListTest extends TestCase
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

    private function buildLesson(string $suffix = 'a'): Lesson
    {
        $course = Course::create([
            'org_id' => null, 'slug' => "cn-{$suffix}", 'title' => "Course {$suffix}",
            'description' => '', 'level' => null, 'duration_minutes' => 0,
            'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null,
        ]);
        $module = Module::create([
            'course_id' => $course->id, 'slug' => "mn-{$suffix}",
            'title' => "Module {$suffix}", 'description' => '', 'order' => 1,
        ]);
        return Lesson::create([
            'module_id' => $module->id, 'slug' => "ln-{$suffix}",
            'title' => "Lesson {$suffix}", 'body' => '', 'order' => 1,
        ]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        config(['lms.enabled' => true]);
        $this->getJson('/api/lms/me/notes')->assertUnauthorized();
    }

    public function test_returns_empty_array_when_no_notes(): void
    {
        $this->setupAuthed();
        $res = $this->getJson('/api/lms/me/notes')->assertOk();
        $res->assertJson(['data' => []]);
    }

    public function test_returns_notes_with_full_lesson_context_and_preview(): void
    {
        $user = $this->setupAuthed();
        $lesson = $this->buildLesson('x');

        UserNote::create([
            'user_id' => $user->id, 'org_id' => $user->org_id,
            'lesson_id' => $lesson->id, 'body' => 'Short note',
        ]);

        $res = $this->getJson('/api/lms/me/notes')->assertOk();
        $res->assertJsonPath('data.0.lesson_id', $lesson->id);
        $res->assertJsonPath('data.0.lesson_slug', 'ln-x');
        $res->assertJsonPath('data.0.lesson_title', 'Lesson x');
        $res->assertJsonPath('data.0.module_slug', 'mn-x');
        $res->assertJsonPath('data.0.module_title', 'Module x');
        $res->assertJsonPath('data.0.course_slug', 'cn-x');
        $res->assertJsonPath('data.0.course_title', 'Course x');
        $res->assertJsonPath('data.0.body_preview', 'Short note');
        $res->assertJsonPath('data.0.body_truncated', false);
        $this->assertArrayHasKey('updated_at', $res->json('data.0'));
    }

    public function test_body_preview_truncated_at_200_chars(): void
    {
        $user = $this->setupAuthed();
        $lesson = $this->buildLesson('trunc');

        $longBody = str_repeat('A', 250);
        UserNote::create([
            'user_id' => $user->id, 'org_id' => $user->org_id,
            'lesson_id' => $lesson->id, 'body' => $longBody,
        ]);

        $res = $this->getJson('/api/lms/me/notes')->assertOk();
        $preview = $res->json('data.0.body_preview');
        $this->assertEquals(200, mb_strlen($preview));
        $this->assertTrue($res->json('data.0.body_truncated'));
    }

    public function test_notes_ordered_most_recently_updated_first(): void
    {
        $user = $this->setupAuthed();
        $l1 = $this->buildLesson('nord1');
        $l2 = $this->buildLesson('nord2');

        $n1 = UserNote::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $l1->id, 'body' => 'old']);
        $n1->timestamps = false;
        $n1->forceFill(['updated_at' => now()->subDay()])->save();

        $n2 = UserNote::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'lesson_id' => $l2->id, 'body' => 'new']);
        $n2->timestamps = false;
        $n2->forceFill(['updated_at' => now()])->save();

        $res = $this->getJson('/api/lms/me/notes')->assertOk();
        $this->assertEquals($l2->id, $res->json('data.0.lesson_id'));
        $this->assertEquals($l1->id, $res->json('data.1.lesson_id'));
    }

    public function test_other_org_notes_not_returned(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $this->setupAuthed($org1);
        $lesson = $this->buildLesson('niso');

        $user2 = User::factory()->create(['org_id' => $org2->id]);
        UserNote::create([
            'user_id' => $user2->id, 'org_id' => $org2->id,
            'lesson_id' => $lesson->id, 'body' => 'other org note',
        ]);

        $res = $this->getJson('/api/lms/me/notes')->assertOk();
        $this->assertEmpty($res->json('data'));
    }
}
