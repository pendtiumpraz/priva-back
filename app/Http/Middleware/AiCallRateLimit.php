<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-user rate limit untuk endpoint AI.
 *
 * Mencegah single user (authenticated) spam request AI — baik sengaja
 * (DoS / drain quota tenant) maupun karena bug frontend (infinite retry).
 *
 * Limit dibaca dari config('security.ai.max_calls_per_minute_per_user')
 * yang di-hidrasi DB. Default 20/menit — cukup longgar untuk user normal
 * (1 request tiap 3 detik), ketat untuk script attack.
 *
 * Layer ini berbeda dari throttle:api Laravel (global per-IP) dan dari
 * security.tenant_rate_limit (per-org). Khusus AI karena cost per request
 * jauh lebih tinggi dari endpoint CRUD biasa.
 *
 * Bucket key = 'ai_call:<user_id>'. Anonymous request di-skip (tidak
 * mungkin sampai sini karena AI route di-gate auth:sanctum, tapi defensif).
 */
class AiCallRateLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $maxPerMinute = (int) config('security.ai.max_calls_per_minute_per_user', 20);
        if ($maxPerMinute <= 0) {
            // 0 = disabled. Admin bisa matikan dari UI bila perlu.
            return $next($request);
        }

        $key = 'ai_call:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, $maxPerMinute)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'message' => sprintf(
                    'Terlalu banyak permintaan AI. Maksimal %d per menit. Silakan coba lagi dalam %d detik.',
                    $maxPerMinute,
                    $retryAfter,
                ),
                'code' => 'ai_rate_limit_exceeded',
                'max_per_minute' => $maxPerMinute,
                'retry_after_seconds' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }
}
