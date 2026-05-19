<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sliding refresh untuk Sanctum personal access tokens.
 *
 * Sanctum bawaan: token `expiration` (config) jadi hard cut — kalau di-set
 * 7 hari, user yang aktif terus-menerus tetap ke-logout di hari ke-8.
 * Middleware ini menambah behavior:
 *   - Setiap authenticated request, cek umur token (sejak created_at)
 *   - Kalau melewati threshold % dari lifetime, issue token baru, hapus
 *     yang lama, kirim token baru via header `X-Refreshed-Token`
 *   - Frontend tangkap header itu dan replace `auth_token` di localStorage
 *
 * Hasilnya: user aktif gak pernah ke-logout (token rotated otomatis di
 * background); user idle ke-logout setelah `lifetime` waktu absolut sejak
 * last interaction. Ini "sliding window" yang umum di industri.
 *
 * Race condition catatan: kalau 2 request paralel sama-sama trigger
 * refresh, dua-duanya bisa create token baru (orphan 1 token). Frontend
 * pakai header dari response paling akhir — orphan token tetap valid
 * tapi tidak dipakai siapa pun, akan kena hard-expiry dengan sendirinya.
 *
 * Endpoint logout sengaja TIDAK di-refresh — kalau user logout, jangan
 * issue token baru tepat sebelum delete.
 */
class SanctumTokenRefresh
{
    /** Header yang dibaca oleh frontend untuk replace localStorage auth_token. */
    public const REFRESH_HEADER = 'X-Refreshed-Token';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Hanya kalau user authenticated via Sanctum dan request ini bukan
        // logout (yang akan delete token-nya).
        $user = $request->user();
        if (! $user) return $response;

        $token = $user->currentAccessToken();
        if (! $token || ! method_exists($token, 'getKey')) return $response;

        // Skip pada endpoint logout — gak masuk akal issue token baru
        // tepat sebelum di-delete.
        if ($request->is('api/auth/logout')) return $response;

        $lifetimeMinutes = (int) config('sanctum.expiration', 0);
        if ($lifetimeMinutes <= 0) return $response; // expiry off → no refresh

        $thresholdPct = (int) config('security.token.refresh_threshold_pct', 50);
        if ($thresholdPct <= 0 || $thresholdPct >= 100) return $response;

        $createdAt = $token->created_at;
        if (! $createdAt) return $response;

        // Refresh kalau current_age >= threshold% * lifetime
        $ageSeconds = now()->getTimestamp() - $createdAt->getTimestamp();
        $thresholdSeconds = (int) round($lifetimeMinutes * 60 * ($thresholdPct / 100));
        if ($ageSeconds < $thresholdSeconds) return $response;

        // Issue token baru — pakai nama yang sama supaya audit trail
        // konsisten. Token LAMA TIDAK langsung di-delete supaya in-flight
        // parallel requests (yang sudah lewat auth tapi belum lewat refresh)
        // tidak kena 401 di backend berikutnya. Old token akan jalan natural
        // ke hard expiry-nya dan di-cleanup oleh scheduled command
        // `sanctum:prune-stale-tokens` (jika ter-register).
        //
        // Trade-off: 1 token extra per refresh, accumulate hingga hard expiry.
        // Untuk lifetime 7 hari + refresh 50% threshold, max ~2-3 token per
        // user (current + 1-2 baru-baru di-rotate). Manageable di DB.
        //
        // Refresh juga di-guard dengan transient cache lock supaya hanya 1
        // dari N parallel requests yang trigger rotation — sisanya skip dan
        // return response normal pakai token mereka (yang masih valid).
        $lockKey = 'sanctum_refresh_lock:' . $token->getKey();
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 10); // 10 detik lock
        if (! $lock->get()) {
            // Request lain udah refresh barusan — skip, biarkan client
            // pakai token sekarang. Akan auto-refresh di request berikutnya
            // kalau threshold masih relevant.
            return $response;
        }

        $newPlain = null;
        try {
            $newToken = $user->createToken($token->name ?? 'auth-token');
            $newPlain = $newToken->plainTextToken;
        } catch (\Throwable $e) {
            // Refresh gagal bukan reason untuk kill request — log doang,
            // user tetap dapat response normal pakai token lama.
            \Log::warning('SanctumTokenRefresh failed', ['error' => $e->getMessage()]);
            return $response;
        } finally {
            $lock->release();
        }

        if ($newPlain) {
            $response->headers->set(self::REFRESH_HEADER, $newPlain);
        }

        return $response;
    }
}
