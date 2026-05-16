<?php

/**
 * TPRM Phase 3 — Vendor screening config.
 *
 * Search provider: DuckDuckGo (free HTML scrape, langsung jalan tanpa
 * API key). Kalau nanti perlu provider lain (Brave/Tavily/dst), tambah
 * implementasi SearchProviderInterface + ganti binding di AppServiceProvider.
 */
return [
    /*
     * Phase 4 — Default frekuensi full re-assessment vendor (bulan).
     * Sistem hitung "need_reassessment" kalau last_approved_at > N bulan.
     * Tenant boleh override via system_settings UI superadmin.
     * Default 12 bulan sesuai best practice BUMN.
     */
    'full_assessment_frequency_months' => env('TPRM_FULL_ASSESSMENT_FREQUENCY_MONTHS', 12),
];
