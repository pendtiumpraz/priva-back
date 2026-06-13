<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Course;
use App\Lms\Models\Module;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Lms\Models\XpRule;
use App\Models\MenuItem;
use App\Models\Organization;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizTenantAndLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function enableLmsAndXpRules(): void
    {
        config(['lms.enabled' => true]);
        XpRule::create(['action_key' => 'quiz.passed', 'xp_amount' => 50]);
        XpRule::create(['action_key' => 'quiz.perfect', 'xp_amount' => 25]);
    }

    private function entitledOrgUser(): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
            'sort_order' => 100, 'hideable' => true, 'required_packages' => [],
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);
        return [$org, $user];
    }

    private function moduleQuiz(?string $courseOrgId, int $passingScore = 70, ?int $maxAttempts = null): array
    {
        $course = Course::create(['org_id' => $courseOrgId, 'slug' => 'c-'.uniqid(), 'title' => 'C', 'description' => '', 'level' => null, 'duration_minutes' => 0, 'thumbnail_url' => null, 'published' => true, 'order' => 1, 'created_by' => null]);
        $module = Module::create(['course_id' => $course->id, 'slug' => 'm-'.uniqid(), 'title' => 'M', 'description' => '', 'order' => 1]);
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => (string) $module->id, 'passing_score' => $passingScore, 'max_attempts' => $maxAttempts]);
        $q = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key' => 'a', 'label' => 'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);
        return [$course, $module, $quiz, $q];
    }

    /** F11: a platform role (org_id null) can submit a quiz attempt — was 500 (NOT NULL org_id). */
    public function test_platform_role_can_submit_quiz_attempt(): void
    {
        $this->enableLmsAndXpRules();
        $superadmin = User::factory()->create(['org_id' => null, 'role' => 'superadmin']);
        Sanctum::actingAs($superadmin);
        [, , $quiz, $q] = $this->moduleQuiz(null);

        $r = $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", ['answers' => [(string) $q->id => ['a']]]);

        $r->assertOk()->assertJsonPath('data.passed', true);
        $this->assertDatabaseHas('lms_quiz_attempts', ['user_id' => $superadmin->id, 'quiz_id' => $quiz->id, 'org_id' => null, 'passed' => true]);
        // XP/leaderboard side-effects also persist with null org.
        $this->assertDatabaseHas('lms_xp_log', ['user_id' => $superadmin->id, 'org_id' => null, 'action' => 'quiz.passed']);
        $this->assertDatabaseHas('lms_org_leaderboard', ['user_id' => $superadmin->id, 'org_id' => null]);
    }

    /** N2: a quiz owned by another tenant's course reads as 404 (no cross-tenant leak). */
    public function test_cross_tenant_quiz_is_404(): void
    {
        $this->enableLmsAndXpRules();
        [, $user] = $this->entitledOrgUser();
        Sanctum::actingAs($user);
        $otherOrg = Organization::factory()->create();
        [, , $quiz, $q] = $this->moduleQuiz($otherOrg->id); // quiz owned by a DIFFERENT org

        $this->getJson("/api/lms/quizzes/{$quiz->id}")->assertNotFound();
        $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", ['answers' => [(string) $q->id => ['a']]])->assertNotFound();
        $this->assertDatabaseMissing('lms_quiz_attempts', ['quiz_id' => $quiz->id]);
    }

    /** N2: a global quiz (course org_id null) stays visible to any entitled tenant. */
    public function test_global_quiz_visible_to_org_user(): void
    {
        $this->enableLmsAndXpRules();
        [, $user] = $this->entitledOrgUser();
        Sanctum::actingAs($user);
        [, , $quiz] = $this->moduleQuiz(null);

        $this->getJson("/api/lms/quizzes/{$quiz->id}")->assertOk()->assertJsonPath('data.id', $quiz->id);
    }

    /** ATT: max_attempts is enforced server-side. */
    public function test_max_attempts_enforced(): void
    {
        $this->enableLmsAndXpRules();
        [, $user] = $this->entitledOrgUser();
        Sanctum::actingAs($user);
        [, , $quiz, $q] = $this->moduleQuiz($user->org_id, 70, 1); // max 1 attempt

        $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", ['answers' => [(string) $q->id => ['b']]])->assertOk();
        $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", ['answers' => [(string) $q->id => ['a']]])
            ->assertStatus(422)->assertJsonPath('code', 'LMS_NO_ATTEMPTS');
    }

    /** XP: passing a quiz a second time does not re-award XP. */
    public function test_xp_not_reawarded_on_second_pass(): void
    {
        $this->enableLmsAndXpRules();
        [, $user] = $this->entitledOrgUser();
        Sanctum::actingAs($user);
        [, , $quiz, $q] = $this->moduleQuiz($user->org_id);

        $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", ['answers' => [(string) $q->id => ['a']]])->assertOk();
        $this->postJson("/api/lms/quizzes/{$quiz->id}/attempts", ['answers' => [(string) $q->id => ['a']]])->assertOk();

        $this->assertSame(1, \App\Lms\Models\XpLog::where('user_id', $user->id)->where('action', 'quiz.passed')->count());
        $this->assertSame(2, \App\Lms\Models\QuizAttempt::where('user_id', $user->id)->where('quiz_id', $quiz->id)->count());
    }
}
