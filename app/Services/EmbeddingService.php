<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * EmbeddingService
 *
 * Generates vector embeddings for text content via a switchable provider
 * (TEI / OpenAI / Cohere). Used by the RAG pipeline:
 *   - VectorSearchService: embeds the user query before pgvector search
 *   - EmbedRecordJob: embeds RoPA/DPIA/Breach/KB content for indexing
 *
 * Cross-tenant safety:
 *   Cache key is keyed by sha256($orgId . '|' . $provider . '|' . $model . '|' . $text).
 *   Tenant A and Tenant B can have identical text but will NEVER share a cache
 *   entry — preventing leakage if the embedding ever encodes tenant-private
 *   context (e.g. hashed PII). $orgId == null also has its own bucket.
 *
 * Provider notes:
 *   - TEI (Text Embeddings Inference, self-hosted bge-m3): batch up to 128.
 *   - OpenAI (text-embedding-3-small): batch up to 2048.
 *   - Cohere (embed-multilingual-v3.0): batch up to 96.
 */
class EmbeddingService
{
    /**
     * Maximum characters per single text input. Beyond this the request is
     * rejected — caller must chunk first (config('ai_embedding.chunk_size_chars')).
     */
    private const MAX_TEXT_CHARS = 8000;

    /**
     * Per-provider hard batch caps (provider-side limits, not config-tunable).
     *
     * @var array<string, int>
     */
    private const BATCH_LIMITS = [
        'tei' => 128,
        'openai' => 2048,
        'cohere' => 96,
    ];

    private string $provider;

    /**
     * Resolved provider config block (base_url, model, dimension, timeout, api_key).
     *
     * @var array<string, mixed>
     */
    private array $config;

    private bool $enabled;

    private int $cacheTtl;

    public function __construct()
    {
        $this->enabled = (bool) config('ai_embedding.enabled', false);
        $this->provider = (string) config('ai_embedding.provider', 'tei');
        $this->cacheTtl = (int) config('ai_embedding.cache_ttl_seconds', 86400 * 30);

        $providerConfig = config('ai_embedding.'.$this->provider);
        if (! is_array($providerConfig)) {
            $providerConfig = [];
        }
        $this->config = $providerConfig;
    }

