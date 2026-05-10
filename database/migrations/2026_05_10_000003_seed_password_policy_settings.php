<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default password policy ke section "security" di system_settings.
 *
 * Sebelum patch ini, password cuma divalidasi `min:8` Laravel default —
 * cukup lemah untuk platform compliance UU PDP. Default baru:
 *   - min length 12
 *   - wajib uppercase + lowercase + digit
 *   - opsional simbol (default true tapi bisa di-off untuk akun servis)
 *   - blokir top-100 common passwords (diembed di service)
 *   - blokir password yang isinya sama dengan email/local-part
 *
 * Editable lewat /platform-admin/system-settings → Security section.
 * Idempotent — kalau key sudah ada (mis. admin sempat set sebelum migration
 * ulang), tidak overwrite.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'security.password_min_length' => 12,
        'security.password_require_uppercase' => true,
        'security.password_require_lowercase' => true,
        'security.password_require_digit' => true,
        'security.password_require_symbol' => true,
        'security.password_block_common' => true,
        'security.password_block_email_match' => true,
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
