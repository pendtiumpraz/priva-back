<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed phase 2 nice-to-have security settings. SEMUA default OFF.
 *
 *   - ip_allowlist_enabled_for_root: lock root login ke IP/CIDR di list
 *   - ip_allowlist_enabled_for_superadmin: idem untuk superadmin
 *   - ip_allowlist_root: JSON array IP/CIDR string (default empty)
 *   - ip_allowlist_superadmin: idem
 *   - audit_log_retention_days: auto-prune audit_logs lama via cron
 *     (0 = keep forever, default)
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.ip_allowlist_enabled_for_root' => false,
        'security.ip_allowlist_enabled_for_superadmin' => false,
        'security.ip_allowlist_root' => [],
        'security.ip_allowlist_superadmin' => [],
        'security.audit_log_retention_days' => 0,
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
