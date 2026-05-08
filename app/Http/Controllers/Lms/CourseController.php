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
    public function show(Request $r, $slug)       { return StubResponse::notImplemented('courses.show'); }
    public function showModule(Request $r, $courseSlug, $moduleSlug) { return StubResponse::notImplemented('courses.module.show'); }
    public function showLesson(Request $r, $courseSlug, $moduleSlug, $lessonSlug) { return StubResponse::notImplemented('courses.lesson.show'); }
    public function examAttempt(Request $r, $courseSlug) { return StubResponse::notImplemented('courses.exam.attempt'); }
}
