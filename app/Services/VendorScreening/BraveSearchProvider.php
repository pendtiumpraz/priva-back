<?php

namespace App\Services\VendorScreening;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Production search provider: Brave Search API.
 *
 * Endpoint: https://api.search.brave.com/res/v1/web/search
 * Auth header: X-Subscription-Token: <api_key>
 *
 * Free tier 2000 query/bulan. Setelahnya $3/1000 query. Return JSON
 * terstruktur (stabil, tidak perlu parsing HTML).
 *
 * API key disetel di system_settings key 'vendor_screening.brave_api_key'.
 * Kalau kosong, provider mark as unavailable + caller fallback ke DDG.
 */
class BraveSearchProvider implements SearchProviderInterface
{
    public function getName(): string
    {
        return 'brave';
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey());
    }

    public function search(string $query, int $maxResults = 10): array
    {
        $key = $this->apiKey();
        if (! $key) {
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Subscription-Token' => $key,
                    'Accept' => 'application/json',
                ])
                ->get('https://api.search.brave.com/res/v1/web/search', [
                    'q' => $query,
                    'count' => min($maxResults, 20),
                    'safesearch' => 'moderate',
                    'country' => 'ID',  // prioritas hasil Indonesia
                ]);

            if ($response->failed()) {
                Log::warning("Brave search failed [{$query}]: HTTP {$response->status()} - ".substr($response->body(), 0, 200));
                return [];
            }

            $data = $response->json();
            $items = $data['web']['results'] ?? [];

            $out = [];
            foreach ($items as $item) {
                if (count($out) >= $maxResults) {
                    break;
                }
                $url = $item['url'] ?? null;
                if (! $url) {
                    continue;
                }
                $out[] = [
                    'title' => mb_substr((string) ($item['title'] ?? ''), 0, 200),
                    'url' => $url,
                    'snippet' => mb_substr((string) ($item['description'] ?? ''), 0, 500),
                    'domain' => parse_url($url, PHP_URL_HOST) ?: null,
                    'published_at' => $item['page_age'] ?? null,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning("Brave search error [{$query}]: ".$e->getMessage());
            return [];
        }
    }

    private function apiKey(): ?string
    {
        return config('vendor_screening.brave_api_key')
            ?: env('BRAVE_SEARCH_API_KEY');
    }
}
