<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default Sanctum token expiry + sliding-refresh settings.
 *
 * Sebelum patch ini, `config('sanctum.expiration') = null` artinya token
 * never expires — kalau leak, attacker punya akses selamanya sampai user
 * logout manual. Sekarang:
 *
 *   - token_lifetime_minutes (default 10080 = 7 hari): hard expiry per token
 *   - token_refresh_threshold_pct (default 50): kalau token udah lewat
 *     50% lifetime-nya, request berikutnya akan di-issue token baru
 *     (sliding refresh) supaya user aktif tidak ke-logout setiap 7 hari.
 *
 * Keduanya editable lewat /platform-admin/system-settings → Security.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.token_lifetime_minutes' => 10080,    // 7 hari
        'security.token_refresh_threshold_pct' => 50,  // refresh setelah 50% lifetime
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
