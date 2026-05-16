<?php

namespace App\Services\VendorScreening;

/**
 * Abstraksi search provider untuk vendor screening.
 *
 * Implementasi default: DuckDuckGoHtmlSearchProvider (free, no API key,
 * tapi scrape HTML). Tambah provider lain dengan implement interface ini
 * lalu ganti binding di AppServiceProvider.
 */
interface SearchProviderInterface
{
    /**
     * Run search query. Return array of structured results.
     *
     * Format return:
     * [
     *   [
     *     'title' => 'Page title',
     *     'url' => 'https://...',
     *     'snippet' => 'Short description...',
     *     'domain' => 'detik.com',  // optional
     *     'published_at' => 'ISO date or null',  // optional
     *   ],
     *   ...
     * ]
     *
     * Limit hasil ke $maxResults teratas. Empty array = no results / provider
     * unavailable. Caller cek empty.
     */
    public function search(string $query, int $maxResults = 10): array;

    /**
     * Identifier untuk audit log + traceability di vendor_screenings.search_provider.
     */
    public function getName(): string;

    /**
     * Apakah provider siap dipakai (mis. API key tersedia).
     */
    public function isAvailable(): bool;
}
