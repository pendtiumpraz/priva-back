<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default 2FA settings ke section "security" di system_settings.
 *
 * Default policy: feature TERSEDIA tapi TIDAK di-enforce per role. User opt-in
 * via profile sendiri. Admin yang mau force untuk role tertentu (mis. semua
 * root WAJIB 2FA) bisa toggle per-role lewat UI.
 *
 * Reasoning default OFF untuk required: kalau langsung enforce ke role
 * existing yang belum setup, mereka langsung ke-lock keluar dari sistem
 * tanpa peringatan. Admin harus opt-in saat siap, idealnya setelah:
 *   1. Komunikasikan ke user di role tsb
 *   2. Setup grace period (mis. 1 minggu untuk setup)
 *   3. Baru enforce
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.2fa_enabled' => true,                    // Master: feature TERSEDIA untuk user opt-in
        'security.2fa_required_for_root' => false,         // Force per role
        'security.2fa_required_for_superadmin' => false,
        'security.2fa_required_for_admin' => false,
        'security.2fa_required_for_dpo' => false,
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
