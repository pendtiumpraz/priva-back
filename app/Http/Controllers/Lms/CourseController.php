<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index(Request $r)
    {
        $user = $r->user();
        $courses = \App\Lms\Models\Course::query()
            ->where('published', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })
            ->orderBy('order')
            ->get(['id', 'slug', 'title', 'description', 'level', 'duration_minutes', 'thumbnail_url', 'regulation_code', 'order']);

        if ($courses->isEmpty()) {
            return response()->json(['data' => []]);
        }

        // Attach published-lesson count + the user's completion percent per course
        // (2 grouped queries, no N+1). Drives the catalog "Sedang Berjalan /
        // Selesai" filters and the home "N lesson" stat — both showed 0 before.
        $courseIds = $courses->pluck('id');

        $lessonTotals = \DB::table('lms_lessons as l')
            ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
            ->whereIn('m.course_id', $courseIds)
            ->where('m.published', true)->where('l.published', true)
            ->groupBy('m.course_id')
            ->selectRaw('m.course_id as course_id, COUNT(l.id) as cnt')
            ->pluck('cnt', 'course_id');

        $lessonDone = \DB::table('lms_user_lesson_progress as p')
            ->join('lms_lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
            ->whereIn('m.course_id', $courseIds)
            ->where('m.published', true)->where('l.published', true)
            ->where('p.user_id', $user->id)
            ->whereNotNull('p.completed_at')
            ->groupBy('m.course_id')
            ->selectRaw('m.course_id as course_id, COUNT(DISTINCT l.id) as cnt')
            ->pluck('cnt', 'course_id');

        $data = $courses->map(function ($c) use ($lessonTotals, $lessonDone) {
            $total = (int) ($lessonTotals[$c->id] ?? 0);
            $done = (int) ($lessonDone[$c->id] ?? 0);
            $arr = $c->toArray();
            $arr['lessons_count'] = $total;
            $arr['progress'] = $total > 0 ? (int) round(($done / $total) * 100) : 0;
            return $arr;
        });

        return response()->json(['data' => $data]);
    }
    public function show(Request $r, $slug)
    {
        $user = $r->user();
        $course = \App\Lms\Models\Course::query()
            ->where('slug', $slug)->where('published', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })
            ->first();
        if (! $course) return response()->json(['message' => 'Course not found.'], 404);

        // Filter draft modules on learner side. Admin endpoints expose drafts;
        // learners only see published content.
        $modules = $course->modules()->published()->orderBy('order')->get(['id', 'slug', 'title', 'description', 'order', 'unlock_after_module_id']);
        $moduleIds = $modules->pluck('id');

        // Per-module published-lesson totals + the user's completed count, plus
        // lock state — so the course-detail page shows real module progress
        // ("Selesai / Sedang Berjalan / Terkunci") instead of 0% everywhere.
        $lessonTotals = \DB::table('lms_lessons')
            ->whereIn('module_id', $moduleIds)->where('published', true)
            ->groupBy('module_id')
            ->selectRaw('module_id, COUNT(*) as cnt')
            ->pluck('cnt', 'module_id');

        $lessonDone = \DB::table('lms_user_lesson_progress as p')
            ->join('lms_lessons as l', 'l.id', '=', 'p.lesson_id')
            ->whereIn('l.module_id', $moduleIds)->where('l.published', true)
            ->where('p.user_id', $user->id)->whereNotNull('p.completed_at')
            ->groupBy('l.module_id')
            ->selectRaw('l.module_id as module_id, COUNT(DISTINCT l.id) as cnt')
            ->pluck('cnt', 'module_id');

        $completedModuleIds = \App\Lms\Models\UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('module_id', $moduleIds)
            ->where('status', 'completed')
            ->pluck('module_id')->all();

        $modulesData = $modules->map(function ($m) use ($lessonTotals, $lessonDone, $completedModuleIds) {
            $total = (int) ($lessonTotals[$m->id] ?? 0);
            $done = (int) ($lessonDone[$m->id] ?? 0);
            return [
                'id' => $m->id,
                'slug' => $m->slug,
                'title' => $m->title,
                'description' => $m->description,
                'order' => $m->order,
                'unlock_after_module_id' => $m->unlock_after_module_id,
                'lessons_count' => $total,
                'progress' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
                'locked' => $m->unlock_after_module_id
                    ? ! in_array($m->unlock_after_module_id, $completedModuleIds, true)
                    : false,
            ];
        });

        $hasExam = \App\Lms\Models\Quiz::query()
            ->where('owner_type', 'course')->where('owner_key', (string) $course->id)->exists();

        $data = [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'description' => $course->description,
            'level' => $course->level,
            'duration_minutes' => $course->duration_minutes,
            'thumbnail_url' => $course->thumbnail_url,
            'regulation_code' => $course->regulation_code,
            'published' => $course->published,
            'order' => $course->order,
            'modules' => $modulesData,
            'modules_count' => $modules->count(),
            'lessons_count' => (int) $lessonTotals->sum(),
            'has_exam' => $hasExam,
        ];

        return response()->json(['data' => $data]);
    }
    public function showModule(Request $r, $courseSlug, $moduleSlug)
    {
        $user = $r->user();
        $course = \App\Lms\Models\Course::query()
            ->where('slug', $courseSlug)->where('published', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->first();
        if (! $course) return response()->json(['message' => 'Course not found.'], 404);

        // Drafts are invisible to learners — return 404 for unpublished modules.
        $module = $course->modules()->published()->where('slug', $moduleSlug)->first();
        if (! $module) return response()->json(['message' => 'Module not found.'], 404);

        if ($module->unlock_after_module_id) {
            $prevDone = \App\Lms\Models\UserModuleProgress::query()
                ->where('user_id', $user->id)
                ->where('module_id', $module->unlock_after_module_id)
                ->where('status', 'completed')->exists();
            if (! $prevDone) {
                return response()->json(['message' => 'Complete the previous module first.', 'code' => 'LMS_LOCKED'], 403);
            }
        }

        $lessons = $module->lessons()->published()->orderBy('order')->get(['id', 'slug', 'title', 'order', 'duration_seconds', 'video_id']);

        // Attach the user's per-lesson completion/watch state so the module view
        // reflects real status instead of an order-based heuristic.
        $progressRows = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->get()->keyBy('lesson_id');

        $lessonsData = $lessons->map(function ($l) use ($progressRows) {
            $p = $progressRows->get($l->id);
            $completed = $p && $p->completed_at !== null;
            return [
                'id' => $l->id,
                'slug' => $l->slug,
                'title' => $l->title,
                'order' => $l->order,
                'duration_seconds' => $l->duration_seconds,
                'video_id' => $l->video_id,
                'completed' => $completed,
                'watched_seconds' => $p->watched_seconds ?? 0,
                'status' => $completed ? 'done' : (($p && $p->watched_seconds > 0) ? 'in_progress' : 'todo'),
            ];
        });

        $data = [
            'id' => $module->id,
            'slug' => $module->slug,
            'title' => $module->title,
            'description' => $module->description,
            'order' => $module->order,
            'unlock_after_module_id' => $module->unlock_after_module_id,
            'lessons' => $lessonsData,
        ];

        return response()->json(['data' => $data]);
    }
    public function showLesson(Request $r, $courseSlug, $moduleSlug, $lessonSlug)
    {
        $user = $r->user();

        $course = \App\Lms\Models\Course::query()
            ->where('slug', $courseSlug)->where('published', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->first();
        if (! $course) return response()->json(['message' => 'Course not found.'], 404);

        // Drafts are invisible to learners — return 404 for unpublished modules.
        $module = $course->modules()->published()->where('slug', $moduleSlug)->first();
        if (! $module) return response()->json(['message' => 'Module not found.'], 404);

        if ($module->unlock_after_module_id) {
            $prev = \App\Lms\Models\UserModuleProgress::query()
                ->where('user_id', $user->id)
                ->where('module_id', $module->unlock_after_module_id)
                ->where('status', 'completed')->exists();
            if (! $prev) {
                return response()->json(['message' => 'Module locked.', 'code' => 'LMS_LOCKED'], 403);
            }
        }

        // Drafts are invisible to learners — return 404 for unpublished lessons.
        $lesson = $module->lessons()->published()->where('slug', $lessonSlug)->first();
        if (! $lesson) return response()->json(['message' => 'Lesson not found.'], 404);

        if ($lesson->order > 1) {
            $earlierIds = $module->lessons()->where('order', '<', $lesson->order)->pluck('id');
            $completedCount = \App\Lms\Models\UserLessonProgress::query()
                ->where('user_id', $user->id)
                ->whereIn('lesson_id', $earlierIds)
                ->whereNotNull('completed_at')
                ->count();
            if ($completedCount < $earlierIds->count()) {
                return response()->json(['message' => 'Lesson locked — finish previous lesson.', 'code' => 'LMS_LOCKED'], 403);
            }
        }

        $userProgress = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        $bookmarked = \App\Lms\Models\UserBookmark::query()
            ->where('user_id', $user->id)->where('lesson_id', $lesson->id)->exists();

        $userNote = \App\Lms\Models\UserNote::query()
            ->where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        $data = [
            'id' => $lesson->id,
            'slug' => $lesson->slug,
            'title' => $lesson->title,
            'body' => $lesson->body,
            'order' => $lesson->order,
            'duration_seconds' => $lesson->duration_seconds,
            'video_id' => $lesson->video_id,
            // Serialize the linked video so the player can bind to a Mux
            // playback id or a YouTube id. null when the lesson has no video.
            // `id` lets the player request a signed playback token; for
            // playback_policy 'signed' (Mux) the FE must fetch a JWT from
            // /api/lms/videos/{id}/playback-token before playing.
            'video' => $lesson->video ? [
                'id' => $lesson->video->id,
                'source' => $lesson->video->source,
                'external_id' => $lesson->video->external_id,
                'playback_policy' => $lesson->video->playback_policy,
                'duration_seconds' => $lesson->video->duration_seconds,
            ] : null,
            'steps' => $lesson->steps,
            'tips' => $lesson->tips,
            'tags' => $lesson->tags,
            'watched_seconds' => $userProgress->watched_seconds ?? 0,
            'completed_at' => $userProgress->completed_at ?? null,
            'bookmarked' => $bookmarked,
            'note_body' => $userNote->body ?? null,
        ];

        return response()->json(['data' => $data]);
    }
    public function examAttempt(Request $r, $courseSlug)
    {
        $r->validate(['answers' => 'required|array']);
        $user = $r->user();

        $course = \App\Lms\Models\Course::query()
            ->where('slug', $courseSlug)->where('published', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->first();
        if (! $course) return response()->json(['message' => 'Course not found.'], 404);

        // Enforce: all published modules of the course must be completed before
        // exam is unlocked. Draft modules are invisible to learners and must not
        // gate exam access.
        $allModuleIds = $course->modules()->published()->pluck('id');
        if ($allModuleIds->isNotEmpty()) {
            $completedCount = \App\Lms\Models\UserModuleProgress::query()
                ->where('user_id', $user->id)
                ->whereIn('module_id', $allModuleIds)
                ->where('status', 'completed')
                ->count();
            if ($completedCount < $allModuleIds->count()) {
                return response()->json([
                    'message' => 'Selesaikan semua modul terlebih dahulu.',
                    'code' => 'LMS_EXAM_LOCKED',
                ], 403);
            }
        }

        $exam = \App\Lms\Models\Quiz::query()
            ->where('owner_type', 'course')
            ->where('owner_key', (string) $course->id)
            ->first();
        if (! $exam) return response()->json(['message' => 'Exam not configured.'], 404);

        $request = \Illuminate\Http\Request::create("/api/lms/quizzes/{$exam->id}/attempts", 'POST', ['answers' => $r->input('answers')]);
        $request->setUserResolver(fn () => $user);

        return app(\App\Http\Controllers\Lms\QuizController::class)->attempt($request, $exam->id);
    }
}
