<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class LessonAdminController extends Controller
{
    public function index(Request $r)            { return StubResponse::notImplemented('admin.lesson.index'); }
    public function store(Request $r)            { return StubResponse::notImplemented('admin.lesson.store'); }
    public function show(Request $r, $id)        { return StubResponse::notImplemented('admin.lesson.show'); }
    public function update(Request $r, $id)      { return StubResponse::notImplemented('admin.lesson.update'); }
    public function destroy(Request $r, $id)     { return StubResponse::notImplemented('admin.lesson.destroy'); }
}
