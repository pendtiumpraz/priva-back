<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed phase 1 nice-to-have security settings. SEMUA default OFF / 0 / unlimited:
 *
 *   - password_check_hibp: check via HaveIBeenPwned k-anonymity API
 *   - password_rotation_days: force password change setelah N hari (0 = off)
 *   - max_sessions_per_user: cap concurrent active Sanctum tokens (0 = unlimited)
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.password_check_hibp' => false,
        'security.password_rotation_days' => 0,
        'security.max_sessions_per_user' => 0,
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
