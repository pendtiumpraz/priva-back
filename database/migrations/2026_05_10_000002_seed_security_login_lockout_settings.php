<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default values untuk section "security" di system_settings.
 *
 * Editable lewat UI /platform-admin/system-settings (root/superadmin).
 * Default value-nya konservatif tapi bukan agresif — biar pengguna sah
 * yang typo password 1-2 kali tetap nyaman, sementara brute-force kena.
 *
 *   tier1: 3 fail   → lock 30 detik
 *   tier2: 5 fail   → lock 5 menit
 *   tier3: 10 fail  → lock 1 jam
 *   window: counter di-reset kalau gak ada fail dalam 30 menit
 */
return new class extends Migration
{
    /**
     * Naming: flat (security.<field>) supaya konsisten dengan section lain
     * (redis.host, mail.smtp_host, dll) dan kompatibel dengan helper
     * `shortKey()` di SystemSettingsController. Mapping ke nested config
     * path ('security.login_lockout.*') terjadi di SettingsServiceProvider.
     */
    private const DEFAULTS = [
        'security.lockout_enabled' => true,
        'security.lockout_tier1_attempts' => 3,
        'security.lockout_tier1_seconds' => 30,
        'security.lockout_tier2_attempts' => 5,
        'security.lockout_tier2_seconds' => 300,
        'security.lockout_tier3_attempts' => 10,
        'security.lockout_tier3_seconds' => 3600,
        'security.lockout_window_minutes' => 30,
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::DEFAULTS as $key => $value) {
            // Idempotent — kalau sudah ada (mis. admin sempat set sebelum
            // migration jalan ulang), JANGAN overwrite.
            $exists = DB::table('system_settings')->where('key', $key)->exists();
            if ($exists) {
                continue;
            }

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
