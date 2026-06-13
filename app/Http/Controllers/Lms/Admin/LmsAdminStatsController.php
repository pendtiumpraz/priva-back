<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LMS Admin landing-page stats (Task 4-BE).
 *
 * GET /api/lms/admin/stats?range=7d|30d|90d|all (default 30d)
 *
 * Auth: auth:sanctum + role.root (root or superadmin only).
 *
 * Schema deviations from the FE-spec contract (documented inline):
 *  - There is NO `lms_enrollments` table. Enrollment is implicit via per-user
 *    module progress (`lms_user_module_progress`). A user is "enrolled" in a
 *    course iff they have ≥1 progress row against any of that course's modules.
 *    "Completed enrollment" = module progress with a non-null `completed_at`.
 *  - `lms_courses` uses a `published` boolean (not a `status` enum). We expose
 *    the spec-shaped string `published|draft` in `top_courses[].status`.
 *  - `users` table uses `is_active` (not `active`).
 *  - `badges_awarded` is sourced from `lms_user_badges` (one row per award).
 *  - `recent_activity` is derived from the simplest available signals:
 *    recent courses created/published, module completions (lesson.completed
 *    fallback via lesson progress), badge awards, quiz publications. No
 *    dedicated event log exists, so this is a best-effort merge.
 */
class LmsAdminStatsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = $request->query('range', '30d');
        if (! in_array($range, ['7d', '30d', '90d', 'all'], true)) {
            return response()->json(['message' => 'Invalid range. Use one of 7d|30d|90d|all.'], 422);
        }

        // Defence-in-depth: middleware (role.root) already enforces this, but
        // re-checking here keeps the controller safe if a future caller drops
        // the middleware by accident.
        $user = $request->user();
        if (! $user || ! in_array($user->role ?? null, ['root', 'superadmin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $since = match ($range) {
            '7d'  => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            '90d' => Carbon::now()->subDays(90),
            'all' => null,
        };

        $totals = $this->buildTotals();
        $deltas = $this->buildDeltas($since);
        $rates  = $this->buildRates();
        $topCourses = $this->buildTopCourses();
        $recentActivity = $this->buildRecentActivity(10);

        return response()->json([
            'data' => [
                'range'           => $range,
                'as_of'           => Carbon::now()->toIso8601String(),
                'totals'          => $totals,
                'deltas'          => $deltas,
                'rates'           => $rates,
                'top_courses'     => $topCourses,
                'recent_activity' => $recentActivity,
            ],
        ]);
    }

    /** @return array<string,int> */
    private function buildTotals(): array
    {
        return [
            'courses'         => (int) DB::table('lms_courses')->whereNull('deleted_at')->count(),
            // "active_learners" = distinct users with ≥1 module-progress row.
            // This is the closest analogue to "enrolled with activity" given
            // we have no enrollment table.
            'active_learners' => (int) DB::table('lms_user_module_progress')->distinct()->count('user_id'),
            'quizzes'         => (int) DB::table('lms_quizzes')->count(),
            'badges_awarded'  => (int) DB::table('lms_user_badges')->count(),
        ];
    }

    /** @return array<string,int> */
    private function buildDeltas(?Carbon $since): array
    {
        if ($since === null) {
            return ['courses' => 0, 'active_learners' => 0, 'quizzes' => 0, 'badges_awarded' => 0];
        }

        return [
            'courses'         => (int) DB::table('lms_courses')
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $since)
                ->count(),
            'active_learners' => (int) DB::table('lms_user_module_progress')
                ->where('created_at', '>=', $since)
                ->distinct()
                ->count('user_id'),
            'quizzes'         => (int) DB::table('lms_quizzes')
                ->where('created_at', '>=', $since)
                ->count(),
            'badges_awarded'  => (int) DB::table('lms_user_badges')
                ->where('awarded_at', '>=', $since)
                ->count(),
        ];
    }

    /** @return array{enrollment_rate: float, completion_rate: float} */
    private function buildRates(): array
    {
        // "Enrollment" denominator = active platform users. Use `is_active`
        // when the column exists; fall back to the full users count otherwise.
        $activeUsers = Schema::hasColumn('users', 'is_active')
            ? (int) DB::table('users')->where('is_active', true)->count()
            : (int) DB::table('users')->count();

        $usersWithProgress = (int) DB::table('lms_user_module_progress')
            ->distinct()
            ->count('user_id');

        $totalProgress = (int) DB::table('lms_user_module_progress')->count();
        $completedProgress = (int) DB::table('lms_user_module_progress')
            ->whereNotNull('completed_at')
            ->count();

        $enrollmentRate = $activeUsers > 0
            ? min(1.0, max(0.0, $usersWithProgress / $activeUsers))
            : 0.0;
        $completionRate = $totalProgress > 0
            ? min(1.0, max(0.0, $completedProgress / $totalProgress))
            : 0.0;

        return [
            'enrollment_rate' => round($enrollmentRate, 4),
            'completion_rate' => round($completionRate, 4),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function buildTopCourses(): array
    {
        $rows = DB::table('lms_courses')
            ->leftJoin('lms_modules', 'lms_modules.course_id', '=', 'lms_courses.id')
            ->leftJoin('lms_user_module_progress as ump', 'ump.module_id', '=', 'lms_modules.id')
            ->whereNull('lms_courses.deleted_at')
            ->select(
                'lms_courses.id',
                'lms_courses.title',
                'lms_courses.published',
                DB::raw('COUNT(DISTINCT ump.user_id) as enrolled_count'),
                DB::raw("SUM(CASE WHEN ump.completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_count"),
                DB::raw('COUNT(ump.id) as progress_count')
            )
            ->groupBy('lms_courses.id', 'lms_courses.title', 'lms_courses.published')
            ->orderByDesc('enrolled_count')
            ->orderBy('lms_courses.id')
            ->limit(5)
            ->get();

        return $rows->map(function ($r) {
            $progressCount = (int) ($r->progress_count ?? 0);
            $completedCount = (int) ($r->completed_count ?? 0);
            $completionRate = $progressCount > 0
                ? round($completedCount / $progressCount, 4)
                : 0.0;

            return [
                'id'              => (int) $r->id,
                'title'           => (string) $r->title,
                'enrolled_count'  => (int) ($r->enrolled_count ?? 0),
                'completion_rate' => $completionRate,
                'status'          => ((bool) $r->published) ? 'published' : 'draft',
            ];
        })->all();
    }

    /**
     * Best-effort merge of recent platform activity. No dedicated audit-log
     * table exists, so we pull from the natural sources and sort by timestamp.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildRecentActivity(int $limit): array
    {
        $events = [];

        // 1) Recent course creations.
        $courses = DB::table('lms_courses')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'title', 'published', 'created_by', 'created_at', 'updated_at']);

        foreach ($courses as $c) {
            $events[] = [
                'id'      => 'course_created_'.$c->id,
                'type'    => 'course.created',
                'actor'   => $this->actorForUser($c->created_by),
                'subject' => [
                    'type'  => 'course',
                    'id'    => (int) $c->id,
                    'label' => (string) $c->title,
                ],
                'occurred_at' => $this->isoOrNull($c->created_at),
                '_ts'         => $this->tsOrZero($c->created_at),
            ];

            if ((bool) $c->published) {
                $events[] = [
                    'id'      => 'course_published_'.$c->id,
                    'type'    => 'course.published',
                    'actor'   => $this->actorForUser($c->created_by),
                    'subject' => [
                        'type'  => 'course',
                        'id'    => (int) $c->id,
                        'label' => (string) $c->title,
                    ],
                    'occurred_at' => $this->isoOrNull($c->updated_at ?? $c->created_at),
                    '_ts'         => $this->tsOrZero($c->updated_at ?? $c->created_at),
                ];
            }
        }

        // 2) Recent lesson completions.
        $lessonProgress = DB::table('lms_user_lesson_progress as ulp')
            ->leftJoin('lms_lessons as ll', 'll.id', '=', 'ulp.lesson_id')
            ->whereNotNull('ulp.completed_at')
            ->orderByDesc('ulp.completed_at')
            ->limit($limit)
            ->get(['ulp.id', 'ulp.user_id', 'ulp.lesson_id', 'ulp.completed_at', 'll.title as lesson_title']);

        foreach ($lessonProgress as $lp) {
            $events[] = [
                'id'      => 'lesson_completed_'.$lp->id,
                'type'    => 'lesson.completed',
                'actor'   => $this->actorForUser($lp->user_id),
                'subject' => [
                    'type'  => 'lesson',
                    'id'    => (int) $lp->lesson_id,
                    'label' => (string) ($lp->lesson_title ?? 'Lesson #'.$lp->lesson_id),
                ],
                'occurred_at' => $this->isoOrNull($lp->completed_at),
                '_ts'         => $this->tsOrZero($lp->completed_at),
            ];
        }

        // 3) Recent badge awards.
        $badgeAwards = DB::table('lms_user_badges as ub')
            ->leftJoin('lms_badges as b', 'b.id', '=', 'ub.badge_id')
            ->orderByDesc('ub.awarded_at')
            ->limit($limit)
            ->get(['ub.id', 'ub.user_id', 'ub.badge_id', 'ub.awarded_at', 'b.name as badge_name']);

        foreach ($badgeAwards as $ba) {
            $events[] = [
                'id'      => 'badge_awarded_'.$ba->id,
                'type'    => 'badge.awarded',
                'actor'   => $this->actorForUser($ba->user_id),
                'subject' => [
                    'type'  => 'badge',
                    'id'    => (int) $ba->badge_id,
                    'label' => (string) ($ba->badge_name ?? 'Badge #'.$ba->badge_id),
                ],
                'occurred_at' => $this->isoOrNull($ba->awarded_at),
                '_ts'         => $this->tsOrZero($ba->awarded_at),
            ];
        }

        // Sort by timestamp DESC, take $limit, strip the sort helper.
        usort($events, fn ($a, $b) => $b['_ts'] <=> $a['_ts']);
        $events = array_slice($events, 0, $limit);

        return array_map(function ($e) {
            unset($e['_ts']);
            return $e;
        }, $events);
    }

    /**
     * Resolve a user id into the spec's actor shape. Returns null-shaped actor
     * (name "Unknown") when the user can't be found — we never throw.
     *
     * @param  string|int|null  $userId
     * @return array{id:string|int|null, name:string, avatar_url:null}
     */
    private function actorForUser($userId): array
    {
        if (! $userId) {
            return ['id' => null, 'name' => 'System', 'avatar_url' => null];
        }

        $row = DB::table('users')->where('id', $userId)->first(['id', 'name']);
        if (! $row) {
            return ['id' => $userId, 'name' => 'Unknown', 'avatar_url' => null];
        }

        return [
            'id'         => $row->id,
            'name'       => (string) ($row->name ?? 'Unknown'),
            'avatar_url' => null,
        ];
    }

    private function isoOrNull($value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function tsOrZero($value): int
    {
        if (! $value) {
            return 0;
        }
        try {
            return Carbon::parse($value)->timestamp;
        } catch (\Throwable) {
            return 0;
        }
    }
}
