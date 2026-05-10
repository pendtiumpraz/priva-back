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
