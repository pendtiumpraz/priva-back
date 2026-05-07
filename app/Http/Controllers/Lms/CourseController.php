<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function index(Request $r)             { return StubResponse::notImplemented('courses.index'); }
    public function show(Request $r, $slug)       { return StubResponse::notImplemented('courses.show'); }
    public function showModule(Request $r, $courseSlug, $moduleSlug) { return StubResponse::notImplemented('courses.module.show'); }
    public function showLesson(Request $r, $courseSlug, $moduleSlug, $lessonSlug) { return StubResponse::notImplemented('courses.lesson.show'); }
    public function examAttempt(Request $r, $courseSlug) { return StubResponse::notImplemented('courses.exam.attempt'); }
}
