<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login lockout state per user. Persisted di DB (bukan cache) supaya kondisi
 * lockout tetap berlaku meskipun browser di-reload, attacker rotate IP, atau
 * pakai client lain.
 *
 * `failed_login_attempts` adalah running counter — di-reset ke 0 ketika:
 *   1. login berhasil, atau
 *   2. last_failed_login_at lebih lama dari window_minutes (default 30 menit).
 *
 * `locked_until` adalah timestamp absolut sampai kapan akun dilarang login.
 * Tier (default): 3x → 30 detik, 5x → 5 menit, 10x → 1 jam. Threshold &
 * durasinya editable lewat /platform-admin/system-settings, section "Security".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('is_active');
            $table->timestamp('last_failed_login_at')->nullable()->after('failed_login_attempts');
            $table->timestamp('locked_until')->nullable()->after('last_failed_login_at');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'failed_login_attempts',
                'last_failed_login_at',
                'locked_until',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
