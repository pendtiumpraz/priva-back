<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class QuizQuestionAdminController extends Controller
{
    public function index(Request $r)            { return StubResponse::notImplemented('admin.quiz_question.index'); }
    public function store(Request $r)            { return StubResponse::notImplemented('admin.quiz_question.store'); }
    public function show(Request $r, $id)        { return StubResponse::notImplemented('admin.quiz_question.show'); }
    public function update(Request $r, $id)      { return StubResponse::notImplemented('admin.quiz_question.update'); }
    public function destroy(Request $r, $id)     { return StubResponse::notImplemented('admin.quiz_question.destroy'); }
}
