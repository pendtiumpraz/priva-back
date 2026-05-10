<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default per-tenant rate limit settings.
 *
 * Sebelum: `throttle:api` global cuma 60 req/menit per user/IP — attacker
 * dari 1 tenant bisa drain quota platform sehingga impact tenant lain.
 * Plus, user authenticated banyak yang reasonably > 60 req/menit untuk
 * UI normal (dashboards yang load 10+ widget).
 *
 * Sekarang: layer kedua di atas global throttle — per-tenant bucket.
 *   - Global IP/user throttle:api → 60/menit (existing, anti-abuse mentah)
 *   - Per-tenant throttle:tenant-api → 300/menit (configurable)
 *   - Auth (login/register) throttle:api → tetap 60 (anti brute force)
 *
 * Default 300 cukup untuk operation normal user banyak (dashboards),
 * tapi tetap reasonable untuk cegah satu tenant flood seluruh platform.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.tenant_rate_limit_per_minute' => 300,
        'security.tenant_rate_limit_enabled' => true,
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
