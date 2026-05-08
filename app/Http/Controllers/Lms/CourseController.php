<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
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

        return response()->json(['data' => $courses]);
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

        $modules = $course->modules()->orderBy('order')->get(['id', 'slug', 'title', 'description', 'order', 'unlock_after_module_id']);
        $data = $course->toArray();
        $data['modules'] = $modules;

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

        $module = $course->modules()->where('slug', $moduleSlug)->first();
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

        $lessons = $module->lessons()->orderBy('order')->get(['id', 'slug', 'title', 'order', 'duration_seconds', 'video_id']);
        $data = $module->toArray();
        $data['lessons'] = $lessons;

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

        $module = $course->modules()->where('slug', $moduleSlug)->first();
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

        $lesson = $module->lessons()->where('slug', $lessonSlug)->first();
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

        $data = $lesson->toArray();
        $data['watched_seconds'] = $userProgress->watched_seconds ?? 0;
        $data['completed_at'] = $userProgress->completed_at ?? null;

        return response()->json(['data' => $data]);
    }
    public function examAttempt(Request $r, $courseSlug) { return StubResponse::notImplemented('courses.exam.attempt'); }
}
