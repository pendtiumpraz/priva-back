<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VectorSearchService — semantic similarity search atas tabel `vector_embeddings`
 * dengan tenant isolation MUTLAK via `$orgId` filter (defense layer 2).
 *
 * Cross-driver safety: hanya berjalan di Postgres (pgvector). Di SQLite/MySQL
 * return [] + log warning supaya feature graceful degrade.
 *
 * @see docs/RAG_IMPLEMENTATION_SPEC.md (section "VectorSearchService" + "Cross-Tenant Isolation Rules")
 */
class VectorSearchService
{
    public function __construct(private EmbeddingService $embedding) {}

    /**
     * Semantic search across vector_embeddings for one org.
     *
     * SECURITY: $orgId is MANDATORY first parameter (tenant isolation).
     * Filter: WHERE org_id = $orgId — defense layer 2 (RLS = layer 1, BelongsToOrg = layer 3).
     *
     * @param  string  $orgId          Tenant UUID (required, non-empty).
     * @param  string  $query          Natural-language query text to embed.
     * @param  int     $topK           Max results returned (after similarity filter).
     * @param  array   $sourceTypes    Optional whitelist of source_type values (e.g. ['ropa','dpia']).
     * @param  float   $minSimilarity  Minimum cosine similarity threshold (0..1), default 0.5.
     *
     * @return array<int, array{
     *   id: string,
     *   source_type: string,
     *   source_id: string,
     *   content_excerpt: string,
     *   similarity: float,
     *   metadata: array,
     * }>
     */
    public function search(
        string $orgId,
        string $query,
        int $topK = 5,
        array $sourceTypes = [],
        float $minSimilarity = 0.5
    ): array {
        if (empty($orgId)) {
            throw new \InvalidArgumentException('VectorSearchService: orgId is required (tenant isolation)');
        }

        if (! $this->isPostgres()) {
            Log::warning('VectorSearchService: non-Postgres driver detected, vector search unavailable', [
                'driver' => DB::getDriverName(),
                'org_id' => $orgId,
            ]);

            return [];
        }

        if (trim($query) === '') {
            return [];
        }

        // Defensive: set RLS context kalau dipanggil di luar HTTP request
        // (queue worker, console command). Saat di HTTP, middleware
        // SetCurrentOrgContext sudah set; SET ulang aman dan idempotent.
        $this->setRlsContext($orgId);

        $vec = $this->embedding->embed($query, $orgId);
        $vecStr = '['.implode(',', $vec).']';

        $hasSourceFilter = ! empty($sourceTypes);

        $sql = 'SELECT id, source_type, source_id, content_excerpt, metadata,
                       1 - (embedding <=> ?::vector) AS similarity
                FROM vector_embeddings
                WHERE org_id = ?
                  AND deleted_at IS NULL
                  '.($hasSourceFilter ? 'AND source_type = ANY(?)' : '').'
                ORDER BY embedding <=> ?::vector
                LIMIT ?';

        $bindings = $hasSourceFilter
            ? [$vecStr, $orgId, '{'.implode(',', $sourceTypes).'}', $vecStr, $topK]
            : [$vecStr, $orgId, $vecStr, $topK];

        $rows = DB::select($sql, $bindings);

        $filtered = array_filter(
            $rows,
            fn ($r) => isset($r->similarity) && (float) $r->similarity >= $minSimilarity
        );

        return array_values(array_map(fn ($r) => $this->normalizeRow($r), $filtered));
    }

    /**
     * Find records related to an existing record (e.g. RoPAs mirip dengan RoPA X).
     * Mengambil embedding row sumber, lalu run similarity search dalam org yang sama
     * (exclude row sumber dari hasil).
     *
     * @return array<int, array{
     *   id: string,
     *   source_type: string,
     *   source_id: string,
     *   content_excerpt: string,
     *   similarity: float,
     *   metadata: array,
     * }>
     */
    public function findRelated(
        string $orgId,
        string $sourceType,
        string $sourceId,
        int $topK = 5
    ): array {
        if (empty($orgId)) {
            throw new \InvalidArgumentException('VectorSearchService: orgId is required (tenant isolation)');
        }

        if (! $this->isPostgres()) {
            Log::warning('VectorSearchService::findRelated: non-Postgres driver detected, vector search unavailable', [
                'driver' => DB::getDriverName(),
                'org_id' => $orgId,
            ]);

            return [];
        }

        // Defensive: set RLS context (sama alasan dengan search()).
        $this->setRlsContext($orgId);

        // Ambil embedding row sumber. WHERE org_id WAJIB supaya tenant tidak
        // bisa probe embedding dari org lain via crafted source_id.
        $source = DB::selectOne(
            'SELECT id, embedding::text AS embedding_text
             FROM vector_embeddings
             WHERE org_id = ?
               AND source_type = ?
               AND source_id = ?
               AND deleted_at IS NULL
             LIMIT 1',
            [$orgId, $sourceType, $sourceId]
        );

        if (! $source || empty($source->embedding_text)) {
            return [];
        }

        $vecStr = $source->embedding_text;
        $excludeId = $source->id;

        // Fetch topK + 1 lalu drop row sumber, supaya kalau row sumber muncul
        // di top hasil (similarity = 1.0 thd dirinya sendiri) kita tetap dapat topK.
        $sql = 'SELECT id, source_type, source_id, content_excerpt, metadata,
                       1 - (embedding <=> ?::vector) AS similarity
                FROM vector_embeddings
                WHERE org_id = ?
                  AND deleted_at IS NULL
                  AND id <> ?
                ORDER BY embedding <=> ?::vector
                LIMIT ?';

        $rows = DB::select($sql, [$vecStr, $orgId, $excludeId, $vecStr, $topK]);

        return array_values(array_map(fn ($r) => $this->normalizeRow($r), $rows));
    }

    /**
     * Normalize raw DB row → return shape contract.
     */
    private function normalizeRow(object $row): array
    {
        $metadata = [];
        if (isset($row->metadata) && $row->metadata !== null) {
            if (is_array($row->metadata)) {
                $metadata = $row->metadata;
            } elseif (is_string($row->metadata)) {
                $decoded = json_decode($row->metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
        }

        return [
            'id' => (string) ($row->id ?? ''),
            'source_type' => (string) ($row->source_type ?? ''),
            'source_id' => (string) ($row->source_id ?? ''),
            'content_excerpt' => (string) ($row->content_excerpt ?? ''),
            'similarity' => isset($row->similarity) ? (float) $row->similarity : 0.0,
            'metadata' => $metadata,
        ];
    }

    /**
     * Driver gate — pgvector hanya tersedia di Postgres.
     */
    private function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /**
     * Set Postgres RLS session variable supaya policy
     * `vector_embeddings_tenant_isolation` punya konteks. Idempotent —
     * aman dipanggil meski HTTP middleware sudah set sebelumnya.
     *
     * Kalau service dipanggil dari queue worker / console command /
     * scheduled task yang tidak lewat SetCurrentOrgContext middleware,
     * helper ini guarantee RLS aktif.
     */
    private function setRlsContext(string $orgId): void
    {
        try {
            DB::statement("SELECT set_config('app.current_org_id', ?, false)", [$orgId]);
        } catch (\Throwable $e) {
            Log::warning('VectorSearchService: failed to set RLS context', [
                'org_id' => $orgId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
