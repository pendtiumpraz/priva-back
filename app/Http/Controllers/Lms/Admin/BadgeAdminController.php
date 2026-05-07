<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class BadgeAdminController extends Controller
{
    public function index(Request $r)            { return StubResponse::notImplemented('admin.badge.index'); }
    public function store(Request $r)            { return StubResponse::notImplemented('admin.badge.store'); }
    public function show(Request $r, $id)        { return StubResponse::notImplemented('admin.badge.show'); }
    public function update(Request $r, $id)      { return StubResponse::notImplemented('admin.badge.update'); }
    public function destroy(Request $r, $id)     { return StubResponse::notImplemented('admin.badge.destroy'); }
}
