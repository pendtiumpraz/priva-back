<?php

namespace App\Jobs;

use App\Services\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RAG Infrastructure — Background embedding job.
 *
 * Dispatched oleh model observers (RoPA, DPIA, Breach, Vendor, KB) saat
 * record created/updated. Job:
 *   1. Skip kalau AI Embedding fitur disabled di config
 *   2. Skip kalau driver bukan Postgres (pgvector dependency)
 *   3. Compute content hash; idempotent — skip kalau row identical sudah exist
 *   4. Chunk content kalau melebihi chunk_size_chars
 *   5. Embed setiap chunk via EmbeddingService
 *   6. Insert ke vector_embeddings, org_id-scoped
 *
 * SECURITY: $orgId di-pass eksplisit ke constructor + tersimpan di payload
 * job. Worker tidak punya HTTP context, jadi tenant scoping bergantung pada
 * data ini — JANGAN remove dari signature.
 *
 * Tries: 3 dengan exponential backoff (30s, 2m, 10m).
 * Timeout: 60 detik per chunk batch.
 */
class EmbedRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 60;

    public function __construct(
        public string $orgId,
        public string $sourceType,
        public string $sourceId,
        public string $content,
        public array $metadata = [],
    ) {}

    public function handle(EmbeddingService $svc): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        // pgvector required — skip on SQLite/MySQL silently
        if (DB::connection()->getDriverName() !== 'pgsql') {
            Log::info('EmbedRecordJob skipped: vector storage requires pgsql', [
                'org_id' => $this->orgId,
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'driver' => DB::connection()->getDriverName(),
            ]);
            return;
        }

        // Set RLS context — queue worker tidak lewat HTTP middleware, jadi
        // SetCurrentOrgContext middleware tidak fire. Tanpa SET ini, RLS
        // policy vector_embeddings_tenant_isolation reject INSERT (org_id::text
        // tidak match current_setting('app.current_org_id', true) yang null).
        try {
            DB::statement("SELECT set_config('app.current_org_id', ?, false)", [$this->orgId]);
        } catch (\Throwable $e) {
            Log::warning('EmbedRecordJob: failed to set RLS context', [
                'org_id' => $this->orgId,
                'error' => $e->getMessage(),
            ]);
        }

        $content = trim($this->content);
        if ($content === '') {
            return;
        }

        $chunkSize = (int) config('ai_embedding.chunk_size_chars', 1000);
        $overlap = (int) config('ai_embedding.chunk_overlap_chars', 200);
        $chunks = $this->chunkContent($content, $chunkSize, $overlap);

        foreach ($chunks as $chunkIndex => $chunk) {
            $contentHash = hash('sha256', $chunk);

            // Idempotency check — same (org, source, content_hash) already embedded
            $exists = DB::table('vector_embeddings')
                ->where('org_id', $this->orgId)
                ->where('source_type', $this->sourceType)
                ->where('source_id', $this->sourceId)
                ->where('content_hash', $contentHash)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                Log::info('EmbedRecordJob chunk skipped (already embedded)', [
                    'org_id' => $this->orgId,
                    'source_type' => $this->sourceType,
                    'source_id' => $this->sourceId,
                    'chunk_index' => $chunkIndex,
                    'content_hash' => $contentHash,
                ]);
                continue;
            }

            try {
                $vector = $svc->embed($chunk, $this->orgId);
            } catch (\Throwable $e) {
                Log::warning('EmbedRecordJob embed call failed', [
                    'org_id' => $this->orgId,
                    'source_type' => $this->sourceType,
                    'source_id' => $this->sourceId,
                    'chunk_index' => $chunkIndex,
                    'error' => $e->getMessage(),
                ]);
                throw $e; // let queue retry
            }

            $excerpt = mb_substr($chunk, 0, 500);
            $chunkMetadata = array_merge($this->metadata, [
                'chunk_index' => $chunkIndex,
                'chunk_total' => count($chunks),
                'chunk_chars' => mb_strlen($chunk),
            ]);

            // Cast float array → pgvector string literal '[a,b,c]'
            $embeddingLiteral = '['.implode(',', array_map(
                static fn ($v) => is_numeric($v) ? (string) (float) $v : '0',
                $vector
            )).']';

            $now = now();
            DB::table('vector_embeddings')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $this->orgId,
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'content_hash' => $contentHash,
                'embedding' => $embeddingLiteral,
                'content_excerpt' => $excerpt,
                'metadata' => json_encode($chunkMetadata),
                'embedding_provider' => $svc->getProviderName(),
                'embedding_model' => $svc->getModelName(),
                'embedding_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Log::info('EmbedRecordJob chunk embedded', [
                'org_id' => $this->orgId,
                'source_type' => $this->sourceType,
                'source_id' => $this->sourceId,
                'chunk_index' => $chunkIndex,
                'chunk_total' => count($chunks),
                'provider' => $svc->getProviderName(),
                'model' => $svc->getModelName(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('EmbedRecordJob failed permanently', [
            'org_id' => $this->orgId,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    /**
     * Split content into overlapping chunks. Returns at least one chunk
     * (the original content) when length ≤ $chunkSize.
     *
     * @return array<int, string>
     */
    private function chunkContent(string $content, int $chunkSize, int $overlap): array
    {
        if ($chunkSize <= 0) {
            return [$content];
        }

        $length = mb_strlen($content);
        if ($length <= $chunkSize) {
            return [$content];
        }

        $overlap = max(0, min($overlap, $chunkSize - 1));
        $step = max(1, $chunkSize - $overlap);

        $chunks = [];
        for ($start = 0; $start < $length; $start += $step) {
            $chunks[] = mb_substr($content, $start, $chunkSize);
            if ($start + $chunkSize >= $length) {
                break;
            }
        }
        return $chunks;
    }
}
