<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class CourseAdminController extends Controller
{
    public function index(Request $r)            { return StubResponse::notImplemented('admin.course.index'); }
    public function store(Request $r)            { return StubResponse::notImplemented('admin.course.store'); }
    public function show(Request $r, $id)        { return StubResponse::notImplemented('admin.course.show'); }
    public function update(Request $r, $id)      { return StubResponse::notImplemented('admin.course.update'); }
    public function destroy(Request $r, $id)     { return StubResponse::notImplemented('admin.course.destroy'); }
}
