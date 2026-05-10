<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default webhook HMAC settings.
 *
 *   - webhook_hmac_required (default FALSE)
 *     Kalau true: SEMUA incoming webhook (threat-intel dll) WAJIB punya
 *     header X-Webhook-Signature yang valid HMAC-SHA256 dari body. Kalau
 *     false (default): signature OPTIONAL — verified kalau dikirim, tapi
 *     gak required (backward-compat dengan vendor yang belum support).
 *
 *   - webhook_timestamp_tolerance_seconds (default 300 = 5 menit)
 *     Maksimum drift antara X-Webhook-Timestamp dan server time. Kalau
 *     header tidak dikirim, skip check ini. Default cukup longgar untuk
 *     clock skew normal, tapi cukup ketat untuk anti-replay (attacker
 *     gak bisa replay request kuno).
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.webhook_hmac_required' => false,
        'security.webhook_timestamp_tolerance_seconds' => 300,
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
