<?php

namespace App\Services\Embedding;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien tipis untuk REST API Vercel Blob.
 *
 * Dipakai menyimpan artefak model embedding supaya banyak instans aplikasi
 * berbagi satu salinan, alih-alih tiap VPS mengunduh sendiri dari HuggingFace.
 *
 * Token diambil dari config('embedding_models.blob.token') — pada deployment
 * ini berasal dari env `priva_READ_WRITE_TOKEN` (Vercel memberi awalan nama
 * store pada token yang diterbitkannya).
 *
 * Catatan unggah: berkas model berukuran puluhan sampai ratusan MB, jadi
 * body dikirim sebagai stream (resource) — bukan string — agar tidak menarik
 * seluruh berkas ke memori PHP.
 */
class VercelBlobClient
{
    private ?string $token;

    private string $baseUrl;

    private string $apiVersion;

    private int $timeout;

    public function __construct()
    {
        $blob = (array) config('embedding_models.blob', []);
        $this->token = $blob['token'] ?? null;
        $this->baseUrl = rtrim((string) ($blob['base_url'] ?? 'https://blob.vercel-storage.com'), '/');
        $this->apiVersion = (string) ($blob['api_version'] ?? '7');
        $this->timeout = (int) ($blob['timeout'] ?? 300);
    }

    /** Blob hanya dapat dipakai bila tokennya tersedia. */
    public function isConfigured(): bool
    {
        return is_string($this->token) && $this->token !== '';
    }

    /**
     * Daftar blob dengan awalan tertentu.
     *
     * @return array<int, array{pathname: string, url: string, size: int, uploadedAt: ?string}>
     */
    public function list(string $prefix, int $limit = 1000): array
    {
        $this->assertConfigured();

        $response = Http::withToken($this->token)
            ->withHeaders(['x-api-version' => $this->apiVersion])
            ->timeout($this->timeout)
            ->acceptJson()
            ->get($this->baseUrl, [
                'prefix' => $prefix,
                'limit' => $limit,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Vercel Blob list gagal (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 200)
            );
        }

        $blobs = $response->json('blobs');
        if (! is_array($blobs)) {
            return [];
        }

        return array_map(static fn (array $b) => [
            'pathname' => (string) ($b['pathname'] ?? ''),
            'url' => (string) ($b['url'] ?? ''),
            'size' => (int) ($b['size'] ?? 0),
            'uploadedAt' => isset($b['uploadedAt']) ? (string) $b['uploadedAt'] : null,
        ], array_filter($blobs, 'is_array'));
    }

    /**
     * Unggah berkas lokal ke pathname tertentu. Mengembalikan URL publiknya.
     *
     * `addRandomSuffix=0` penting: kita butuh pathname yang dapat ditebak
     * supaya pengecekan keberadaan berkas tidak bergantung pada hasil list.
     */
    public function putFile(string $pathname, string $localPath, string $contentType = 'application/octet-stream'): string
    {
        $this->assertConfigured();

        if (! is_file($localPath)) {
            throw new RuntimeException("Berkas sumber tidak ditemukan: {$localPath}");
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Gagal membuka berkas untuk diunggah: {$localPath}");
        }

        try {
            $response = Http::withToken($this->token)
                ->withHeaders([
                    'x-api-version' => $this->apiVersion,
                    'x-content-type' => $contentType,
                    'x-add-random-suffix' => '0',
                    'x-cache-control-max-age' => '31536000',
                    'Content-Length' => (string) filesize($localPath),
                ])
                ->timeout($this->timeout)
                ->withBody($stream, $contentType)
                ->put($this->baseUrl.'/'.ltrim($pathname, '/'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                "Vercel Blob upload gagal (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 200)
            );
        }

        $url = $response->json('url');
        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Vercel Blob upload tidak mengembalikan url');
        }

        return $url;
    }

    /**
     * Unduh blob ke berkas lokal. URL blob bersifat publik sehingga tidak
     * perlu token untuk membacanya.
     */
    public function download(string $url, string $destination): int
    {
        $dir = dirname($destination);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("Gagal membuat direktori tujuan: {$dir}");
        }

        $response = Http::timeout((int) config('embedding_models.download_timeout', 900))
            ->sink($destination)
            ->get($url);

        if (! $response->successful()) {
            @unlink($destination);
            throw new RuntimeException("Unduhan dari blob gagal (HTTP {$response->status()})");
        }

        return (int) (filesize($destination) ?: 0);
    }

    /**
     * Hapus satu atau beberapa blob berdasarkan URL-nya.
     *
     * @param  array<int, string>  $urls
     */
    public function delete(array $urls): void
    {
        $this->assertConfigured();

        if (empty($urls)) {
            return;
        }

        $response = Http::withToken($this->token)
            ->withHeaders(['x-api-version' => $this->apiVersion])
            ->timeout($this->timeout)
            ->asJson()
            ->post($this->baseUrl.'/delete', ['urls' => array_values($urls)]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Vercel Blob delete gagal (HTTP {$response->status()}): ".mb_substr($response->body(), 0, 200)
            );
        }
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Token Vercel Blob belum diset. Isi priva_READ_WRITE_TOKEN di .env backend.'
            );
        }
    }
}
