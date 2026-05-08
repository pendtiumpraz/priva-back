<?php

namespace Tests\Feature\Lms;

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

class FeatureDocQuizAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntitledUser(): User
    {
        config(['lms.enabled' => true]);

        $org  = Organization::factory()->create();
        $user = User::factory()->create(['org_id' => $org->id]);

        // Ensure the LMS entitlement middleware passes.
        $mi = MenuItem::firstOrCreate(['menu_key' => 'lms'], [
            'parent_menu_id' => null, 'label' => 'Learn', 'href' => '/learn',
            'icon' => 'GraduationCap', 'section' => 'Menu Utama',
        ]);
        TenantModuleEntitlement::create(['org_id' => $org->id, 'menu_id' => $mi->id, 'is_entitled' => true]);

        Sanctum::actingAs($user);
        return $user;
    }

    public function test_feature_doc_quiz_can_be_fetched_by_owner_key(): void
    {
        $this->makeEntitledUser();

        $quiz = Quiz::create([
            'owner_type'    => 'feature_doc',
            'owner_key'     => 'ropa',
            'title'         => 'Kuis ROPA',
            'passing_score' => 70,
        ]);

        $this->getJson('/api/lms/feature-doc-quizzes?owner_key=ropa')
             ->assertOk()
             ->assertJsonPath('data.id', $quiz->id)
             ->assertJsonPath('data.owner_type', 'feature_doc')
             ->assertJsonMissingPath('data.questions.0.correct_answer');
    }

    public function test_unknown_owner_key_returns_null_not_404(): void
    {
        $this->makeEntitledUser();

        $this->getJson('/api/lms/feature-doc-quizzes?owner_key=nonexistent')
             ->assertOk()
             ->assertJsonPath('data', null);
    }

    public function test_passing_feature_doc_quiz_awards_xp(): void
    {
        $user = $this->makeEntitledUser();

        // Seed the XP rule so the award service actually writes a row.
        XpRule::create(['action_key' => 'quiz.passed', 'xp_amount' => 50]);

        $quiz = Quiz::create([
            'owner_type'    => 'feature_doc',
            'owner_key'     => 'ropa',
            'title'         => 'Kuis ROPA',
            'passing_score' => 0, // pass always
        ]);

        $question = QuizQuestion::create([
            'quiz_id'        => $quiz->id,
            'type'           => 'mcq',
            'prompt'         => 'Kepanjangan ROPA?',
            'options'        => [['key' => 'a', 'label' => 'Record of Processing Activities']],
            'correct_answer' => ['a'],
            'points'         => 1,
            'order'          => 1,
        ]);

        $this->postJson("/api/lms/feature-doc-quizzes/{$quiz->id}/attempt", [
                 'answers' => [$question->id => ['a']],
             ])
             ->assertOk()
             ->assertJsonPath('data.passed', true);

        $this->assertDatabaseHas('lms_xp_log', [
            'user_id' => $user->id,
            'action'  => 'quiz.passed',
            'xp_amount' => 50,
        ]);
    }
}
