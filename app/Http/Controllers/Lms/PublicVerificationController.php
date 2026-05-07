<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use App\Lms\Http\StubResponse;
use Illuminate\Http\Request;

class PublicVerificationController extends Controller
{
    public function verify(Request $r, string $certificateNumber)
    {
        return StubResponse::notImplemented('public.verify');
    }
}
