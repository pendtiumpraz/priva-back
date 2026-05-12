<?php

namespace App\Http\Middleware;

use App\Services\AssessmentTokenService;
use App\Services\CurrentOrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gerbang akses untuk URL publik vendor assessment (`/api/asesmen-publik/{token}/...`).
 *
 * Tanggung jawab:
 *   1. Resolve token dari segmen route, validasi format UUID.
 *   2. Cek token exists, belum expired. Sumbat dengan status code yang tepat
 *      (404 invalid, 410 expired, 410 already-consumed untuk write endpoints).
 *   3. Hidrasi CurrentOrgContext → org_id dari assessment, supaya trait
 *      BelongsToOrg pada model anak (mis. file upload metadata, audit log)
 *      tetap auto-scope ke tenant yang benar walau request anonim.
 *   4. Inject assessment ke request supaya controller cukup baca
 *      `$request->get('_assessment')` tanpa query ulang.
 *   5. Rate limit 30 RPM per token — mencegah brute-force / scraping.
 *
 * Catatan single-use guard: GET /result diizinkan setelah consumed
 * (read-only result page). Write endpoints (POST jawaban/upload/submit)
 * ditolak setelah consumed. Pemilihan ini berdasarkan method HTTP, bukan
 * path string — supaya rename route di kemudian hari tidak break logic.
 */
class PublicAssessmentTokenMiddleware
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(
        private AssessmentTokenService $tokens,
        private CurrentOrgContext $orgContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->route('token');

        // Rate limit dulu sebelum DB hit — kalau attacker spam token random,
        // gak boleh sampai bikin query ke DB tiap request.
        $rateKey = 'public-assessment-token:' . sha1($token ?: $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_LIMIT_PER_MINUTE)) {
            $retry = RateLimiter::availableIn($rateKey);
            return response()->json([
                'error' => 'Terlalu banyak permintaan. Silakan coba lagi dalam ' . $retry . ' detik.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($rateKey, 60);

        if ($token === '') {
            return response()->json(['error' => 'Token tidak ditemukan dalam URL.'], 404);
        }

        $assessment = $this->tokens->verify($token);

        if (! $assessment) {
            return response()->json([
                'error' => 'Tautan asesmen tidak valid. Hubungi pengirim untuk meminta tautan baru.',
            ], 404);
        }

        // Expired? 410 Gone karena resource pernah ada tapi sudah tidak berlaku.
        $expiresAt = $assessment->token_expires_at;
        if ($expiresAt && now()->greaterThan($expiresAt)) {
            return response()->json([
                'error' => 'Tautan asesmen sudah kedaluwarsa. Mohon hubungi pengirim untuk meminta tautan baru.',
                'expired_at' => $expiresAt->toIso8601String(),
            ], 410);
        }

        // Single-use guard untuk write endpoints. Method GET tetap diizinkan
        // supaya pihak ketiga bisa lihat result page setelah submit.
        $isWrite = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($isWrite && $assessment->token_consumed_at !== null) {
            return response()->json([
                'error' => 'Asesmen sudah dikirim sebelumnya dan tidak dapat diubah.',
                'result_url' => url('/api/asesmen-publik/' . $token . '/result'),
                'submitted_at' => optional($assessment->submitted_at)->toIso8601String(),
            ], 410);
        }

        // Set tenant context supaya operasi anak (audit log, tenant storage,
        // model dengan BelongsToOrg trait) ter-scope ke org yang benar.
        $this->orgContext->set($assessment->org_id);

        // Sisipkan assessment ke request — controller cukup `$request->get('_assessment')`.
        $request->merge(['_assessment' => $assessment]);

        return $next($request);
    }
}
