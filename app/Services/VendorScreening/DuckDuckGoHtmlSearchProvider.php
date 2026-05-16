<?php

namespace App\Services\VendorScreening;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Default search provider: scrape DuckDuckGo HTML lite endpoint.
 *
 * Endpoint: https://html.duckduckgo.com/html/?q=QUERY
 * Pros: gratis, tidak perlu API key, langsung jalan
 * Cons: rapuh (rate limit + parsing breaking), against ToS technically
 *
 * Untuk production BUMN sebaiknya swap ke BraveSearchProvider (API key di
 * system_settings). Provider ini cocok untuk PoC / dev environment.
 *
 * Parsing pakai PHP built-in DOMDocument supaya tidak butuh dependency
 * tambahan (composer.json tidak ada Symfony DomCrawler).
 */
class DuckDuckGoHtmlSearchProvider implements SearchProviderInterface
{
    public function getName(): string
    {
        return 'duckduckgo';
    }

    public function isAvailable(): bool
    {
        return true; // tidak butuh API key
    }

    public function search(string $query, int $maxResults = 10): array
    {
        try {
            $response = Http::timeout(15)
                ->withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'id-ID,id;q=0.9,en;q=0.8',
                ])
                ->asForm()
                ->post('https://html.duckduckgo.com/html/', [
                    'q' => $query,
                ]);

            if ($response->failed()) {
                Log::warning("DDG search failed [{$query}]: HTTP {$response->status()}");
                return [];
            }

            $html = $response->body();
            return $this->parseResults($html, $maxResults);
        } catch (\Throwable $e) {
            Log::warning("DDG search error [{$query}]: ".$e->getMessage());
            return [];
        }
    }

    /**
     * Parse HTML DDG hasil pakai DOMDocument. Selector untuk result item:
     *   div.result            (container per item)
     *     a.result__a         (title + URL)
     *     a.result__snippet   (snippet text)
     */
    private function parseResults(string $html, int $maxResults): array
    {
        if (empty($html) || strlen($html) < 100) {
            return [];
        }

        $results = [];

        // Suppress warnings dari malformed HTML — DDG output kadang tidak strict
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $items = $xpath->query("//div[contains(@class, 'result') and not(contains(@class, 'result--no'))]");

        if (! $items || $items->length === 0) {
            return [];
        }

        foreach ($items as $item) {
            if (count($results) >= $maxResults) {
                break;
            }
            /** @var \DOMElement $item */
            $titleNodes = $xpath->query(".//a[contains(@class, 'result__a')]", $item);
            $snippetNodes = $xpath->query(".//a[contains(@class, 'result__snippet')] | .//*[contains(@class, 'result__snippet')]", $item);

            if (! $titleNodes || $titleNodes->length === 0) {
                continue;
            }

            $titleEl = $titleNodes->item(0);
            /** @var \DOMElement $titleEl */
            $title = trim($titleEl->textContent);
            $rawHref = $titleEl->getAttribute('href');
            $url = $this->cleanDdgRedirectUrl($rawHref);
            if (! $url || ! $title) {
                continue;
            }

            $snippet = '';
            if ($snippetNodes && $snippetNodes->length > 0) {
                $snippet = trim($snippetNodes->item(0)->textContent);
            }

            $results[] = [
                'title' => mb_substr($title, 0, 200),
                'url' => $url,
                'snippet' => mb_substr($snippet, 0, 500),
                'domain' => parse_url($url, PHP_URL_HOST) ?: null,
                'published_at' => null,
            ];
        }

        return $results;
    }

    /**
     * DDG bungkus URL hasil dalam redirect: //duckduckgo.com/l/?uddg=ENCODED_URL&rut=...
     * Decode supaya kita dapat URL asli.
     */
    private function cleanDdgRedirectUrl(string $rawHref): ?string
    {
        if (! $rawHref) {
            return null;
        }
        // Sudah absolute
        if (preg_match('/^https?:\/\//i', $rawHref)) {
            return $rawHref;
        }
        // Pattern DDG: //duckduckgo.com/l/?uddg=...
        if (str_contains($rawHref, 'uddg=')) {
            $parsed = parse_url($rawHref);
            if (! empty($parsed['query'])) {
                parse_str($parsed['query'], $params);
                if (! empty($params['uddg'])) {
                    return urldecode($params['uddg']);
                }
            }
        }
        return null;
    }
}
