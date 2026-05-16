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
     * Brave Search API key. Diisi superadmin via system_settings UI atau .env
     * untuk dev. Kalau kosong + search_provider='brave' → fall back ke DDG.
     */
    'brave_api_key' => env('BRAVE_SEARCH_API_KEY', null),

    /*
     * Tavily API key (kalau nanti tambah Tavily provider).
     */
    'tavily_api_key' => env('TAVILY_API_KEY', null),
];
