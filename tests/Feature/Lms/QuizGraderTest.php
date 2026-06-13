<?php

namespace Tests\Feature\Lms;

use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use App\Lms\Services\QuizGrader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuizGraderTest extends TestCase
{
    use RefreshDatabase;

    public function test_grades_perfect_mcq(): void
    {
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        $q1 = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);

        $result = app(QuizGrader::class)->grade($quiz, [(string) $q1->id => ['a']]);

        $this->assertEquals(100, $result['score']);
        $this->assertTrue($result['passed']);
    }

    public function test_grades_partial(): void
    {
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        $q1 = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'X', 'options' => [['key'=>'a','label'=>'A']], 'correct_answer' => ['a'], 'points' => 1, 'order' => 1]);
        $q2 = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'mcq', 'prompt' => 'Y', 'options' => [['key'=>'b','label'=>'B']], 'correct_answer' => ['b'], 'points' => 1, 'order' => 2]);

        $result = app(QuizGrader::class)->grade($quiz, [
            (string) $q1->id => ['a'],
            (string) $q2->id => ['c'],
        ]);

        $this->assertEquals(50, $result['score']);
        $this->assertFalse($result['passed']);
    }

    public function test_grades_true_false(): void
    {
        $quiz = Quiz::create(['owner_type' => 'module', 'owner_key' => '1', 'passing_score' => 70]);
        $q = QuizQuestion::create(['quiz_id' => $quiz->id, 'type' => 'true_false', 'prompt' => 'X', 'options' => null, 'correct_answer' => [true], 'points' => 1, 'order' => 1]);

        $result = app(QuizGrader::class)->grade($quiz, [(string) $q->id => [true]]);
        $this->assertEquals(100, $result['score']);
    }
}
