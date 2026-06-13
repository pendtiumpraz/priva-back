<?php

namespace App\Lms\Services;

use App\Lms\Models\Badge;
use App\Lms\Models\QuizAttempt;
use App\Lms\Models\UserBadge;
use App\Lms\Models\UserLessonProgress;
use App\Lms\Models\UserModuleProgress;
use App\Lms\Models\XpLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BadgeEvaluator
{
    public function evaluate(User $user): array
    {
        $newlyAwarded = [];
        $alreadyEarnedIds = UserBadge::where('user_id', $user->id)->pluck('badge_id')->all();

        foreach (Badge::all() as $badge) {
            if (in_array($badge->id, $alreadyEarnedIds, true)) continue;
            if (! $this->matchesCriteria($user, $badge)) continue;

            UserBadge::firstOrCreate(
                ['user_id' => $user->id, 'badge_id' => $badge->id],
                ['org_id' => $user->org_id, 'awarded_at' => now()]
            );
            $newlyAwarded[] = $badge;
        }
        return $newlyAwarded;
    }

    public function progressFor(User $user, Badge $badge): ?array
    {
        $criteria = is_array($badge->criteria_json) ? $badge->criteria_json : [];
        $params = $criteria['params'] ?? [];

        if ($badge->criteria_type === 'completion') {
            $what = $params['what'] ?? null;
            $target = (int) ($params['min'] ?? 0);
            $current = match ($what) {
                'lessons' => $this->countLessonsCompleted($user),
                'quizzes' => $this->countQuizzesPassed($user),
                'courses' => $this->countCoursesCompleted($user),
                default => 0,
            };
            $label = match ($what) {
                'lessons' => "Selesaikan $target pelajaran",
                'quizzes' => "Lulus $target kuis",
                'courses' => "Selesaikan $target kursus",
                default => $badge->description,
            };
            return ['current' => $current, 'target' => $target, 'label' => $label];
        }

        if ($badge->criteria_type === 'xp_total') {
            $target = (int) ($params['min_xp'] ?? 0);
            return ['current' => $this->totalXp($user), 'target' => $target, 'label' => "Kumpulkan $target XP"];
        }

        return null;
    }

    private function matchesCriteria(User $user, Badge $badge): bool
    {
        $criteria = is_array($badge->criteria_json) ? $badge->criteria_json : [];
        $params = $criteria['params'] ?? [];

        return match ($badge->criteria_type) {
            'completion' => $this->matchesCompletion($user, $params),
            'quiz_score' => $this->matchesQuizScore($user, $params),
            'xp_total' => $this->totalXp($user) >= (int) ($params['min_xp'] ?? 0),
            'custom' => $this->matchesCustom($user, $params),
            default => false,
        };
    }

    private function matchesCompletion(User $user, array $params): bool
    {
        $what = $params['what'] ?? null;
        $min = (int) ($params['min'] ?? 0);
        return match ($what) {
            'lessons' => $this->countLessonsCompleted($user) >= $min,
            'quizzes' => $this->countQuizzesPassed($user) >= $min,
            'courses' => $this->countCoursesCompleted($user) >= $min,
            default => false,
        };
    }

    private function matchesQuizScore(User $user, array $params): bool
    {
        $score = (int) ($params['score'] ?? 100);
        return QuizAttempt::query()
            ->where('user_id', $user->id)
            ->where('passed', true)
            ->where('score', '>=', $score)
            ->exists();
    }

    private function matchesCustom(User $user, array $params): bool
    {
        $kind = $params['kind'] ?? null;
        return match ($kind) {
            'perfect_streak' => $this->matchesPerfectStreak($user, (int) ($params['length'] ?? 3)),
            'daily_streak' => $this->matchesDailyStreak($user, (int) ($params['length'] ?? 3)),
            'course_with_exam_score' => $this->matchesCourseExam($user, $params),
            default => false,
        };
    }

    private function matchesPerfectStreak(User $user, int $length): bool
    {
        $recent = QuizAttempt::query()
            ->where('user_id', $user->id)
            ->orderByDesc('submitted_at')
            ->limit($length)
            ->pluck('score');
        if ($recent->count() < $length) return false;
        return $recent->every(fn ($s) => (int) $s === 100);
    }

    private function matchesDailyStreak(User $user, int $length): bool
    {
        $dates = XpLog::query()
            ->where('user_id', $user->id)
            ->selectRaw('DATE(created_at) as d')
            ->groupBy('d')
            ->orderByDesc('d')
            ->limit($length + 7)
            ->pluck('d')
            ->map(fn ($d) => (string) $d)
            ->all();

        if (count($dates) < $length) return false;

        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        if ($dates[0] !== $today && $dates[0] !== $yesterday) return false;

        $cursor = $dates[0];
        $streak = 1;
        for ($i = 1; $i < count($dates) && $streak < $length; $i++) {
            $expectedPrev = (new \DateTime($cursor))->modify('-1 day')->format('Y-m-d');
            if ($dates[$i] !== $expectedPrev) break;
            $cursor = $dates[$i];
            $streak++;
        }
        return $streak >= $length;
    }

    private function matchesCourseExam(User $user, array $params): bool
    {
        $courseSlug = $params['course_slug'] ?? null;
        $minScore = (int) ($params['exam_score_min'] ?? 80);
        if (! $courseSlug) return false;

        $course = \App\Lms\Models\Course::where('slug', $courseSlug)->first();
        if (! $course) return false;

        $moduleIds = $course->modules()->pluck('id');
        if ($moduleIds->isEmpty()) return false;
        $completedModules = UserModuleProgress::where('user_id', $user->id)
            ->whereIn('module_id', $moduleIds)
            ->where('status', 'completed')
            ->count();
        if ($completedModules < $moduleIds->count()) return false;

        $exam = \App\Lms\Models\Quiz::where('owner_type', 'course')
            ->where('owner_key', (string) $course->id)
            ->first();
        if (! $exam) return false;

        return QuizAttempt::query()
            ->where('user_id', $user->id)
            ->where('quiz_id', $exam->id)
            ->where('score', '>=', $minScore)
            ->exists();
    }

    private function countLessonsCompleted(User $user): int
    {
        return UserLessonProgress::where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->count();
    }

    private function countQuizzesPassed(User $user): int
    {
        return QuizAttempt::where('user_id', $user->id)
            ->where('passed', true)
            ->distinct('quiz_id')
            ->count('quiz_id');
    }

    private function countCoursesCompleted(User $user): int
    {
        return DB::table('lms_modules as m')
            ->whereIn('m.course_id', function ($q) use ($user) {
                $q->select('m2.course_id')->from('lms_modules as m2')
                  ->leftJoin('lms_user_module_progress as p', function ($j) use ($user) {
                      $j->on('p.module_id', '=', 'm2.id')
                        ->where('p.user_id', '=', $user->id)
                        ->where('p.status', '=', 'completed');
                  })
                  ->groupBy('m2.course_id')
                  ->havingRaw('COUNT(m2.id) > 0 AND COUNT(m2.id) = COUNT(p.id)');
            })
            ->distinct()
            ->count('m.course_id');
    }

    private function totalXp(User $user): int
    {
        return (int) XpLog::where('user_id', $user->id)->sum('xp_amount');
    }
}
