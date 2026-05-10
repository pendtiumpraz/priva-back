<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers middleware. Stamp common headers ke setiap response:
 *   - Strict-Transport-Security (HSTS)        — paksa HTTPS, configurable
 *   - X-Content-Type-Options: nosniff          — cegah MIME sniffing (always)
 *   - X-Frame-Options                          — cegah clickjacking, configurable
 *   - Referrer-Policy                          — kontrol Referer leakage
 *   - Permissions-Policy                       — block kamera/mic/geo by default
 *
 * Master toggle `security.headers.enabled` matikan SEMUA header sekaligus
 * (untuk debugging atau env yang konflik dengan reverse proxy yang sudah
 * stamp duluan). HSTS punya toggle terpisah supaya bisa diaktifkan di prod
 * tapi mati di local HTTP dev.
 *
 * X-Content-Type-Options: nosniff selalu di-stamp (gak ada knob) karena
 * trade-off-nya nol — gak ada legitimate reason browser perlu sniff MIME.
 *
 * CSP NOT included di sini — backend mostly serve JSON, dan beberapa endpoint
 * memang serve HTML (DSR verify, NDA preview). CSP yang restrictive akan
 * break preview pages. CSP untuk HTML pages harus per-route, atau ditangani
 * di layer reverse proxy / Next.js frontend.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! (bool) config('security.headers.enabled', true)) {
            return $response;
        }

        // Always-on (no knob, zero downside)
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // HSTS — only ketika request datang via HTTPS, supaya local HTTP dev
        // gak ke-stamp HSTS yang akan force redirect dari browser cache.
        if ((bool) config('security.headers.hsts_enabled', true) && $request->isSecure()) {
            $maxAge = (int) config('security.headers.hsts_max_age', 31536000);
            $hstsValue = "max-age={$maxAge}; includeSubDomains";
            // `preload` sengaja TIDAK di-set otomatis — itu opt-in HSTS preload
            // list di chromium yang gak bisa di-rollback gampang. Admin yang
            // mau preload bisa edit value via reverse proxy / next layer.
            $response->headers->set('Strict-Transport-Security', $hstsValue);
        }

        // Frame options
        $frameOptions = (string) config('security.headers.frame_options', 'SAMEORIGIN');
        if (in_array(strtoupper($frameOptions), ['DENY', 'SAMEORIGIN'], true)) {
            $response->headers->set('X-Frame-Options', strtoupper($frameOptions));
        }

        // Referrer policy
        $referrer = (string) config('security.headers.referrer_policy', 'strict-origin-when-cross-origin');
        if ($referrer !== '') {
            $response->headers->set('Referrer-Policy', $referrer);
        }

        // Permissions policy (formerly Feature-Policy)
        $permissions = (string) config('security.headers.permissions_policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        if ($permissions !== '') {
            $response->headers->set('Permissions-Policy', $permissions);
        }

        return $response;
    }
}
