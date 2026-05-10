<?php

/**
 * Default security knobs. Nilai live-nya di-override oleh row di tabel
 * system_settings (key = "security.<group>.<field>") yang di-load oleh
 * SettingsServiceProvider. Editable lewat /platform-admin/system-settings →
 * Security section.
 *
 * Menyimpan default di sini supaya:
 *   1. Boot tetap aman walau migration belum dijalankan / DB belum reachable.
 *   2. App code bisa pakai config('security.login_lockout.tier1_attempts')
 *      tanpa worry null.
 *   3. Test (RefreshDatabase) gak harus seed system_settings tiap kali.
 */

return [
    /**
     * Webhook HMAC signature verification untuk incoming webhooks. Dipakai
     * di endpoint /webhooks/threat-intel/{org_id} dan future webhook receivers.
     * Default OFF (backward-compat dengan vendor yang belum support).
     */
    'webhook_hmac_required' => false,
    'webhook_timestamp_tolerance_seconds' => 300,

    /**
     * Email verification — kirim verification link saat register, block login
     * sampai user click link. Default OFF supaya gak break flow existing
     * (butuh SMTP working). User existing di-grandfather verified_at = now
     * di migration supaya gak ke-lock saat admin enable nanti.
     */
    'email_verification_required' => false,
    'email_verification_grace_minutes' => 60,

    /**
     * 2FA TOTP. `enabled` = master toggle; `required_for_<role>` = force
     * untuk role tertentu (default semua false). User opt-in via profile.
     * State per user di kolom users.two_factor_*; service di
     * App\Services\TwoFactorAuthService.
     */
    '2fa_enabled' => true,
    '2fa_required_for_root' => false,
    '2fa_required_for_superadmin' => false,
    '2fa_required_for_admin' => false,
    '2fa_required_for_dpo' => false,

    /**
     * AI prompt size guard. Cegah biaya tak terduga + abuse — attacker
     * authenticated bisa kirim prompt 100K char untuk drain credit tenant
     * atau bikin tagihan provider membengkak. Limit ini REJECT prompt
     * besar SEBELUM HTTP call ke provider.
     *
     * Service: App\Services\AiPromptGuard. Throw PromptTooLargeException
     * (HTTP 413) kalau melewati limit.
     */
    'ai' => [
        'max_prompt_chars' => 24000,        // ≈ 6000 token (system + user combined)
        'max_message_chars' => 4000,        // single user message field di chat
        'max_attachment_chars' => 12000,    // parsed text dari file upload
    ],

    /**
     * Sanctum personal access token — hard expiry + sliding refresh.
     *
     * `lifetime_minutes` di-mapping ke `sanctum.expiration` oleh
     * SettingsServiceProvider. `refresh_threshold_pct` dipakai oleh
     * App\Http\Middleware\SanctumTokenRefresh untuk decide kapan rotate.
     *
     * Default 7 hari + refresh setelah 50% lifetime — user aktif tidak
     * pernah ke-logout (token rotate diam-diam), user idle ke-logout di
     * hari ke-7.
     */
    'token' => [
        'lifetime_minutes' => 10080,        // 7 hari
        'refresh_threshold_pct' => 50,      // refresh kalau umur token >= 50% lifetime
    ],

    /**
     * Security headers. Dijalankan di App\Http\Middleware\SecurityHeaders
     * (registered global di bootstrap/app.php). Master `enabled` matikan
     * semua sekaligus. HSTS punya toggle terpisah supaya local HTTP dev
     * gak terkena.
     */
    'headers' => [
        'enabled' => true,
        'hsts_enabled' => true,
        'hsts_max_age' => 31536000, // 1 tahun
        'frame_options' => 'SAMEORIGIN',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
    ],

    /**
     * Password policy. Dijalankan di App\Services\PasswordPolicyService saat
     * password baru di-set (register, admin create user, admin update).
     * Policy LAMA tidak di-recheck — user lama gak dipaksa ganti password
     * setelah policy diperketat (kecuali superadmin trigger force reset).
     */
    'password' => [
        'min_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_digit' => true,
        'require_symbol' => true,
        'block_common' => true,
        'block_email_match' => true,
    ],

    /**
     * Login lockout per akun. Dijalankan di App\Services\LoginAttemptService.
     * State (counter & locked_until) ada di kolom users.failed_login_attempts
     * dan users.locked_until — persisted DB, tidak ngandelin cache.
     */
    'login_lockout' => [
        'enabled' => env('SECURITY_LOGIN_LOCKOUT_ENABLED', true),

        // Tier 1 — typo-friendly (3x → 30 detik)
        'tier1_attempts' => 3,
        'tier1_seconds' => 30,

        // Tier 2 — adversarial (5x → 5 menit)
        'tier2_attempts' => 5,
        'tier2_seconds' => 300,

        // Tier 3 — confirmed brute-force (10x → 1 jam)
        'tier3_attempts' => 10,
        'tier3_seconds' => 3600,

        // Sliding window. Counter di-reset ke 0 kalau last_failed_login_at
        // sudah lebih lama dari ini — supaya typo bertahun-tahun lalu tidak
        // membuat akun terkunci saat user akhirnya kembali.
        'window_minutes' => 30,
    ],
];
