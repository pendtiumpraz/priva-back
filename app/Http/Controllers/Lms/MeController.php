<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function dashboard(Request $r)         { return StubResponse::notImplemented('me.dashboard'); }
    public function courses(Request $r)            { return StubResponse::notImplemented('me.courses'); }
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
