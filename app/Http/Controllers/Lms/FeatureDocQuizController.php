<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizAttempt;
use App\Lms\Services\XpAwardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureDocQuizController extends Controller
{
    /**
     * GET /lms/feature-doc-quizzes?owner_key=dashboard
     * Returns the quiz for a given DocSection id, or null (not 404).
     */
    public function findByOwner(Request $request): JsonResponse
    {
        $key = $request->query('owner_key');

        if (! $key) {
            return response()->json(['data' => null]);
        }

        $quiz = Quiz::where('owner_type', 'feature_doc')
                    ->where('owner_key', $key)
                    ->first();

        if (! $quiz) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->quizShape($quiz)]);
    }

    /**
     * GET /lms/feature-doc-quizzes/{quiz}
     * Returns quiz with questions; scrubs correct_answer.
     */
    public function show(Quiz $quiz): JsonResponse
    {
        abort_if($quiz->owner_type !== 'feature_doc', 404);

        $quiz->load('questions');

        $questions = $quiz->questions->map(fn ($q) => [
            'id'      => $q->id,
            'type'    => $q->type,
            'prompt'  => $q->prompt,
            'options' => $q->options,
            'points'  => $q->points ?? 1,
            'order'   => $q->order,
        ])->values();

        return response()->json(['data' => array_merge($this->quizShape($quiz), ['questions' => $questions])]);
    }

    /**
     * POST /lms/feature-doc-quizzes/{quiz}/attempt
     * Grade answers, award XP on pass, return result.
     */
    public function attempt(Request $request, Quiz $quiz): JsonResponse
    {
        abort_if($quiz->owner_type !== 'feature_doc', 404);

        $request->validate(['answers' => 'required|array']);

        $user   = $request->user();

        // Enforce attempt cap (when configured) before grading/persisting.
        $prev = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->max('attempt_number') ?? 0;
        if ($quiz->max_attempts && $prev >= $quiz->max_attempts) {
            return response()->json([
                'message' => 'Anda telah mencapai batas maksimum percobaan untuk kuis ini.',
                'code' => 'LMS_NO_ATTEMPTS',
            ], 422);
        }

        // XP is awarded once: a prior PASSED attempt means no re-award (prevents farming).
        $alreadyPassed = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->where('passed', true)->exists();

        $result = app(\App\Lms\Services\QuizGrader::class)->grade($quiz, $request->input('answers'));

        $attempt = QuizAttempt::create([
            'user_id'        => $user->id,
            'org_id'         => $user->org_id,
            'quiz_id'        => $quiz->id,
            'score'          => $result['score'],
            'passed'         => $result['passed'],
            'attempt_number' => $prev + 1,
            'started_at'     => now(),
            'submitted_at'   => now(),
            'answers'        => $request->input('answers'),
        ]);

        if ($result['passed'] && ! $alreadyPassed) {
            app(XpAwardService::class)->award($user, 'quiz.passed', 'quiz', (string) $quiz->id);

            if ($result['score'] === 100) {
                app(XpAwardService::class)->award($user, 'quiz.perfect', 'quiz', (string) $quiz->id);
            }
        }

        return response()->json(['data' => [
            'attempt_id'     => $attempt->id,
            'score'          => $attempt->score,
            'passed'         => $attempt->passed,
            'attempt_number' => $attempt->attempt_number,
        ]]);
    }

    private function quizShape(Quiz $quiz): array
    {
        return [
            'id'                 => $quiz->id,
            'owner_type'         => $quiz->owner_type,
            'owner_key'          => $quiz->owner_key,
            'title'              => $quiz->title,
            'passing_score'      => $quiz->passing_score,
            'time_limit_seconds' => $quiz->time_limit_seconds ?? null,
            'max_attempts'       => $quiz->max_attempts ?? null,
        ];
    }
}