    /**
     * Embed single text. Returns float[] of length getDimension().
     *
     * Throws RuntimeException if RAG disabled, provider down, or text empty/too long.
     *
     * @return array<int, float>
     */
    public function embed(string $text, ?string $orgId = null): array
    {
        $this->assertEnabled();
        $this->assertValidText($text);

        $cacheKey = $this->cacheKey($orgId, $text);

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($text, $orgId) {
            $batch = $this->callProvider([$text], $orgId);

            if (empty($batch) || ! isset($batch[0])) {
                throw new RuntimeException('Embedding provider returned empty result');
            }

            return $batch[0];
        });
    }

    /**
     * Batch embed for efficiency. Splits into provider-appropriate chunks
     * (TEI 128, OpenAI 2048, Cohere 96) and concatenates results in input order.
     *
     * Each text is cached individually so a partial-overlap batch (some new,
     * some seen) only sends the new ones to the provider.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function batchEmbed(array $texts, ?string $orgId = null): array
    {
        $this->assertEnabled();

        if (empty($texts)) {
            return [];
        }

        // Validate all upfront — fail fast before any HTTP call.
        foreach ($texts as $i => $text) {
            if (! is_string($text)) {
                throw new RuntimeException("Batch input #{$i} is not a string");
            }
            $this->assertValidText($text);
        }

        // Resolve from cache where possible, build list of misses for provider call.
        $results = array_fill(0, count($texts), null);
        $missIndexes = [];
        $missTexts = [];
        $missCacheKeys = [];

        foreach ($texts as $i => $text) {
            $key = $this->cacheKey($orgId, $text);
            $cached = Cache::get($key);
            if (is_array($cached)) {
                $results[$i] = $cached;
            } else {
                $missIndexes[] = $i;
                $missTexts[] = $text;
                $missCacheKeys[] = $key;
            }
        }

        if (empty($missTexts)) {
            /** @var array<int, array<int, float>> $results */
            return $results;
        }

        $batchLimit = self::BATCH_LIMITS[$this->provider] ?? 32;

        // Chunk by provider limit, embed, fill results, cache each.
        $cursor = 0;
        foreach (array_chunk($missTexts, $batchLimit, true) as $chunk) {
            // array_chunk with preserve_keys=true keeps original miss-list indexes.
            $chunkValues = array_values($chunk);
            $embeddings = $this->callProvider($chunkValues, $orgId);

            if (count($embeddings) !== count($chunkValues)) {
                throw new RuntimeException(sprintf(
                    'Embedding provider returned %d vectors for %d inputs',
                    count($embeddings),
                    count($chunkValues)
                ));
            }

            foreach ($embeddings as $j => $vector) {
                $originalIndex = $missIndexes[$cursor];
                $cacheKey = $missCacheKeys[$cursor];
                $results[$originalIndex] = $vector;
                Cache::put($cacheKey, $vector, $this->cacheTtl);
                $cursor++;
            }
        }

        /** @var array<int, array<int, float>> $results */
        return $results;
    }

    public function getDimension(): int
    {
        return (int) ($this->config['dimension'] ?? 1024);
    }

    public function getProviderName(): string
    {
        return $this->provider;
    }

    public function getModelName(): string
    {
        return (string) ($this->config['model'] ?? '');
    }

    /**
     * Lightweight HTTP health check. Used by admin UI to render status badge.
     * Does NOT consume credits / quota — only probes the endpoint reachability.
     */
    public function isAvailable(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        try {
            return match ($this->provider) {
                'tei' => $this->probeTei(),
                'openai' => $this->probeOpenAi(),
                'cohere' => $this->probeCohere(),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning('[EmbeddingService] health check failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ------------------------------------------------------------------
    // Internal: provider dispatch
    // ------------------------------------------------------------------

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function callProvider(array $texts, ?string $orgId): array
    {
        return match ($this->provider) {
            'tei' => $this->callTei($texts, $orgId),
            'openai' => $this->callOpenAi($texts, $orgId),
            'cohere' => $this->callCohere($texts, $orgId),
            default => throw new RuntimeException("Unknown embedding provider: {$this->provider}"),
        };
    }

    /**
     * TEI (Text Embeddings Inference, HuggingFace self-hosted).
     * POST {base_url}/embed   body: {"inputs": [text, ...]}
     * Response: [[float,...], [float,...]]  (array of embeddings, in input order)
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function callTei(array $texts, ?string $orgId): array
    {
        $baseUrl = $this->requireConfig('base_url');
        $timeout = (int) ($this->config['timeout'] ?? 30);

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($baseUrl, '/').'/embed', [
                'inputs' => $texts,
            ]);

        if (! $response->successful()) {
            Log::warning('[EmbeddingService] TEI request failed', [
                'org_id' => $orgId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);
            throw new RuntimeException("TEI embedding provider returned HTTP {$response->status()}");
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('TEI response was not a JSON array');
        }

        // TEI returns either [[...],[...]] or wrapped under a key — normalize.
        if (isset($body['embeddings']) && is_array($body['embeddings'])) {
            $body = $body['embeddings'];
        }

        return array_map(
            fn ($vec) => $this->toFloatArray($vec, 'tei'),
            $body
        );
    }

    /**
     * OpenAI Embeddings API.
     * POST {base_url}/embeddings  headers: Authorization: Bearer <key>
     * Body: {"model": "...", "input": [text, ...]}
     * Response: {"data": [{"embedding": [...]}, ...]}
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function callOpenAi(array $texts, ?string $orgId): array
    {
        $baseUrl = $this->requireConfig('base_url');
        $apiKey = $this->requireConfig('api_key');
        $model = $this->requireConfig('model');
        $timeout = (int) ($this->config['timeout'] ?? 30);

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($baseUrl, '/').'/embeddings', [
                'model' => $model,
                'input' => $texts,
            ]);

        if (! $response->successful()) {
            Log::warning('[EmbeddingService] OpenAI request failed', [
                'org_id' => $orgId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);
            throw new RuntimeException("OpenAI embedding provider returned HTTP {$response->status()}");
        }

        $data = $response->json('data');
        if (! is_array($data)) {
            throw new RuntimeException('OpenAI response missing "data" array');
        }

        $out = [];
        foreach ($data as $item) {
            if (! isset($item['embedding']) || ! is_array($item['embedding'])) {
                throw new RuntimeException('OpenAI response item missing "embedding" array');
            }
            $out[] = $this->toFloatArray($item['embedding'], 'openai');
        }

        return $out;
    }

    /**
     * Cohere Embed API.
     * POST {base_url}/embed   headers: Authorization: Bearer <key>
     * Body: {"model": "...", "texts": [...], "input_type": "search_document"}
     * Response: {"embeddings": [[...],[...]]}  (or {"embeddings": {"float": [...]}} on v2)
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    private function callCohere(array $texts, ?string $orgId): array
    {
        $baseUrl = $this->requireConfig('base_url');
        $apiKey = $this->requireConfig('api_key');
        $model = $this->requireConfig('model');
        $timeout = (int) ($this->config['timeout'] ?? 30);

        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post(rtrim($baseUrl, '/').'/embed', [
                'model' => $model,
                'texts' => $texts,
                'input_type' => 'search_document',
            ]);

        if (! $response->successful()) {
            Log::warning('[EmbeddingService] Cohere request failed', [
                'org_id' => $orgId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 300),
            ]);
            throw new RuntimeException("Cohere embedding provider returned HTTP {$response->status()}");
        }

        $embeddings = $response->json('embeddings');

        // Cohere v2 may return {"float": [...]} under embeddings
        if (is_array($embeddings) && isset($embeddings['float']) && is_array($embeddings['float'])) {
            $embeddings = $embeddings['float'];
        }

        if (! is_array($embeddings)) {
            throw new RuntimeException('Cohere response missing "embeddings" array');
        }

        return array_map(
            fn ($vec) => $this->toFloatArray($vec, 'cohere'),
            $embeddings
        );
    }

    // ------------------------------------------------------------------
    // Internal: health probes (very cheap — single 1-char embed or HEAD)
    // ------------------------------------------------------------------

    private function probeTei(): bool
    {
        $baseUrl = (string) ($this->config['base_url'] ?? '');
        if ($baseUrl === '') {
            return false;
        }

        // TEI exposes /health endpoint per HuggingFace TEI server convention.
        $response = Http::timeout(5)->get(rtrim($baseUrl, '/').'/health');

        return $response->successful();
    }

    private function probeOpenAi(): bool
    {
        // Treat as available if api_key configured. Actual auth tested lazily
        // on first call to avoid burning rate-limit on health checks.
        return ! empty($this->config['api_key']) && ! empty($this->config['base_url']);
    }

    private function probeCohere(): bool
    {
        return ! empty($this->config['api_key']) && ! empty($this->config['base_url']);
    }

    // ------------------------------------------------------------------
    // Internal: helpers
    // ------------------------------------------------------------------

    private function assertEnabled(): void
    {
        if (! $this->enabled) {
            throw new RuntimeException('RAG disabled in config (ai_embedding.enabled=false)');
        }
    }

    private function assertValidText(string $text): void
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new RuntimeException('Embedding input text is empty');
        }

        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            Log::warning('[EmbeddingService] input text too long', [
                'length' => mb_strlen($text),
                'limit' => self::MAX_TEXT_CHARS,
            ]);
            throw new RuntimeException(sprintf(
                'Embedding input text exceeds %d chars (got %d). Chunk before calling.',
                self::MAX_TEXT_CHARS,
                mb_strlen($text)
            ));
        }
    }

    /**
     * Build cache key keyed by tenant, provider, model and content. The orgId
     * component prevents cross-tenant cache pollution: tenant A and tenant B
     * embedding the literal same string still hit separate cache entries.
     */
    private function cacheKey(?string $orgId, string $text): string
    {
        $hash = hash('sha256', ($orgId ?? '__noorg__').'|'.$this->provider.'|'.$this->getModelName().'|'.$text);

        return 'embed:'.$hash;
    }

    /**
     * Coerce an array of mixed numeric values to a flat array<int, float>.
     */
    private function toFloatArray(mixed $vec, string $providerLabel): array
    {
        if (! is_array($vec)) {
            throw new RuntimeException("[{$providerLabel}] embedding entry is not an array");
        }

        $out = [];
        foreach ($vec as $v) {
            if (! is_numeric($v)) {
                throw new RuntimeException("[{$providerLabel}] embedding contains non-numeric value");
            }
            $out[] = (float) $v;
        }

        return $out;
    }

    /**
     * Pull a required key from the provider config or throw a clear error.
     */
    private function requireConfig(string $key): string
    {
        $value = $this->config[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'Missing config ai_embedding.%s.%s — set env var or update config/ai_embedding.php',
                $this->provider,
                $key
            ));
        }

        return $value;
    }
}
