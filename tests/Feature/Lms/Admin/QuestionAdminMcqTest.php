<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Models\Organization;

class QuestionAdminMcqTest extends LmsAdminTestCase
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

    public function test_create_mcq_with_valid_options(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $quiz = $this->makeOwnedQuiz($org);

        $r = $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions", [
            'question_text' => 'What does GDPR mean?',
            'type'          => 'mcq',
            'options'       => [
                ['id' => 'a', 'text' => 'Big rules',                       'is_correct' => false],
                ['id' => 'b', 'text' => 'General Data Protection Reg.',    'is_correct' => true],
                ['id' => 'c', 'text' => 'Random',                           'is_correct' => false],
            ],
        ]);

        $r->assertCreated();
        $r->assertJsonPath('data.type', 'mcq');
        $r->assertJsonPath('data.question_text', 'What does GDPR mean?');
        $r->assertJsonCount(3, 'data.options');
        $r->assertJsonPath('data.options.1.id', 'b');
        $r->assertJsonPath('data.options.1.is_correct', true);

        // DB check: correct_answer column stores keys
        $row = QuizQuestion::where('quiz_id', $quiz->id)->first();
        $this->assertSame(['b'], $row->correct_answer);
    }

    public function test_create_mcq_rejects_no_correct_answer(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $quiz = $this->makeOwnedQuiz($org);

        $r = $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions", [
            'question_text' => 'Bad?',
            'type'          => 'mcq',
            'options'       => [
                ['id' => 'a', 'text' => 'A', 'is_correct' => false],
                ['id' => 'b', 'text' => 'B', 'is_correct' => false],
            ],
        ]);

        $r->assertStatus(422);
        $r->assertJsonValidationErrors(['options']);
    }

    public function test_update_modifies_options(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $quiz = $this->makeOwnedQuiz($org);
        $q = QuizQuestion::create([
            'quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'orig',
            'options' => [
                ['key' => 'a', 'label' => 'A', 'is_correct' => true],
                ['key' => 'b', 'label' => 'B', 'is_correct' => false],
            ],
            'correct_answer' => ['a'], 'order' => 1, 'points' => 1,
        ]);

        $r = $this->putJson("/api/lms/admin/questions/{$q->id}", [
            'options' => [
                ['id' => 'a', 'text' => 'A', 'is_correct' => false],
                ['id' => 'b', 'text' => 'B updated', 'is_correct' => true],
                ['id' => 'c', 'text' => 'New', 'is_correct' => false],
            ],
        ]);
        $r->assertOk();
        $r->assertJsonCount(3, 'data.options');

        $fresh = QuizQuestion::find($q->id);
        $this->assertSame(['b'], $fresh->correct_answer);
    }

    public function test_destroy_returns_204(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $quiz = $this->makeOwnedQuiz($org);
        $q = QuizQuestion::create([
            'quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'x',
            'options' => [['key' => 'a', 'label' => 'A', 'is_correct' => true]],
            'correct_answer' => ['a'], 'order' => 1, 'points' => 1,
        ]);

        $r = $this->deleteJson("/api/lms/admin/questions/{$q->id}");
        $r->assertNoContent();
        $this->assertDatabaseMissing('lms_quiz_questions', ['id' => $q->id]);
    }

    public function test_options_sort_order_preserved(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);

        $quiz = $this->makeOwnedQuiz($org);

        $r = $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions", [
            'question_text' => 'Order test',
            'type'          => 'mcq',
            'options'       => [
                ['id' => 'z', 'text' => 'Z', 'is_correct' => false],
                ['id' => 'a', 'text' => 'A', 'is_correct' => true],
                ['id' => 'm', 'text' => 'M', 'is_correct' => false],
            ],
        ]);
        $r->assertCreated();

        // Order must be preserved as submitted (no sort)
        $r->assertJsonPath('data.options.0.id', 'z');
        $r->assertJsonPath('data.options.1.id', 'a');
        $r->assertJsonPath('data.options.2.id', 'm');
    }
}
