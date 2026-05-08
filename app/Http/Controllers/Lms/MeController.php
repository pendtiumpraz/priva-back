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
    public function progress(Request $r)           { return StubResponse::notImplemented('me.progress'); }
    public function completeLesson(Request $r, $id) { return StubResponse::notImplemented('me.lesson.complete'); }
    public function lessonProgress(Request $r, $id) { return StubResponse::notImplemented('me.lesson.progress'); }
    public function bookmarkCreate(Request $r)     { return StubResponse::notImplemented('me.bookmark.create'); }
    public function bookmarkDelete(Request $r, $lessonId) { return StubResponse::notImplemented('me.bookmark.delete'); }
    public function noteUpsert(Request $r, $lessonId) { return StubResponse::notImplemented('me.note.upsert'); }
}
