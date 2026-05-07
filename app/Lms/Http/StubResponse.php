<?php

namespace App\Lms\Http;

use Illuminate\Http\JsonResponse;

class StubResponse
{
    public static function notImplemented(string $method): JsonResponse
    {
        return response()->json([
            'message' => 'LMS endpoint not implemented yet — Foundation phase only.',
            'code' => 'LMS_FOUNDATION_STUB',
            'method' => $method,
        ], 501);
    }
}
