<?php

/**
 * CORS configuration. Default Laravel-nya allow `*` semua origin yang
 * berbahaya untuk production. File ini publish defaults yang lebih ketat,
 * dan SettingsServiceProvider override `allowed_origins` + `max_age` dari
 * row di system_settings supaya bisa diubah runtime tanpa rebuild.
 *
 * `paths`, `allowed_methods`, `allowed_headers` SENGAJA tidak configurable
 * dari UI:
 *   - paths: harus include api/* + sanctum/csrf-cookie + storage downloads
 *     untuk fungsi normal; salah ubah = app rusak
 *   - methods: `*` aman karena gating sebenarnya ada di route + auth middleware
 *   - headers: `*` aman karena credentials=false (token-based, bukan cookie)
 *
 * Yang ETIKAT KETAT: `allowed_origins`. Ini permukaan serangan utama.
 * `supports_credentials` default false karena auth Sanctum yang dipakai
 * adalah Bearer token, bukan SPA cookie. Kalau suatu saat pindah ke SPA
 * cookie, tambah toggle di UI dan PASTIKAN allowed_origins specific (CORS
 * spec melarang `*` saat credentials=true).
 */

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    /**
     * Default origins — dev-friendly. Production HARUS di-set lewat
     * /platform-admin/system-settings → Security → CORS Allowed Origins.
     * Boot-time override datang dari SettingsServiceProvider.
     */
    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    /**
     * Pattern (regex) untuk allowlist sub-domain. Misal `^https://[^.]+\.privasimu\.com$`
     * untuk semua sub-domain privasimu.com. Settings UI belum expose ini
     * (advanced) — manual edit di system_settings kalau butuh.
     */
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /**
     * Header yang dibolehkan di-baca oleh browser dari respons cross-origin.
     * Tanpa entry di sini, browser akan sembunyikan header tersebut walaupun
     * server kirim. `X-Refreshed-Token` dipakai oleh sliding refresh
     * Sanctum (App\Http\Middleware\SanctumTokenRefresh) — tanpa di-expose,
     * frontend gak bisa replace localStorage auth_token saat rotate.
     */
    'exposed_headers' => ['X-Refreshed-Token'],

    /**
     * Preflight cache duration. Browser akan cache hasil OPTIONS request
     * selama N detik. Trade-off: lebih besar = lebih cepat (less preflight),
     * tapi config baru butuh wait sampai browser cache expired untuk efektif.
     */
    'max_age' => 3600,

    /**
     * Bearer-token auth Sanctum — credentials=false. Kalau diubah true,
     * allowed_origins tidak boleh `*` (CORS spec).
     */
    'supports_credentials' => false,

];
