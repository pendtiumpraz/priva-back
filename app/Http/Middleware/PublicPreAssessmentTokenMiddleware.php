<?php

namespace App\Http\Middleware;

use App\Models\VendorPreAssessment;
use App\Services\CurrentOrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sibling of PublicAssessmentTokenMiddleware, but for the TPRM Pre-Assessment
 * (triage) public link (`/api/pra-asesmen-publik/{token}/...`).
 *
 * Reuses the EXACT pattern documented in CLAUDE.md for /asesmen-publik:
 *   1. Rate limit 30 RPM per token+IP — own bucket, separate from the full
 *      assessment middleware so neither starves the other.
 *   2. Resolve token → VendorPreAssessment (withoutGlobalScope since anonymous).
 *   3. Validate exists / not-expired / (for writes) not-consumed → 404 / 410.
 *   4. Set CurrentOrgContext from pre_assessment.org_id so BelongsToOrg +
 *      tenant storage + audit log scope correctly for the anonymous request.
 *   5. Inject the resolved row into `$request->_pre_assessment`.
 *
 * A SEPARATE middleware (not a generalisation of PublicAssessmentTokenMiddleware)
 * was chosen so the existing vendor_assessments public flow + its test suite
 * (PublicAssessmentFlowTest) stay byte-for-byte untouched. The two are
 * intentionally near-identical; if a third tokened resource appears, extract a
 * shared abstract base then.
 */
class PublicPreAssessmentTokenMiddleware
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(
        private CurrentOrgContext $orgContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->route('token');

        $rateKey = 'public-pre-assessment-token:'.sha1($token ?: $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_LIMIT_PER_MINUTE)) {
            $retry = RateLimiter::availableIn($rateKey);

            return response()->json([
                'error' => 'Terlalu banyak permintaan. Silakan coba lagi dalam '.$retry.' detik.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($rateKey, 60);

        if ($token === '' || ! Str::isUuid($token)) {
            return response()->json(['error' => 'Tautan penyaringan tidak valid.'], 404);
        }

        $assessment = VendorPreAssessment::query()
            ->withoutGlobalScope('org')
            ->where('assessment_token', $token)
            ->first();

        if (! $assessment) {
            return response()->json([
                'error' => 'Tautan penyaringan tidak valid. Hubungi pengirim untuk meminta tautan baru.',
            ], 404);
        }

        $expiresAt = $assessment->token_expires_at;
        if ($expiresAt && now()->greaterThan($expiresAt)) {
            return response()->json([
                'error' => 'Tautan penyaringan sudah kedaluwarsa. Mohon hubungi pengirim untuk meminta tautan baru.',
                'expired_at' => $expiresAt->toIso8601String(),
            ], 410);
        }

        // Single-use guard for writes; GET stays allowed (read after submit).
        $isWrite = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($isWrite && $assessment->token_consumed_at !== null) {
            return response()->json([
                'error' => 'Penyaringan sudah dikirim sebelumnya dan tidak dapat diubah.',
                'submitted_at' => optional($assessment->submitted_at)->toIso8601String(),
            ], 410);
        }

        $this->orgContext->set($assessment->org_id);
        $request->merge(['_pre_assessment' => $assessment]);

        return $next($request);
    }
}
