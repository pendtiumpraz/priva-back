<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function show(Request $r, $id)
    {
        $quiz = \App\Lms\Models\Quiz::with('questions')->find($id);
        if (! $quiz) return response()->json(['message' => 'Quiz not found.'], 404);

        $questions = $quiz->questions->map(fn ($q) => [
            'id' => $q->id,
            'type' => $q->type,
            'prompt' => $q->prompt,
            'options' => $q->options,
            'points' => $q->points,
            'order' => $q->order,
        ])->values();

        return response()->json(['data' => [
            'id' => $quiz->id,
            'owner_type' => $quiz->owner_type,
            'owner_key' => $quiz->owner_key,
            'passing_score' => $quiz->passing_score,
            'time_limit_seconds' => $quiz->time_limit_seconds,
            'max_attempts' => $quiz->max_attempts,
            'questions' => $questions,
        ]]);
    }

    public function attempt(Request $r, $id)
    {
        $r->validate(['answers' => 'required|array']);
        $user = $r->user();
        $quiz = \App\Lms\Models\Quiz::find($id);
        if (! $quiz) return response()->json(['message' => 'Quiz not found.'], 404);

        $result = app(\App\Lms\Services\QuizGrader::class)->grade($quiz, $r->input('answers'));

        $prev = \App\Lms\Models\QuizAttempt::query()
            ->where('user_id', $user->id)->where('quiz_id', $quiz->id)
            ->max('attempt_number') ?? 0;

        $attempt = \App\Lms\Models\QuizAttempt::create([
            'user_id' => $user->id,
            'org_id' => $user->org_id,
            'quiz_id' => $quiz->id,
            'score' => $result['score'],
            'passed' => $result['passed'],
            'attempt_number' => $prev + 1,
            'started_at' => now(),
            'submitted_at' => now(),
            'answers' => $r->input('answers'),
        ]);

        if ($result['passed']) {
            app(\App\Lms\Services\XpAwardService::class)->award($user, 'quiz.passed', 'quiz', (string) $quiz->id);
            if ($result['score'] === 100) {
                app(\App\Lms\Services\XpAwardService::class)->award($user, 'quiz.perfect', 'quiz', (string) $quiz->id);
            }
            if ($quiz->owner_type === 'module') {
                (new \App\Http\Controllers\Lms\MeController)->reEvaluateModule($user, (int) $quiz->owner_key);
            }
        }

        return response()->json(['data' => [
            'attempt_id' => $attempt->id,
            'score' => $attempt->score,
            'passed' => $attempt->passed,
            'attempt_number' => $attempt->attempt_number,
        ]]);
    }

    public function findByOwner(Request $r)
    {
        $r->validate(['owner_type' => 'required|string', 'owner_key' => 'required|string']);
        $quiz = \App\Lms\Models\Quiz::query()
            ->where('owner_type', $r->owner_type)
            ->where('owner_key', $r->owner_key)
            ->first();

        if (! $quiz) return response()->json(['data' => null]);

        return response()->json(['data' => [
            'id' => $quiz->id,
            'owner_type' => $quiz->owner_type,
            'owner_key' => $quiz->owner_key,
            'passing_score' => $quiz->passing_score,
            'time_limit_seconds' => $quiz->time_limit_seconds,
            'max_attempts' => $quiz->max_attempts,
        ]]);
    }
}
