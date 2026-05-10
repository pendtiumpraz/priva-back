<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grandfather user existing — set email_verified_at = now untuk semua user
 * yang verified_at masih null. Tanpa ini, kalau admin enable
 * security.email_verification_required setelah deploy, semua user existing
 * langsung ke-lock dari login sampai verifikasi email (yang gak pernah
 * mereka terima).
 *
 * Seed default settings juga:
 *   - security.email_verification_required (default FALSE)
 *   - security.email_verification_grace_minutes (default 60 — kalau enable,
 *     user baru punya 60 menit untuk verify sebelum login di-block. Untuk
 *     v1 belum di-enforce, hanya sebagai info).
 */
return new class extends Migration
{
    private const SETTINGS = [
        'security.email_verification_required' => false,
        'security.email_verification_grace_minutes' => 60,
    ];

    public function up(): void
    {
        // Grandfather existing users
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);

        // Seed settings
        $now = now();
        foreach (self::SETTINGS as $key => $value) {
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
        DB::table('system_settings')->whereIn('key', array_keys(self::SETTINGS))->delete();
        // Tidak un-grandfather user existing — itu bisa break login mereka,
        // dan rollback bukan reason untuk re-trigger verification massal.
    }
};
