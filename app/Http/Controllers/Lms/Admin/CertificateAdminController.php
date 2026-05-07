<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class CertificateAdminController extends Controller
{
    public function revoke(Request $r, $id) { return StubResponse::notImplemented('admin.certificate.revoke'); }
}
