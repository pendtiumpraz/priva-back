<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $r) { return StubResponse::notImplemented('leaderboard.index'); }
}
