<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default security headers ke section "security" di system_settings.
 *
 * Headers di-stamp oleh App\Http\Middleware\SecurityHeaders (registered global
 * di bootstrap/app.php). Bisa di-toggle global lewat `headers_enabled`.
 *
 * Default-nya konservatif untuk B2B SaaS:
 *   - HSTS 1 tahun (preload-ready)
 *   - X-Content-Type-Options nosniff (selalu, gak ada knob)
 *   - X-Frame-Options SAMEORIGIN (cegah clickjacking dari domain lain)
 *   - Referrer-Policy strict-origin-when-cross-origin
 *   - Permissions-Policy block camera/mic/geo/payment by default
 *
 * HSTS sengaja enabled by default — kalau deploy lokal HTTP-only, admin
 * matikan via toggle. Kalau kepatil di sub-domain HTTP, browser tetap
 * forced ke HTTPS sampai max-age habis (jadi hati-hati).
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.headers_enabled' => true,
        'security.headers_hsts_enabled' => true,
        'security.headers_hsts_max_age' => 31536000, // 1 tahun
        'security.headers_frame_options' => 'SAMEORIGIN',
        'security.headers_referrer_policy' => 'strict-origin-when-cross-origin',
        'security.headers_permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::DEFAULTS as $key => $value) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();
            if ($exists) continue;

            DB::table('system_settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'is_encrypted' => false,
                'section' => 'security',
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys(self::DEFAULTS))->delete();
    }
};
