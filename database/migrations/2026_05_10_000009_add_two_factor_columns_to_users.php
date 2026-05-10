<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2FA TOTP — add per-user secret + confirmed timestamp + recovery codes.
 *
 *   two_factor_secret           — base32-encoded TOTP shared secret, encrypted
 *                                  at rest via Laravel Crypt::encryptString.
 *   two_factor_recovery_codes   — JSON array of one-time backup codes
 *                                  (10 default), encrypted. User pakai kalau
 *                                  authenticator-nya hilang/rusak.
 *   two_factor_confirmed_at     — null = setup belum di-confirm dengan kode
 *                                  pertama. Setelah confirmed, login WAJIB
 *                                  pakai 2FA. Kalau null tapi secret ada,
 *                                  artinya setup in-progress (user dapat QR
 *                                  tapi belum verify) — gak ke-enforce di login.
 *
 * Tidak pakai Sanctum bawaan two-factor (yang di Fortify) karena project ini
 * pakai Sanctum personal access token, bukan Fortify session-based. Implementasi
 * custom lewat App\Services\TwoFactorAuthService + pragmarx/google2fa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('last_login_ip');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
