<?php

namespace App\Services\VendorScreening;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetch URL halaman web + extract plain text untuk dianalisis AI.
 *
 * Use case: privacy policy vendor (URL) + top N hasil search → fetch + strip
 * boilerplate → plain text body. Tidak pakai library readability eksternal
 * supaya tidak nambah dependency; pakai PHP built-in DOMDocument.
 *
 * SECURITY: caller harus VALIDATE URL whitelist (http/https only, no
 * 127.0.0.1/internal IP, no file://, dst) sebelum panggil fetch().
 * Endpoint screening yang panggil ini di-throttle + auth.
 */
class WebContentFetcher
{
    private const MAX_BYTES = 2_000_000; // 2 MB cap untuk safety

    /**
     * Fetch URL + return text bersih. Return null kalau gagal.
     *
     * @return array{text: string, title: string|null, fetched_bytes: int, mime: string|null}|null
     */
    public function fetch(string $url): ?array
    {
        if (! $this->isUrlSafe($url)) {
            Log::info("WebContentFetcher rejected unsafe URL: {$url}");
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; PrivasimuTPRMBot/1.0; +https://privasimu.com)',
                    'Accept' => 'text/html,application/xhtml+xml,text/plain',
                    'Accept-Language' => 'id-ID,id;q=0.9,en;q=0.8',
                ])
                ->get($url);

            if ($response->failed()) {
                Log::info("WebContentFetcher HTTP {$response->status()} for {$url}");
                return null;
            }

            $mime = $response->header('Content-Type') ?: '';
            $body = $response->body();
            if (strlen($body) > self::MAX_BYTES) {
                $body = substr($body, 0, self::MAX_BYTES);
            }

            // Plain text (mis. robots.txt, sitemap.xml fallback)
            if (str_contains($mime, 'text/plain') || str_contains($mime, 'text/markdown')) {
                return [
                    'text' => mb_substr(trim($body), 0, 50000),
                    'title' => null,
                    'fetched_bytes' => strlen($body),
                    'mime' => $mime,
                ];
            }

            // HTML — extract title + main text
            [$title, $text] = $this->extractFromHtml($body);
            return [
                'text' => mb_substr($text, 0, 50000),
                'title' => $title ? mb_substr($title, 0, 200) : null,
                'fetched_bytes' => strlen($body),
                'mime' => $mime,
            ];
        } catch (\Throwable $e) {
            Log::warning("WebContentFetcher exception for {$url}: ".$e->getMessage());
            return null;
        }
    }

    /**
     * Validasi URL untuk hindari SSRF. Reject:
     *  - scheme bukan http/https
     *  - host kosong atau IP private (127.x, 10.x, 172.16-31.x, 192.168.x, localhost)
     *  - host yang resolve ke metadata cloud (169.254.169.254)
     *
     * Note: DNS rebinding attack masih bisa lolos di sini — production yang
     * lebih ketat perlu cek IP resolution di Guzzle middleware.
     */
    private function isUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);
        if (! $parsed || empty($parsed['host']) || empty($parsed['scheme'])) {
            return false;
        }
        $scheme = strtolower($parsed['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower($parsed['host']);
        $blocked = [
            'localhost', '127.0.0.1', '::1',
            '0.0.0.0', '169.254.169.254',
        ];
        if (in_array($host, $blocked, true)) {
            return false;
        }
        // Block private IP range basic check (kalau host kebetulan IP literal)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract title + main text dari HTML. Strip script/style/nav/footer
     * dengan DOMDocument selector. Tidak readability-perfect tapi cukup
     * untuk get the gist privacy policy / artikel berita.
     */
    private function extractFromHtml(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Title
        $title = null;
        $titleNodes = $xpath->query('//title');
        if ($titleNodes && $titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        // Remove noise nodes
        $noiseSelectors = [
            '//script', '//style', '//nav', '//footer', '//header',
            '//noscript', '//iframe', '//svg',
            "//*[contains(@class, 'navbar')]",
            "//*[contains(@class, 'menu')]",
            "//*[contains(@class, 'cookie')]",
            "//*[contains(@id, 'cookie')]",
        ];
        foreach ($noiseSelectors as $sel) {
            $nodes = $xpath->query($sel);
            if ($nodes) {
                foreach (iterator_to_array($nodes) as $n) {
                    $n->parentNode?->removeChild($n);
                }
            }
        }

        // Prefer <main>, fallback <body>, fallback whole doc
        $mainNodes = $xpath->query('//main');
        $root = ($mainNodes && $mainNodes->length > 0)
            ? $mainNodes->item(0)
            : ($dom->getElementsByTagName('body')->item(0) ?? $dom);

        $text = $root ? $root->textContent : '';
        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", trim($text));

        return [$title, $text];
    }
}
