<?php

/**
 * TPRM Phase 3 — Vendor screening config.
 *
 * Bisa di-override via system_settings (admin UI). Settings key format:
 * 'vendor_screening.search_provider', 'vendor_screening.brave_api_key', dst.
 */
return [
    /*
     * Provider yang dipakai untuk web search di screening.
     * Pilihan: 'duckduckgo' (default, free scrape) | 'brave' (production-grade)
     */
    'search_provider' => env('VENDOR_SCREENING_SEARCH_PROVIDER', 'duckduckgo'),

    /*
     * Brave Search API key (OPSIONAL). Free tier 2000 query/bulan. Diisi
     * superadmin via system_settings UI atau .env. Kalau kosong, system
     * pakai DuckDuckGo HTML scrape sebagai default. NO ACTION REQUIRED
     * kalau Anda OK dengan DDG (free + no setup).
     */
    'brave_api_key' => env('BRAVE_SEARCH_API_KEY', null),

    /*
     * Phase 4 — Default frekuensi full re-assessment vendor (bulan).
     * Sistem hitung "need_reassessment" kalau last_approved_at > N bulan.
     * Tenant boleh override via system_settings UI superadmin.
     * Default 12 bulan sesuai best practice BUMN.
     */
    'full_assessment_frequency_months' => env('TPRM_FULL_ASSESSMENT_FREQUENCY_MONTHS', 12),
];
