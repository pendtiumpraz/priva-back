<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class VideoAdminController extends Controller
{
    public function store(Request $r) { return StubResponse::notImplemented('admin.video.store'); }
}
