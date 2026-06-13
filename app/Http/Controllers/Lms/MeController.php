<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Platform roles (root / superadmin) bypass org_id scoping on read queries.
     * Their users.org_id is NULL by design — they operate cross-tenant — but
     * dev/demo seed data is org-scoped. Without this bypass, their personal LMS
     * progress / XP / bookmarks / notes queries return zero rows.
     *
     * Mirrors the role list in OrgScopedQuery::isRootUser() and the
     * RootOrSuperadmin middleware.
     */
    private function isPlatformRole($user): bool
    {
        return $user !== null && in_array($user->role ?? null, ['root', 'superadmin'], true);
    }

    public function dashboard(\Illuminate\Http\Request $r)
    {
        $user = $r->user();
        $isPlatform = $this->isPlatformRole($user);

        $continueQuery = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->orderByDesc('updated_at');
        if (! $isPlatform) {
            $continueQuery->where('org_id', $user->org_id);
        }
        $continue = $continueQuery->first();

        $coursesTotal = \App\Lms\Models\Course::query()
            ->where('published', true)
            ->where(function ($q) use ($user, $isPlatform) {
                if ($isPlatform) {
                    return; // see all courses (global + any org)
                }
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })
            ->count();

        // Only count published modules toward completion — draft modules are
        // invisible to learners and must not gate course-completed status.
        $coursesCompleted = \DB::table('lms_modules as m')
            ->where('m.published', true)
            ->whereIn('m.course_id', function ($q) use ($user) {
                $q->select('m2.course_id')->from('lms_modules as m2')
                  ->where('m2.published', true)
                  ->leftJoin('lms_user_module_progress as p', function ($j) use ($user) {
                      $j->on('p.module_id', '=', 'm2.id')
                        ->where('p.user_id', '=', $user->id)
                        ->where('p.status', '=', 'completed');
                  })
                  ->groupBy('m2.course_id')
                  ->havingRaw('COUNT(m2.id) > 0 AND COUNT(m2.id) = COUNT(p.id)');
            })
            ->distinct()->count('m.course_id');

        // Leaderboard rows are keyed by (org_id, user_id). For platform roles
        // we match on user_id alone — seeder writes a row for superadmin under
        // the demo org. rank_in_org is meaningless cross-tenant, so null.
        $leaderboardQuery = \App\Lms\Models\OrgLeaderboard::query()
            ->where('user_id', $user->id);
        if (! $isPlatform) {
            $leaderboardQuery->where('org_id', $user->org_id);
        }
        $leaderboardRow = $leaderboardQuery->first();

        $rankInOrg = null;
        if ($leaderboardRow && ! $isPlatform) {
            // Mirror LeaderboardController's stable sort: DESC xp_total, ASC user_id.
            // Rank = count of rows that sort strictly before this user + 1.
            $rankInOrg = \App\Lms\Models\OrgLeaderboard::query()
                ->where('org_id', $user->org_id)
                ->where(function ($q) use ($leaderboardRow, $user) {
                    $q->where('xp_total', '>', $leaderboardRow->xp_total)
                      ->orWhere(function ($q2) use ($leaderboardRow, $user) {
                          $q2->where('xp_total', '=', $leaderboardRow->xp_total)
                             ->where('user_id', '<', $user->id);
                      });
                })
                ->count() + 1;
        }

        $recentQuery = \App\Lms\Models\XpLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5);
        if (! $isPlatform) {
            $recentQuery->where('org_id', $user->org_id);
        }
        $recent = $recentQuery->get(['action', 'xp_amount', 'created_at']);

        return response()->json(['data' => [
            'continue_learning' => $continue ? [
                'lesson_id' => $continue->lesson_id,
                'watched_seconds' => $continue->watched_seconds,
            ] : null,
            'courses_total' => $coursesTotal,
            'courses_completed' => $coursesCompleted,
            'xp_summary' => [
                'total_xp' => (int) ($leaderboardRow->xp_total ?? 0),
                'rank_in_org' => $rankInOrg,
                'badges_count' => (int) ($leaderboardRow->badges_count ?? 0),
                'recent_xp_events' => $recent->map(fn ($x) => [
                    'action' => $x->action,
                    'xp_amount' => (int) $x->xp_amount,
                    'created_at' => $x->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]]);
    }

    public function courses(\Illuminate\Http\Request $r)
    {
        $user = $r->user();
        $isPlatform = $this->isPlatformRole($user);

        // "My courses" = courses where the user has any lesson progress.
        // (No progress yet → empty. This is intentional; full catalog lives at GET /api/lms/courses.)
        $courseIdsQuery = \DB::table('lms_user_lesson_progress as p')
            ->join('lms_lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
            ->where('p.user_id', $user->id);
        if (! $isPlatform) {
            $courseIdsQuery->where('p.org_id', $user->org_id);
        }
        $courseIds = $courseIdsQuery->distinct()->pluck('m.course_id');

        if ($courseIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $courses = \App\Lms\Models\Course::query()
            ->whereIn('id', $courseIds)
            ->where('published', true)
            ->where(function ($q) use ($user, $isPlatform) {
                if ($isPlatform) {
                    return;
                }
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })
            ->orderBy('order')
            ->get(['id', 'slug', 'title', 'description', 'level', 'duration_minutes', 'thumbnail_url', 'regulation_code', 'order']);

        return response()->json(['data' => $courses]);
    }
    public function badges(\Illuminate\Http\Request $r)
    {
        $user = $r->user();
        $since = $r->query('since');

        if ($since) {
            $sinceCarbon = \Illuminate\Support\Carbon::parse($since);
            $earned = \App\Lms\Models\UserBadge::query()
                ->with('badge')
                ->where('user_id', $user->id)
                ->where('awarded_at', '>', $sinceCarbon)
                ->orderByDesc('awarded_at')
                ->get();

            return response()->json(['data' => [
                'earned' => $earned->map(fn ($ub) => $this->serializeEarned($ub))->values(),
            ]]);
        }

        $allEarned = \App\Lms\Models\UserBadge::query()
            ->with('badge')
            ->where('user_id', $user->id)
            ->orderByDesc('awarded_at')
            ->get();

        $earnedBadgeIds = $allEarned->pluck('badge_id')->all();

        $lockedBadges = \App\Lms\Models\Badge::query()
            ->whereNotIn('id', $earnedBadgeIds)
            ->get();

        $evaluator = app(\App\Lms\Services\BadgeEvaluator::class);

        return response()->json(['data' => [
            'earned' => $allEarned->map(fn ($ub) => $this->serializeEarned($ub))->values(),
            'locked' => $lockedBadges->map(function ($b) use ($user, $evaluator) {
                $criteria = is_array($b->criteria_json) ? $b->criteria_json : [];
                $progress = $evaluator->progressFor($user, $b);
                return [
                    'id' => $b->id,
                    'slug' => $b->slug,
                    'name' => $b->name,
                    'description' => $b->description,
                    'icon' => $b->icon,
                    'theme' => $criteria['theme'] ?? 'indigo',
                    'progress' => $progress,
                ];
            })->values(),
        ]]);
    }

    private function serializeEarned(\App\Lms\Models\UserBadge $ub): array
    {
        $b = $ub->badge;
        $criteria = is_array($b->criteria_json) ? $b->criteria_json : [];
        return [
            'id' => $b->id,
            'slug' => $b->slug,
            'name' => $b->name,
            'description' => $b->description,
            'icon' => $b->icon,
            'theme' => $criteria['theme'] ?? 'indigo',
            'awarded_at' => $ub->awarded_at?->toIso8601String(),
        ];
    }
    public function bookmarks(Request $r): \Illuminate\Http\JsonResponse
    {
        $user = $r->user();

        $bookmarksQuery = \App\Lms\Models\UserBookmark::query()
            ->where('user_id', $user->id);
        if (! $this->isPlatformRole($user)) {
            $bookmarksQuery->where('org_id', $user->org_id);
        }
        $bookmarks = $bookmarksQuery
            ->with([
                'lesson:id,slug,title,module_id',
                'lesson.module:id,slug,title,course_id',
                'lesson.module.course:id,slug,title',
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($b) => [
                'lesson_id'    => $b->lesson_id,
                'lesson_slug'  => $b->lesson->slug,
                'lesson_title' => $b->lesson->title,
                'module_slug'  => $b->lesson->module->slug,
                'module_title' => $b->lesson->module->title,
                'course_slug'  => $b->lesson->module->course->slug,
                'course_title' => $b->lesson->module->course->title,
                'created_at'   => $b->created_at->toIso8601String(),
            ]);

        return response()->json(['data' => $bookmarks]);
    }
    public function notes(Request $r): \Illuminate\Http\JsonResponse
    {
        $user = $r->user();

        $notesQuery = \App\Lms\Models\UserNote::query()
            ->where('user_id', $user->id);
        if (! $this->isPlatformRole($user)) {
            $notesQuery->where('org_id', $user->org_id);
        }
        $notes = $notesQuery
            ->with([
                'lesson:id,slug,title,module_id',
                'lesson.module:id,slug,title,course_id',
                'lesson.module.course:id,slug,title',
            ])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn ($n) => [
                'lesson_id'      => $n->lesson_id,
                'lesson_slug'    => $n->lesson->slug,
                'lesson_title'   => $n->lesson->title,
                'module_slug'    => $n->lesson->module->slug,
                'module_title'   => $n->lesson->module->title,
                'course_slug'    => $n->lesson->module->course->slug,
                'course_title'   => $n->lesson->module->course->title,
                'body_preview'   => mb_substr(strip_tags($n->body), 0, 200),
                'body_truncated' => mb_strlen(strip_tags($n->body)) > 200,
                'updated_at'     => $n->updated_at->toIso8601String(),
            ]);

        return response()->json(['data' => $notes]);
    }

    public function progress(\Illuminate\Http\Request $r)
    {
        $user = $r->user();
        $isPlatform = $this->isPlatformRole($user);

        // ── Per-user lesson/module counters ────────────────────────────────
        // FE renders an overall progress bar + per-course breakdown, so we
        // need both raw totals AND a courses[] array. Only published
        // lessons/modules count — drafts are invisible to learners.
        $publishedLessonIds = \DB::table('lms_lessons as l')
            ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
            ->where('l.published', true)
            ->where('m.published', true)
            ->pluck('l.id');

        $lessonsCompleted = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', $publishedLessonIds)
            ->whereNotNull('completed_at')->count();

        $lessonsTotal = $publishedLessonIds->count();

        $modulesCompleted = \App\Lms\Models\UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')->count();

        // Only count published modules toward completion — draft modules are
        // invisible to learners and must not gate course-completed status.
        $coursesCompleted = \DB::table('lms_modules as m')
            ->where('m.published', true)
            ->whereIn('m.course_id', function ($q) use ($user) {
                $q->select('m2.course_id')->from('lms_modules as m2')
                  ->where('m2.published', true)
                  ->leftJoin('lms_user_module_progress as p', function ($j) use ($user) {
                      $j->on('p.module_id', '=', 'm2.id')
                        ->where('p.user_id', '=', $user->id)
                        ->where('p.status', '=', 'completed');
                  })
                  ->groupBy('m2.course_id')
                  ->havingRaw('COUNT(m2.id) > 0 AND COUNT(m2.id) = COUNT(p.id)');
            })
            ->distinct()->count('m.course_id');

        // ── Per-course breakdown ───────────────────────────────────────────
        // "Started" = user has any lesson progress in the course (completed or
        // not). The FE expects each entry: {slug, title, completed_lessons,
        // total_lessons, percent}.
        $startedCourseIds = \DB::table('lms_user_lesson_progress as p')
            ->join('lms_lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
            ->where('p.user_id', $user->id)
            ->distinct()
            ->pluck('m.course_id');

        $courses = [];
        if ($startedCourseIds->isNotEmpty()) {
            $courseRows = \App\Lms\Models\Course::query()
                ->whereIn('id', $startedCourseIds)
                ->where('published', true)
                ->where(function ($q) use ($user, $isPlatform) {
                    if ($isPlatform) {
                        return;
                    }
                    $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
                })
                ->orderBy('order')
                ->get(['id', 'slug', 'title']);

            $courseIds = $courseRows->pluck('id');

            // Two grouped queries instead of 2-per-course (avoids an N+1 over
            // started courses): total published lessons per course, and the
            // user's completed published lessons per course.
            $totalByCourse = \DB::table('lms_lessons as l')
                ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
                ->whereIn('m.course_id', $courseIds)
                ->where('m.published', true)
                ->where('l.published', true)
                ->groupBy('m.course_id')
                ->selectRaw('m.course_id as course_id, COUNT(l.id) as cnt')
                ->pluck('cnt', 'course_id');

            $completedByCourse = \DB::table('lms_user_lesson_progress as p')
                ->join('lms_lessons as l', 'l.id', '=', 'p.lesson_id')
                ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
                ->where('p.user_id', $user->id)
                ->whereNotNull('p.completed_at')
                ->whereIn('m.course_id', $courseIds)
                ->where('m.published', true)
                ->where('l.published', true)
                ->groupBy('m.course_id')
                ->selectRaw('m.course_id as course_id, COUNT(p.id) as cnt')
                ->pluck('cnt', 'course_id');

            foreach ($courseRows as $course) {
                $totalLessons = (int) ($totalByCourse[$course->id] ?? 0);
                $completedLessons = (int) ($completedByCourse[$course->id] ?? 0);
                $percent = $totalLessons > 0
                    ? (int) round(($completedLessons / $totalLessons) * 100)
                    : 0;

                $courses[] = [
                    'slug' => $course->slug,
                    'title' => $course->title,
                    'completed_lessons' => $completedLessons,
                    'total_lessons' => $totalLessons,
                    'percent' => $percent,
                ];
            }
        }

        $overallPercent = $lessonsTotal > 0
            ? (int) round(($lessonsCompleted / $lessonsTotal) * 100)
            : 0;

        // ── XP summary ─────────────────────────────────────────────────────
        // Platform roles (root/superadmin) have NULL org_id; they read any
        // org's leaderboard/xp_log row keyed by their user_id (seeder writes
        // one under the demo org).
        $leaderboardQuery = \App\Lms\Models\OrgLeaderboard::query()
            ->where('user_id', $user->id);
        if (! $isPlatform) {
            $leaderboardQuery->where('org_id', $user->org_id);
        }
        $leaderboardRow = $leaderboardQuery->first();

        $recentXpQuery = \App\Lms\Models\XpLog::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5);
        if (! $isPlatform) {
            $recentXpQuery->where('org_id', $user->org_id);
        }
        $recentXp = $recentXpQuery->get(['action', 'xp_amount', 'created_at']);

        return response()->json(['data' => [
            'overall_percent' => $overallPercent,
            'lessons_completed' => $lessonsCompleted,
            'lessons_total' => $lessonsTotal,
            'modules_completed' => $modulesCompleted,
            'courses_completed' => $coursesCompleted,
            'courses_started' => $startedCourseIds->count(),
            'xp_total' => (int) ($leaderboardRow->xp_total ?? 0),
            'courses' => $courses,
            'recent_xp_events' => $recentXp->map(fn ($x) => [
                'action' => $x->action,
                'xp_amount' => (int) $x->xp_amount,
                'created_at' => $x->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    /**
     * Personal XP analytics for the leaderboard side widgets:
     *  - weekly: XP per day for the last 7 days (incl. today)
     *  - sources: XP grouped by category (lesson/quiz/streak/badge) over 30 days
     *
     * Org-scoped with the usual platform-role bypass (root/superadmin NULL org).
     */
    public function xpStats(\Illuminate\Http\Request $r)
    {
        $user = $r->user();
        $isPlatform = $this->isPlatformRole($user);

        $base = fn () => tap(\App\Lms\Models\XpLog::query()->where('user_id', $user->id), function ($q) use ($isPlatform, $user) {
            if (! $isPlatform) {
                $q->where('org_id', $user->org_id);
            }
        });

        // ── Weekly: XP per day, last 7 days including today ────────────────
        $start = now()->startOfDay()->subDays(6);
        $byDate = $base()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as d, SUM(xp_amount) as xp')
            ->groupBy('d')
            ->pluck('xp', 'd');

        $dayLabels = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab']; // Carbon dayOfWeek 0=Sun
        $weekly = [];
        $weeklyTotal = 0;
        for ($i = 0; $i < 7; $i++) {
            $date = (clone $start)->addDays($i);
            $xp = (int) ($byDate[$date->toDateString()] ?? 0);
            $weeklyTotal += $xp;
            $weekly[] = [
                'date'  => $date->toDateString(),
                'label' => $dayLabels[$date->dayOfWeek],
                'xp'    => $xp,
            ];
        }

        // ── Sources: XP by category, last 30 days ──────────────────────────
        $byAction = $base()
            ->where('created_at', '>=', now()->startOfDay()->subDays(29))
            ->selectRaw('action, SUM(xp_amount) as xp')
            ->groupBy('action')
            ->pluck('xp', 'action');

        $categories = [
            'lesson' => ['label' => 'Lesson', 'xp' => 0],
            'quiz'   => ['label' => 'Kuis', 'xp' => 0],
            'streak' => ['label' => 'Streak', 'xp' => 0],
            'badge'  => ['label' => 'Lencana', 'xp' => 0],
            'other'  => ['label' => 'Lainnya', 'xp' => 0],
        ];
        foreach ($byAction as $action => $xp) {
            $cat = match (explode('.', (string) $action)[0]) {
                'lesson', 'module', 'course' => 'lesson',
                'quiz', 'exam'               => 'quiz',
                'streak', 'daily'            => 'streak',
                'badge'                      => 'badge',
                default                      => 'other',
            };
            $categories[$cat]['xp'] += (int) $xp;
        }

        $maxSource = max(1, ...array_values(array_map(fn ($c) => $c['xp'], $categories)));
        $sources = [];
        foreach ($categories as $key => $c) {
            if ($c['xp'] === 0) {
                continue; // omit empty categories so the widget stays clean
            }
            $sources[] = [
                'key'   => $key,
                'label' => $c['label'],
                'xp'    => $c['xp'],
                'pct'   => (int) round(($c['xp'] / $maxSource) * 100),
            ];
        }

        return response()->json(['data' => [
            'weekly'       => $weekly,
            'weekly_total' => $weeklyTotal,
            'sources'      => $sources,
        ]]);
    }

    public function completeLesson(\Illuminate\Http\Request $r, $id)
    {
        $user = $r->user();
        $lesson = \App\Lms\Models\Lesson::find($id);
        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $existing = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        $alreadyCompleted = $existing && $existing->completed_at !== null;

        $progress = \App\Lms\Models\UserLessonProgress::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['org_id' => $user->org_id, 'completed_at' => $existing?->completed_at ?? now()]
        );

        if (! $alreadyCompleted) {
            app(\App\Lms\Services\XpAwardService::class)->award($user, 'lesson.completed', 'lesson', (string) $lesson->id);
            $this->reEvaluateModule($user, $lesson->module_id);
        }

        return response()->json(['data' => [
            'lesson_id' => $lesson->id,
            'completed_at' => $progress->completed_at,
        ]]);
    }

    public function lessonProgress(\Illuminate\Http\Request $r, $id)
    {
        $r->validate(['watched_seconds' => 'required|integer|min:0']);
        $user = $r->user();
        $lesson = \App\Lms\Models\Lesson::find($id);
        if (! $lesson) return response()->json(['message' => 'Lesson not found.'], 404);

        $clamped = $lesson->duration_seconds
            ? min((int) $r->watched_seconds, $lesson->duration_seconds)
            : (int) $r->watched_seconds;

        $progress = \App\Lms\Models\UserLessonProgress::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['org_id' => $user->org_id, 'watched_seconds' => $clamped]
        );

        return response()->json(['data' => ['watched_seconds' => $progress->watched_seconds]]);
    }

    public function reEvaluateModule(\App\Models\User $user, int $moduleId): void
    {
        $module = \App\Lms\Models\Module::find($moduleId);
        if (! $module) return;

        // Only published lessons gate module completion. Drafts are invisible
        // to learners and must not block the completion check.
        $lessonIds = $module->lessons()->published()->pluck('id');
        $completed = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', $lessonIds)
            ->whereNotNull('completed_at')
            ->count();

        if ($completed < $lessonIds->count()) return;

        $quiz = \App\Lms\Models\Quiz::query()
            ->where('owner_type', 'module')
            ->where('owner_key', (string) $module->id)
            ->first();

        if ($quiz) {
            $passed = \App\Lms\Models\QuizAttempt::query()
                ->where('user_id', $user->id)
                ->where('quiz_id', $quiz->id)
                ->where('passed', true)
                ->exists();
            if (! $passed) return;
        }

        $existing = \App\Lms\Models\UserModuleProgress::query()
            ->where('user_id', $user->id)->where('module_id', $module->id)->first();

        if ($existing && $existing->status === 'completed') return;

        \App\Lms\Models\UserModuleProgress::updateOrCreate(
            ['user_id' => $user->id, 'module_id' => $module->id],
            ['org_id' => $user->org_id, 'status' => 'completed', 'completed_at' => now()]
        );

        $course = $module->course;
        if (! $course) return;
        // Only published modules gate course-completed XP award.
        $allModuleIds = $course->modules()->published()->pluck('id');
        $completedModules = \App\Lms\Models\UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('module_id', $allModuleIds)
            ->where('status', 'completed')
            ->count();
        if ($completedModules >= $allModuleIds->count()) {
            app(\App\Lms\Services\XpAwardService::class)->award($user, 'course.completed', 'course', (string) $course->id);
        }
    }

    public function bookmarkCreate(\Illuminate\Http\Request $r)
    {
        $r->validate(['lesson_id' => 'required|integer']);
        $user = $r->user();
        \App\Lms\Models\UserBookmark::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $r->lesson_id],
            ['org_id' => $user->org_id]
        );
        return response()->json(['data' => ['bookmarked' => true]]);
    }

    public function bookmarkDelete(\Illuminate\Http\Request $r, $lessonId)
    {
        \App\Lms\Models\UserBookmark::query()
            ->where('user_id', $r->user()->id)
            ->where('lesson_id', $lessonId)
            ->delete();
        return response()->json(['data' => ['bookmarked' => false]]);
    }

    public function noteUpsert(\Illuminate\Http\Request $r, $lessonId)
    {
        $r->validate(['body' => 'required|string']);
        $user = $r->user();
        $note = \App\Lms\Models\UserNote::updateOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lessonId],
            ['org_id' => $user->org_id, 'body' => $r->body]
        );
        return response()->json(['data' => ['lesson_id' => (int) $lessonId, 'body' => $note->body]]);
    }
}
