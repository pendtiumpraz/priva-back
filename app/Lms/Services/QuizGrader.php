<?php

namespace App\Lms\Services;

use App\Lms\Models\Quiz;

class QuizGrader
{
    public function grade(Quiz $quiz, array $answers): array
    {
        $totalPoints = 0;
        $earnedPoints = 0;
        foreach ($quiz->questions as $q) {
            $totalPoints += $q->points;
            $submitted = $answers[(string) $q->id] ?? null;
            if ($this->isCorrect($submitted, $q->correct_answer)) {
                $earnedPoints += $q->points;
            }
        }
        $score = $totalPoints > 0 ? (int) round(($earnedPoints / $totalPoints) * 100) : 0;
        return [
            'score' => $score,
            'passed' => $score >= $quiz->passing_score,
        ];
    }

    private function isCorrect($submitted, $correct): bool
    {
        if (! is_array($submitted) || ! is_array($correct)) return false;
        return collect($submitted)->sort()->values()->all() === collect($correct)->sort()->values()->all();
    }
}
