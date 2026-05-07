<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class QuizAdminController extends Controller
{
    public function index(Request $r)            { return StubResponse::notImplemented('admin.quiz.index'); }
    public function store(Request $r)            { return StubResponse::notImplemented('admin.quiz.store'); }
    public function show(Request $r, $id)        { return StubResponse::notImplemented('admin.quiz.show'); }
    public function update(Request $r, $id)      { return StubResponse::notImplemented('admin.quiz.update'); }
    public function destroy(Request $r, $id)     { return StubResponse::notImplemented('admin.quiz.destroy'); }
}
