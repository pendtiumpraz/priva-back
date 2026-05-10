<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default CORS allowlist ke section "security" di system_settings.
 *
 * Default-nya:
 *   - allow_credentials = false (Sanctum bearer-token, bukan SPA cookie)
 *   - max_age = 3600 (preflight cache 1 jam)
 *   - allowed_origins = JSON array dengan localhost dev origins
 *
 * Production HARUS edit allowed_origins via UI atau direct SQL setelah
 * deploy — kalau frontend di-host di domain lain (mis. app-privasimu.esteh.id),
 * domain itu harus eksplisit ditambahkan, bukan di-spread `*`.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.cors_allowed_origins' => ['http://localhost:3000', 'http://127.0.0.1:3000'],
        'security.cors_allow_credentials' => false,
        'security.cors_max_age_seconds' => 3600,
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
