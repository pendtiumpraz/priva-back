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

        // Tenant isolation: a quiz owned by an org-scoped course/module is only
        // visible to that org (and platform roles). Global quizzes (owning course
        // org_id null, or feature_doc) are visible to everyone. Without this, any
        // entitled user could read another tenant's quiz by guessing its id.
        if ($deny = $this->denyIfCrossTenant($quiz, $r->user())) return $deny;

        // Module-lock enforcement: if this quiz belongs to a locked module, deny access.
        if ($quiz->owner_type === 'module') {
            $module = \App\Lms\Models\Module::find((int) $quiz->owner_key);
            if ($module && $module->unlock_after_module_id) {
                $user = $r->user();
                $prevDone = \App\Lms\Models\UserModuleProgress::query()
                    ->where('user_id', $user->id)
                    ->where('module_id', $module->unlock_after_module_id)
                    ->where('status', 'completed')
                    ->exists();
                if (! $prevDone) {
                    return response()->json(['message' => 'Module locked.', 'code' => 'LMS_LOCKED'], 403);
                }
            }
        }

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

        if ($deny = $this->denyIfCrossTenant($quiz, $user)) return $deny;

        // Enforce attempt cap (when configured) before grading/persisting.
        $prev = \App\Lms\Models\QuizAttempt::query()
            ->where('user_id', $user->id)->where('quiz_id', $quiz->id)
            ->max('attempt_number') ?? 0;
        if ($quiz->max_attempts && $prev >= $quiz->max_attempts) {
            return response()->json([
                'message' => 'Anda telah mencapai batas maksimum percobaan untuk kuis ini.',
                'code' => 'LMS_NO_ATTEMPTS',
            ], 422);
        }

        // XP is awarded once per quiz: a prior PASSED attempt means no re-award
        // (mirrors the lesson-completion guard) to prevent XP/leaderboard farming.
        $alreadyPassed = \App\Lms\Models\QuizAttempt::query()
            ->where('user_id', $user->id)->where('quiz_id', $quiz->id)
            ->where('passed', true)->exists();

        $result = app(\App\Lms\Services\QuizGrader::class)->grade($quiz, $r->input('answers'));

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
            if (! $alreadyPassed) {
                app(\App\Lms\Services\XpAwardService::class)->award($user, 'quiz.passed', 'quiz', (string) $quiz->id);
                if ($result['score'] === 100) {
                    app(\App\Lms\Services\XpAwardService::class)->award($user, 'quiz.perfect', 'quiz', (string) $quiz->id);
                }
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

        // Hide cross-tenant quizzes the same way a missing quiz reads — no leak.
        if ($this->denyIfCrossTenant($quiz, $r->user())) return response()->json(['data' => null]);

        return response()->json(['data' => [
            'id' => $quiz->id,
            'owner_type' => $quiz->owner_type,
            'owner_key' => $quiz->owner_key,
            'passing_score' => $quiz->passing_score,
            'time_limit_seconds' => $quiz->time_limit_seconds,
            'max_attempts' => $quiz->max_attempts,
        ]]);
    }

    /**
     * Resolve the org that effectively owns a quiz (via its owning course), or
     * null for a global quiz (course org_id null, or feature_doc).
     */
    private function resolveQuizOrgId(\App\Lms\Models\Quiz $quiz): ?string
    {
        if ($quiz->owner_type === 'course') {
            return \App\Lms\Models\Course::whereKey((int) $quiz->owner_key)->value('org_id');
        }
        if ($quiz->owner_type === 'module') {
            $module = \App\Lms\Models\Module::find((int) $quiz->owner_key);
            if (! $module) return null;
            return \App\Lms\Models\Course::whereKey($module->course_id)->value('org_id');
        }
        // feature_doc (and any future global owner type) are platform-wide.
        return null;
    }

    /**
     * Returns a 404 JsonResponse when the quiz belongs to a different tenant,
     * or null when access is allowed (global quiz, same org, or platform role).
     */
    private function denyIfCrossTenant(\App\Lms\Models\Quiz $quiz, $user): ?\Illuminate\Http\JsonResponse
    {
        $orgId = $this->resolveQuizOrgId($quiz);
        $isPlatform = $user !== null && in_array($user->role ?? null, ['root', 'superadmin'], true);
        if ($orgId === null || $isPlatform || ($user && $orgId === $user->org_id)) {
            return null;
        }
        return response()->json(['message' => 'Quiz not found.'], 404);
    }
}
