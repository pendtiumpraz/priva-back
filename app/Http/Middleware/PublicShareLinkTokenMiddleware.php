<?php

namespace App\Http\Middleware;

use App\Models\RecordShareLink;
use App\Services\CurrentOrgContext;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Gerbang tautan berbagi ke lembaga (`/api/berbagi-publik/{token}/...`).
 *
 * Menjaga keaktifan tautan saja — pemeriksaan kata sandi sengaja TIDAK di sini,
 * melainkan di controller, karena percobaan sandi punya ember batas laju
 * sendiri yang jauh lebih ketat daripada sekadar membuka halaman.
 *
 * Alasan tautan mati dibedakan (dicabut / kedaluwarsa / jatah habis) supaya
 * petugas lembaga tahu harus minta apa ke DPO, bukan menebak-nebak.
 */
class PublicShareLinkTokenMiddleware
{
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(
        private CurrentOrgContext $orgContext,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->route('token');

        $rateKey = 'public-share-link:'.sha1($token ?: (string) $request->ip());
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

        // Tanpa scope org: pencarian token justru yang MENENTUKAN org-nya.
        $link = RecordShareLink::withoutGlobalScope('org')->where('token', $token)->first();
        if (! $link) {
            return response()->json(['error' => 'Tautan dokumen tidak valid.'], 404);
        }

        if ($link->isRevoked()) {
            $karenaHabis = $link->revoked_reason === RecordShareLink::REASON_MAX_VIEWS;

            return response()->json([
                'error' => $karenaHabis
                    ? 'Tautan ini sudah mencapai batas jumlah pembukaan dan ditutup otomatis. Mohon minta tautan baru kepada DPO pengirim.'
                    : 'Tautan ini sudah dicabut oleh pengirimnya. Mohon minta tautan baru kepada DPO pengirim.',
                'reason' => $link->revoked_reason,
            ], 410);
        }

        if ($link->isExpired()) {
            return response()->json([
                'error' => 'Tautan dokumen sudah kedaluwarsa. Mohon minta tautan baru kepada DPO pengirim.',
                'expired_at' => $link->expires_at->toIso8601String(),
            ], 410);
        }

        // Sama seperti embed: modul yang dicabut ikut menutup tautan dokumennya,
        // walau tautan itu terbit saat modulnya masih aktif.
        if (! app(EntitlementService::class)->allowsMenuKey($link->org_id, $link->module)) {
            return response()->json([
                'error' => 'Modul ini tidak lagi aktif untuk organisasi pengirim. Hubungi DPO pengirim.',
            ], 403);
        }

        $this->orgContext->set($link->org_id);
        $request->merge(['_shareLink' => $link]);

        return $next($request);
    }
}
