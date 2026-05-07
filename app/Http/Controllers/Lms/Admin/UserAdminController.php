<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class UserAdminController extends Controller
{
    public function index(Request $r) { return StubResponse::notImplemented('admin.user.index'); }
}
