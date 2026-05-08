<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function dashboard(Request $r)
    {
        $user = $r->user();

        $continue = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->where('org_id', $user->org_id)
            ->whereNull('completed_at')
            ->orderByDesc('updated_at')
            ->first();

        $coursesTotal = \App\Lms\Models\Course::query()
            ->where('published', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })
            ->count();

        return response()->json(['data' => [
            'continue_learning' => $continue ? [
                'lesson_id' => $continue->lesson_id,
                'watched_seconds' => $continue->watched_seconds,
            ] : null,
            'courses_total' => $coursesTotal,
            'courses_completed' => 0,
        ]]);
    }

    public function courses(\Illuminate\Http\Request $r)
    {
        $user = $r->user();

        // "My courses" = courses where the user has any lesson progress.
        // (No progress yet → empty. This is intentional; full catalog lives at GET /api/lms/courses.)
        $courseIds = \DB::table('lms_user_lesson_progress as p')
            ->join('lms_lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('lms_modules as m', 'm.id', '=', 'l.module_id')
            ->where('p.user_id', $user->id)
            ->where('p.org_id', $user->org_id)
            ->distinct()
            ->pluck('m.course_id');

        if ($courseIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $courses = \App\Lms\Models\Course::query()
            ->whereIn('id', $courseIds)
            ->where('published', true)
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })
            ->orderBy('order')
            ->get(['id', 'slug', 'title', 'description', 'level', 'duration_minutes', 'thumbnail_url', 'regulation_code', 'order']);

        return response()->json(['data' => $courses]);
    }
    public function badges(Request $r)             { return StubResponse::notImplemented('me.badges'); }
    public function bookmarks(Request $r)          { return StubResponse::notImplemented('me.bookmarks'); }
    public function notes(Request $r)              { return StubResponse::notImplemented('me.notes'); }

    public function progress(\Illuminate\Http\Request $r)
    {
        $user = $r->user();

        $lessonsCompleted = \App\Lms\Models\UserLessonProgress::query()
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')->count();

        $modulesCompleted = \App\Lms\Models\UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->where('status', 'completed')->count();

        $coursesCompleted = \DB::table('lms_modules as m')
            ->whereIn('m.course_id', function ($q) use ($user) {
                $q->select('m2.course_id')->from('lms_modules as m2')
                  ->leftJoin('lms_user_module_progress as p', function ($j) use ($user) {
                      $j->on('p.module_id', '=', 'm2.id')
                        ->where('p.user_id', '=', $user->id)
                        ->where('p.status', '=', 'completed');
                  })
                  ->groupBy('m2.course_id')
                  ->havingRaw('COUNT(m2.id) = COUNT(p.id)');
            })
            ->distinct()->count('m.course_id');

        return response()->json(['data' => [
            'lessons_completed' => $lessonsCompleted,
            'modules_completed' => $modulesCompleted,
            'courses_completed' => $coursesCompleted,
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

        $lessonIds = $module->lessons()->pluck('id');
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
        $allModuleIds = $course->modules()->pluck('id');
        $completedModules = \App\Lms\Models\UserModuleProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('module_id', $allModuleIds)
            ->where('status', 'completed')
            ->count();
        if ($completedModules >= $allModuleIds->count()) {
            app(\App\Lms\Services\XpAwardService::class)->award($user, 'course.completed', 'course', (string) $course->id);
        }
    }

    public function bookmarkCreate(Request $r)     { return StubResponse::notImplemented('me.bookmark.create'); }
    public function bookmarkDelete(Request $r, $lessonId) { return StubResponse::notImplemented('me.bookmark.delete'); }
    public function noteUpsert(Request $r, $lessonId) { return StubResponse::notImplemented('me.note.upsert'); }
}
