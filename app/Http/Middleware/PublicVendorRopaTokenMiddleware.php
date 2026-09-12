<?php

namespace App\Http\Middleware;

use App\Services\CurrentOrgContext;
use App\Services\VendorRopaTokenService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gerbang tautan publik RoPA pihak ketiga (`/api/ropa-pihak-ketiga/{token}/...`).
 *
 * Sama seperti PublicAssessmentTokenMiddleware: resolve token, validasi
 * expiry, kunci sekali-kirim untuk penulisan, set tenant context, sisipkan
 * record ke request, dan batasi 30 permintaan per menit per token (ember
 * terpisah dari asesmen).
 *
 * SATU pengecualian pada kunci sekali-kirim: `minta-akses-ubah`. Permintaan
 * akses ubah justru baru bermakna SETELAH kiriman terkunci — itulah satu-satunya
 * cara pihak ketiga (yang tidak punya akun) meminta koreksi. Karena itu jalur
 * ini dikecualikan berdasarkan nama segmen, bukan metode HTTP.
 */
class PublicVendorRopaTokenMiddleware
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    /** Satu-satunya penulisan yang tetap diizinkan setelah token terpakai. */
    private const ALLOWED_AFTER_CONSUMED = 'minta-akses-ubah';

    public function __construct(
        private VendorRopaTokenService $tokens,
        private CurrentOrgContext $orgContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->route('token');

        // Batasi dulu sebelum menyentuh basis data, supaya tebakan token acak
        // tidak berubah menjadi beban query.
        $rateKey = 'public-vendor-ropa-token:'.sha1($token ?: (string) $request->ip());
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

        $vendorRopa = $this->tokens->verify($token);
        if (! $vendorRopa) {
            return response()->json([
                'error' => 'Tautan RoPA tidak valid. Hubungi pengirim untuk meminta tautan baru.',
            ], 404);
        }

        $expiresAt = $vendorRopa->token_expires_at;
        if ($expiresAt && now()->greaterThan($expiresAt)) {
            return response()->json([
                'error' => 'Tautan RoPA sudah kedaluwarsa. Mohon hubungi pengirim untuk meminta tautan baru.',
                'expired_at' => $expiresAt->toIso8601String(),
            ], 410);
        }

        $isWrite = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        $isEditRequest = $request->segment(4) === self::ALLOWED_AFTER_CONSUMED;
        if ($isWrite && ! $isEditRequest && $vendorRopa->token_consumed_at !== null) {
            return response()->json([
                'error' => 'RoPA sudah dikirim sebelumnya dan tidak dapat diubah. Ajukan permintaan akses ubah bila ada koreksi.',
                'result_url' => url('/api/ropa-pihak-ketiga/'.$token.'/hasil'),
                'edit_request_url' => url('/api/ropa-pihak-ketiga/'.$token.'/minta-akses-ubah'),
                'submitted_at' => optional($vendorRopa->submitted_at)->toIso8601String(),
            ], 410);
        }

        // Tenant context supaya operasi turunan (audit log, notifikasi,
        // penyimpanan) tetap ter-scope ke org pemilik walau request anonim.
        $this->orgContext->set($vendorRopa->org_id);
        $request->merge(['_vendorRopa' => $vendorRopa]);

        return $next($request);
    }
}
