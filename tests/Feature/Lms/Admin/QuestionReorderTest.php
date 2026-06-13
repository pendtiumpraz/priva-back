<?php

namespace Tests\Feature\Lms\Admin;

use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Models\Organization;

/**
 * POST /admin/quizzes/{quiz}/questions/reorder — bulk question reorder,
 * mirroring the module reorder contract (exhaustive ordered id list).
 */
class QuestionReorderTest extends LmsAdminTestCase
{
    /** @return array{0: Quiz, 1: array<int, QuizQuestion>} */
    private function makeQuizWithQuestions(Organization $org, int $n = 3): array
    {
        $course = $this->makeCourse($org, 'qc-'.uniqid());
        $module = $this->makeModule($course, 'qm-'.uniqid());
        $quiz = Quiz::create([
            'owner_type' => 'module', 'owner_key' => (string) $module->id,
            'title' => 'Q', 'passing_score' => 70,
        ]);
        $qs = [];
        for ($i = 1; $i <= $n; $i++) {
            $qs[] = QuizQuestion::create([
                'quiz_id' => $quiz->id, 'type' => 'true_false', 'prompt' => 'P'.$i,
                'options' => null, 'correct_answer' => [true], 'order' => $i, 'points' => 1,
            ]);
        }
        return [$quiz, $qs];
    }

    public function test_reorder_persists_new_order(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        [$quiz, $qs] = $this->makeQuizWithQuestions($org, 3);

        $r = $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions/reorder", [
            'order' => [$qs[2]->id, $qs[1]->id, $qs[0]->id], // reversed
        ]);

        $r->assertOk();
        $r->assertJsonPath('data.0.id', $qs[2]->id);
        $r->assertJsonPath('data.0.sort_order', 1);
        $r->assertJsonPath('data.2.id', $qs[0]->id);

        $this->assertSame(1, QuizQuestion::find($qs[2]->id)->order);
        $this->assertSame(3, QuizQuestion::find($qs[0]->id)->order);
    }

    public function test_reorder_rejects_partial_set(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        [$quiz, $qs] = $this->makeQuizWithQuestions($org, 3);

        $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions/reorder", [
            'order' => [$qs[0]->id, $qs[1]->id], // missing the 3rd
        ])->assertStatus(422)->assertJsonValidationErrors(['order']);
    }

    public function test_reorder_rejects_foreign_question(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        [$quiz, $qs] = $this->makeQuizWithQuestions($org, 2);
        [, $other] = $this->makeQuizWithQuestions($org, 1); // belongs to another quiz

        $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions/reorder", [
            'order' => [$qs[0]->id, $other[0]->id], // foreign id, wrong count
        ])->assertStatus(422)->assertJsonValidationErrors(['order']);
    }

    public function test_reorder_requires_content_admin(): void
    {
        $org = Organization::factory()->create();
        $this->actingAsContentAdmin($org);
        [$quiz, $qs] = $this->makeQuizWithQuestions($org, 2);

        $this->actingAsLearner($org);
        $this->postJson("/api/lms/admin/quizzes/{$quiz->id}/questions/reorder", [
            'order' => [$qs[1]->id, $qs[0]->id],
        ])->assertStatus(403);
    }
}
