<?php

namespace App\Http\Middleware;

use App\Models\EmbedToken;
use App\Services\CurrentOrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gerbang tautan embed publik (`/api/embed-publik/{token}/...`).
 *
 * Sekeluarga dengan PublicVendorRopaTokenMiddleware, dengan dua perbedaan yang
 * disengaja:
 *
 *  1. HANYA BACA. Tidak ada jalur tulis sama sekali — sebuah tautan yang
 *     tertanam di situs orang lain tidak boleh pernah bisa mengubah data.
 *     Metode selain GET/HEAD/OPTIONS ditolak di sini, bukan diserahkan ke
 *     controller, supaya penambahan rute baru tidak sengaja membuka celah.
 *  2. Token tidak "habis dipakai" — embed memang dilihat berulang kali.
 *     Pengendaliannya lewat pencabutan (`revoked_at`) dan masa berlaku.
 */
class PublicEmbedTokenMiddleware
{
    private const RATE_LIMIT_PER_MINUTE = 60;

    public function __construct(
        private CurrentOrgContext $orgContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->route('token');

        // Batasi sebelum menyentuh basis data, supaya tebakan token acak tidak
        // berubah menjadi beban query.
        $rateKey = 'public-embed-token:'.sha1($token ?: (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, self::RATE_LIMIT_PER_MINUTE)) {
            $retry = RateLimiter::availableIn($rateKey);

            return response()->json([
                'error' => 'Terlalu banyak permintaan. Silakan coba lagi dalam '.$retry.' detik.',
                'retry_after' => $retry,
            ], 429)->header('Retry-After', (string) $retry);
        }
        RateLimiter::hit($rateKey, 60);

        if (! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['error' => 'Tautan embed hanya untuk membaca.'], 405);
        }

        if ($token === '') {
            return response()->json(['error' => 'Token tidak ditemukan dalam URL.'], 404);
        }

        // Tanpa scope org: pencarian token justru yang MENENTUKAN org-nya.
        $embed = EmbedToken::withoutGlobalScope('org')->where('token', $token)->first();
        if (! $embed) {
            return response()->json(['error' => 'Tautan embed tidak valid.'], 404);
        }

        if ($embed->isRevoked()) {
            return response()->json([
                'error' => 'Tautan embed sudah dicabut oleh pemiliknya.',
            ], 410);
        }

        if ($embed->isExpired()) {
            return response()->json([
                'error' => 'Tautan embed sudah kedaluwarsa.',
                'expired_at' => $embed->expires_at->toIso8601String(),
            ], 410);
        }

        if (! $embed->allowsOrigin($request->headers->get('Origin'))) {
            return response()->json([
                'error' => 'Origin ini tidak diizinkan memasang tautan embed tersebut.',
            ], 403);
        }

        // Tenant context supaya operasi turunan tetap ter-scope ke org pemilik
        // walau permintaannya anonim.
        $this->orgContext->set($embed->org_id);
        $request->merge(['_embedToken' => $embed]);

        return $next($request);
    }
}
