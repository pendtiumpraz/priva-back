<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function show(Request $r, $id)            { return StubResponse::notImplemented('quizzes.show'); }
    public function attempt(Request $r, $id)         { return StubResponse::notImplemented('quizzes.attempt'); }
    public function findByOwner(Request $r)          { return StubResponse::notImplemented('quizzes.find'); }
}
