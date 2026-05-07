<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class ModuleAdminController extends Controller
{
    public function index(Request $r)            { return StubResponse::notImplemented('admin.module.index'); }
    public function store(Request $r)            { return StubResponse::notImplemented('admin.module.store'); }
    public function show(Request $r, $id)        { return StubResponse::notImplemented('admin.module.show'); }
    public function update(Request $r, $id)      { return StubResponse::notImplemented('admin.module.update'); }
    public function destroy(Request $r, $id)     { return StubResponse::notImplemented('admin.module.destroy'); }
}
