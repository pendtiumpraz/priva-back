<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Models\Organization;

class QuestionAdminTrueFalseTest extends LmsAdminTestCase
{
    private function makeOwnedQuiz(?Organization $org = null): Quiz
    {
        $org = $org ?: Organization::factory()->create();
        $course = $this->makeCourse($org, 'qc-' . uniqid());
        $module = $this->makeModule($course, 'qm-' . uniqid());

        return Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $module->id,
            'title' => 'Q', 'passing_score' => 70,
        ]);
    }

    public function test_create_true_false_with_correct_answer(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        $quiz = $this->makeOwnedQuiz($org);

        $r = $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions", [
            'question_text'  => 'PII data is regulated.',
            'type'           => 'true_false',
            'correct_answer' => true,
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.type', 'true_false');
        $r->assertJsonPath('data.correct_answer', true);

        $row = QuizQuestion::where('quiz_id', $quiz->id)->first();
        $this->assertSame([true], $row->correct_answer);
    }

    public function test_true_false_missing_correct_answer_rejected(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        $quiz = $this->makeOwnedQuiz($org);

        $r = $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions", [
            'question_text' => 'Missing answer',
            'type'          => 'true_false',
        ]);

        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['correct_answer']);
    }

    public function test_update_toggles_correct_answer(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        $quiz = $this->makeOwnedQuiz($org);
        $q = QuizQuestion::create([
            'quiz_id' => $quiz->id, 'type' => 'true_false', 'prompt' => 'x',
            'options' => null, 'correct_answer' => [false],
            'order' => 1, 'points' => 1,
        ]);

        $r = $this->putJson("/api/lms/admin/questions/{$q->id}", [
            'correct_answer' => true,
        ]);
        $r->assertOk();
        $r->assertJsonPath('data.correct_answer', true);

        $fresh = QuizQuestion::find($q->id);
        $this->assertSame([true], $fresh->correct_answer);
    }
}
