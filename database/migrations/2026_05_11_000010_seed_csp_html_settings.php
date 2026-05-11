<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default Content-Security-Policy settings untuk route HTML.
 *
 * Backend serve campuran JSON (mayoritas) + HTML (preview pages: DSR verify,
 * NDA preview, consent banner, DSR widget). CSP strict di JSON response gak
 * masuk akal (browser tidak execute JSON). Tapi HTML response tanpa CSP
 * adalah XSS vector — kalau attacker bisa inject script tag, browser akan
 * execute. CSP block inline script eksekusi (kecuali 'unsafe-inline'
 * explicit dipasang).
 *
 * Middleware SecurityHeaders auto-detect Content-Type response — kalau
 * text/html, stamp Content-Security-Policy. JSON response gak ke-stamp.
 *
 * Default value permissive tapi block dangerous patterns: object-src 'none'
 * (block Flash/Java), frame-ancestors 'self' (anti-clickjacking selain
 * X-Frame-Options), base-uri 'self' (cegah base tag hijack).
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.headers_csp_html_enabled' => true,
        'security.headers_csp_html_value' =>
            "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob:; "
            ."font-src 'self' data:; "
            ."connect-src 'self'; "
            ."frame-ancestors 'self'; "
            ."base-uri 'self'; "
            ."form-action 'self'; "
            ."object-src 'none'",
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
