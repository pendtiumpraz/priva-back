<?php

namespace App\Http\Middleware;

use App\Models\HoldingAssessmentInstance;
use App\Services\CurrentOrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Gerbang akses URL publik Holding Compliance Assessment
 * (`/api/asesmen-holding/{token}/...`). Sibling dari PublicAssessmentTokenMiddleware
 * (TPRM) — pola identik, model berbeda (HoldingAssessmentInstance).
 *
 * Tanggung jawab:
 *   1. Resolve token (UUID) → instance (withoutGlobalScope karena anonim).
 *   2. Validasi exists / expiry (410) / single-use guard untuk write (410).
 *   3. Set CurrentOrgContext = instance.org_id (org HOLDING pemilik) supaya
 *      TenantStorageService + AuditLog + model BelongsToOrg ter-scope benar.
 *   4. Inject instance ke `$request->_instance`.
 *   5. Rate limit 30 RPM per token (bucket terpisah dari TPRM).
 */
class PublicHoldingAssessmentTokenMiddleware
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(private CurrentOrgContext $orgContext) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->route('token');

        $rateKey = 'public-holding-assessment-token:'.sha1($token ?: $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_LIMIT_PER_MINUTE)) {
            $retry = RateLimiter::availableIn($rateKey);

            return response()->json([
                'error' => 'Terlalu banyak permintaan. Silakan coba lagi dalam '.$retry.' detik.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($rateKey, 60);

        if ($token === '' || ! Str::isUuid($token)) {
            return response()->json(['error' => 'Tautan asesmen tidak valid.'], 404);
        }

        $instance = HoldingAssessmentInstance::query()
            ->withoutGlobalScope('org')
            ->where('assessment_token', $token)
            ->first();

        if (! $instance) {
            return response()->json([
                'error' => 'Tautan asesmen tidak valid. Hubungi pengirim untuk meminta tautan baru.',
            ], 404);
        }

        $expiresAt = $instance->token_expires_at;
        if ($expiresAt && now()->greaterThan($expiresAt)) {
            return response()->json([
                'error' => 'Tautan asesmen sudah kedaluwarsa. Mohon hubungi pengirim untuk tautan baru.',
                'expired_at' => $expiresAt->toIso8601String(),
            ], 410);
        }

        $isWrite = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($isWrite && $instance->token_consumed_at !== null) {
            return response()->json([
                'error' => 'Asesmen sudah dikirim sebelumnya dan tidak dapat diubah.',
                'result_url' => url('/api/asesmen-holding/'.$token.'/result'),
                'submitted_at' => optional($instance->submitted_at)->toIso8601String(),
            ], 410);
        }

        $this->orgContext->set($instance->org_id);
        $request->merge(['_instance' => $instance]);

        return $next($request);
    }
}
