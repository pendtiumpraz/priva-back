<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default brute-force protection settings untuk 2FA challenge verify.
 *
 * Lockout tier sebelumnya cuma di password step. Setelah attacker lolos
 * password, mereka bisa coba brute force 6-digit code (1M kombinasi).
 * Walaupun rate-limit IP global 60/min ada, dengan window 5 menit challenge
 * + brute distribution, attacker bisa coba ~300 kombinasi (lumayan).
 *
 * Sekarang: per-challenge attempt counter. Setelah N kali salah kode dalam
 * 1 challenge, challenge di-invalidate paksa → user harus mulai login dari
 * awal (password lagi).
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.2fa_max_verify_attempts' => 5,
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
