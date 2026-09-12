<?php

namespace App\Http\Middleware;

use App\Services\CurrentOrgContext;
use App\Services\VendorContractTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gerbang tautan publik unggah kontrak (`/api/kontrak-pihak-ketiga/{token}/...`).
 *
 * Kembaran PublicAssessmentTokenMiddleware dengan ember batas sendiri:
 * resolve token, tolak yang kedaluwarsa, kunci setelah dipakai mengunggah,
 * setel tenant context, dan sisipkan kontrak sebagai `_vendorContract`.
 */
class PublicVendorContractTokenMiddleware
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(
        private VendorContractTokenService $tokens,
        private CurrentOrgContext $orgContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->route('token');

        $rateKey = 'public-vendor-contract-token:'.sha1($token ?: (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_LIMIT_PER_MINUTE)) {
            $retry = RateLimiter::availableIn($rateKey);

            return response()->json([
                'error' => 'Terlalu banyak permintaan. Silakan coba lagi dalam '.$retry.' detik.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($rateKey, 60);

        if ($token === '') {
            return response()->json(['error' => 'Token tidak ditemukan dalam URL.'], 404);
        }

        $contract = $this->tokens->verify($token);
        if (! $contract) {
            return response()->json([
                'error' => 'Tautan unggah kontrak tidak valid. Hubungi pengirim untuk meminta tautan baru.',
            ], 404);
        }

        $expiresAt = $contract->token_expires_at;
        if ($expiresAt && now()->greaterThan($expiresAt)) {
            return response()->json([
                'error' => 'Tautan unggah kontrak sudah kedaluwarsa.',
                'expired_at' => $expiresAt->toIso8601String(),
            ], 410);
        }

        $isWrite = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if ($isWrite && $contract->token_consumed_at !== null) {
            return response()->json([
                'error' => 'Kontrak sudah diunggah lewat tautan ini. Hubungi pengirim bila perlu mengunggah ulang.',
                'uploaded_at' => optional($contract->token_consumed_at)->toIso8601String(),
            ], 410);
        }

        $this->orgContext->set($contract->org_id);
        $request->merge(['_vendorContract' => $contract]);

        return $next($request);
    }
}
