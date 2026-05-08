<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Lms\Models\UserModuleProgress;
use App\Lms\Models\XpRule;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizFlowTest extends TestCase
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
        XpRule::create(['action_key' => 'quiz.passed', 'xp_amount' => 50]);
        XpRule::create(['action_key' => 'quiz.perfect', 'xp_amount' => 25]);
        XpRule::create(['action_key' => 'lesson.completed', 'xp_amount' => 10]);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_show_quiz_scrubs_correct_answers(): void
    {
        $this->setupAuthed();
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        $r = $this->getJson("/api/lms/quizzes/{$quiz->id}");
        $r->assertOk();
        $payload = $r->json('data');
        $this->assertArrayNotHasKey('correct_answer', $payload['questions'][0]);
    }

    public function test_attempt_writes_row_and_awards_xp_on_pass(): void
    {
        $user = $this->setupAuthed();
        $course = Course::create(['org_id' => null, 'slug' => 'c', 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm', 'title' => 'M', 'description' => '', 'order' => 1]);
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => (string) $module->id, 'passing_score' => 70]);
        $q = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        $r = $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", [
            'answers' => [(string) $q->id => ['a']],
        ]);

        $r->assertOk()
          ->assertJsonPath('data.score', 100)
          ->assertJsonPath('data.passed', true);
        $this->assertDatabaseHas('lms_quiz_attempts', ['user_id' => $user->id, 'quiz_id' => $quiz->id, 'passed' => true]);
        $this->assertDatabaseHas('lms_xp_log', ['action' => 'quiz.passed']);
        $this->assertDatabaseHas('lms_xp_log', ['action' => 'quiz.perfect']);
    }

    public function test_show_quiz_blocked_when_module_locked(): void
    {
        $user = $this->setupAuthed();
        $course = Course::create(['org_id' => null, 'slug' => 'c2', 'title' => 'C2', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 2, 'created_by' => null]);
        $prevModule = Module::create(['course_id' => $course->id, 'slug' => 'prev', 'title' => 'Prev', 'description' => '', 'order' => 1]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm2', 'title' => 'M2', 'description' => '', 'order' => 2, 'unlock_after_module_id' => $prevModule->id]);
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => (string) $module->id, 'passing_score' => 70]);
        QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        // Previous module not completed — quiz fetch should be 403.
        $r = $this->getJson("/api/lms/quizzes/{$quiz->id}");
        $r->assertForbidden()->assertJsonPath('code', 'LMS_LOCKED');
    }

    public function test_show_quiz_allowed_when_module_unlocked(): void
    {
        $user = $this->setupAuthed();
        $course = Course::create(['org_id' => null, 'slug' => 'c3', 'title' => 'C3', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 3, 'created_by' => null]);
        $prevModule = Module::create(['course_id' => $course->id, 'slug' => 'prev2', 'title' => 'Prev2', 'description' => '', 'order' => 1]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm3', 'title' => 'M3', 'description' => '', 'order' => 2, 'unlock_after_module_id' => $prevModule->id]);
        UserModuleProgress::create(['user_id' => $user->id, 'org_id' => $user->org_id, 'module_id' => $prevModule->id, 'status' => 'completed', 'completed_at' => now()]);
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => (string) $module->id, 'passing_score' => 70]);
        QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        $r = $this->getJson("/api/lms/quizzes/{$quiz->id}");
        $r->assertOk()->assertJsonPath('data.id', $quiz->id);
    }

    public function test_failed_attempt_no_xp(): void
    {
        $this->setupAuthed();
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        $q = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        $r = $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", [
            'answers' => [(string) $q->id => ['b']],
        ]);

        $r->assertOk()->assertJsonPath('data.passed', false);
        $this->assertDatabaseMissing('lms_xp_log', ['action' => 'quiz.passed']);
    }
}
