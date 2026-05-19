# RAG Implementation Spec — Shared Reference

Spec ini di-baca oleh semua agent yang implement RAG infrastructure. Setiap
agent dapat task file spesifik dan harus FOLLOW SPEC INI VERBATIM supaya
hasil dari 20 parallel agents bisa di-assemble tanpa konflik signature.

## Architecture

```
[User Query] → Laravel → EmbeddingService → TEI/OpenAI → vector
                      → VectorSearchService → pgvector WHERE org_id
                      → LLM with retrieved context
```

## Database Schema

**Table: `vector_embeddings`** (Postgres prod, SQLite test SKIP via Schema::hasTable guard)

```sql
CREATE EXTENSION IF NOT EXISTS vector;

CREATE TABLE vector_embeddings (
    id UUID PRIMARY KEY,
    org_id UUID NOT NULL,
    source_type VARCHAR(50) NOT NULL,
    source_id UUID NOT NULL,
    content_hash CHAR(64) NOT NULL,
    embedding vector(1024),                  -- bge-m3 dimension
    content_excerpt TEXT NOT NULL,
    metadata JSONB,
    embedding_provider VARCHAR(50),
    embedding_model VARCHAR(100),
    embedding_version INT DEFAULT 1,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP NULL
);

CREATE INDEX vector_embeddings_org_source_idx ON vector_embeddings(org_id, source_type);
CREATE INDEX vector_embeddings_lookup_idx ON vector_embeddings(org_id, source_type, source_id);
CREATE UNIQUE INDEX vector_embeddings_hash_unique ON vector_embeddings(org_id, source_type, source_id, content_hash) WHERE deleted_at IS NULL;
CREATE INDEX vector_embeddings_embedding_idx ON vector_embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100);
```

**source_type enum values:**
- `ropa` — RoPA records
- `dpia` — DPIA records
- `breach` — Breach incidents
- `vendor` — Vendor assessments
- `kb` — Knowledge base articles
- `pasal_uu_pdp` — Pasal UU PDP reference (global, org_id = system org)
- `contract` — Contract review
- `policy` — Policy review

## File List (each agent gets ONE)

### Backend (PHP)

| # | Path | Type |
|---|---|---|
| 1 | `backend/database/migrations/2026_05_19_120001_create_vector_embeddings_table.php` | new |
| 2 | `backend/app/Models/VectorEmbedding.php` | new |
| 3 | `backend/app/Services/EmbeddingService.php` | new |
| 4 | `backend/app/Services/VectorSearchService.php` | new |
| 5 | `backend/app/Jobs/EmbedRecordJob.php` | new |
| 6 | `backend/app/Console/Commands/EmbeddingsBackfillCommand.php` | new |
| 7 | `backend/config/ai_embedding.php` | new |
| 8 | `backend/app/Observers/RopaEmbeddingObserver.php` | new |
| 9 | `backend/app/Observers/DpiaEmbeddingObserver.php` | new |
| 10 | `backend/app/Observers/BreachEmbeddingObserver.php` | new |
| 11 | `backend/app/Observers/VendorEmbeddingObserver.php` | new |
| 12 | `backend/app/Observers/KbEmbeddingObserver.php` | new |
| 13 | `backend/database/migrations/2026_05_19_120002_enable_rls_on_vector_embeddings.php` | new |
| 14 | `backend/app/Providers/AppServiceProvider.php` | edit (append) |
| 15 | `backend/app/Services/AiAgentToolExecutor.php` | edit (add tools + system prompt) |
| 16 | `backend/app/Http/Controllers/Api/Admin/EmbeddingStatsController.php` + routes | new + edit |
| 17 | `backend/tests/Feature/VectorSearchTenantIsolationTest.php` | new |
| 18 | `backend/docs/RAG_IMPLEMENTATION.md` | new |

### Frontend (TS)

| # | Path | Type |
|---|---|---|
| 19 | `frontend/src/app/(dashboard)/platform-admin/embeddings/page.tsx` | new |
| 20 | `frontend/src/app/(dashboard)/settings/page.tsx` AI Embedding section | edit |

## Service Signatures (LOCKED — agents must follow)

### EmbeddingService

```php
namespace App\Services;

class EmbeddingService
{
    public function __construct() {
        // Read config from config('ai_embedding')
    }

    /**
     * Embed single text. Returns float array matching provider dimension.
     * Throws RuntimeException if provider down or text empty.
     *
     * Cache key includes org_id (when supplied) untuk mencegah cross-tenant
     * cache pollution. content hash + provider model jadi part of key.
     */
    public function embed(string $text, ?string $orgId = null): array;

    /**
     * Batch embed for efficiency (TEI supports up to 128 in one request).
     */
    public function batchEmbed(array $texts, ?string $orgId = null): array;

    /** Vector dimension (1024 for bge-m3, 1536 for OpenAI text-embedding-3-small). */
    public function getDimension(): int;

    /** Provider identifier for audit log + storage column. */
    public function getProviderName(): string;

    /** Model identifier (e.g. 'bge-m3', 'text-embedding-3-small'). */
    public function getModelName(): string;

    /** Health check — used by admin UI to display status. */
    public function isAvailable(): bool;
}
```

### VectorSearchService

```php
namespace App\Services;

use App\Services\EmbeddingService;

class VectorSearchService
{
    public function __construct(private EmbeddingService $embedding) {}

    /**
     * Semantic search across vector_embeddings for one org.
     *
     * SECURITY: $orgId is MANDATORY first parameter (impossible to forget).
     * Filter: WHERE org_id = $orgId — defense layer 2.
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
    ): array;

    /**
     * Find records related to an existing record (e.g. RoPAs mirip dengan
     * RoPA X). Looks up X's embedding then runs similarity within same org.
     */
    public function findRelated(
        string $orgId,
        string $sourceType,
        string $sourceId,
        int $topK = 5
    ): array;
}
```

### EmbedRecordJob

```php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;

class EmbedRecordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public string $orgId,
        public string $sourceType,
        public string $sourceId,
        public string $content,
        public array $metadata = [],
    ) {}

    public function handle(\App\Services\EmbeddingService $svc): void;
}
```

## Cross-Tenant Isolation Rules

ALL agents must follow these rules:

1. **`org_id` filter MANDATORY** di setiap query vector_embeddings (`WHERE org_id = ?`)
2. **Service signature** `search(string $orgId, ...)` — `$orgId` is first parameter, type-hinted, non-nullable
3. **Model uses BelongsToOrg trait** — auto global scope filter
4. **Cache key** includes `org_id` hash component
5. **AI Agent tools** use `$this->orgId` from constructor (existing pattern di AiAgentToolExecutor)
6. **Test wajib** — feature test `VectorSearchTenantIsolationTest` must verify:
   - Tenant A query → cannot retrieve tenant B's embeddings
   - Even with raw query bypass attempt (SQL injection style), RLS blocks
   - BelongsToOrg global scope active

## Config Structure

```php
// config/ai_embedding.php
return [
    'enabled' => env('AI_EMBEDDING_ENABLED', false),
    'provider' => env('AI_EMBEDDING_PROVIDER', 'tei'),

    'tei' => [
        'base_url' => env('AI_EMBEDDING_TEI_URL', 'http://privasimu-embeddings:80'),
        'model' => 'bge-m3',
        'dimension' => 1024,
        'timeout' => 30,
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_EMBEDDING_OPENAI_MODEL', 'text-embedding-3-small'),
        'dimension' => 1536,
        'timeout' => 30,
    ],
    'cohere' => [
        'api_key' => env('COHERE_API_KEY'),
        'base_url' => 'https://api.cohere.ai/v1',
        'model' => 'embed-multilingual-v3.0',
        'dimension' => 1024,
        'timeout' => 30,
    ],

    'cache_ttl_seconds' => 86400 * 30,
    'chunk_size_chars' => 1000,
    'chunk_overlap_chars' => 200,
    'batch_size' => 32,
    'rate_limit_per_minute' => env('AI_EMBEDDING_RATE_LIMIT', 100),
];
```

## Migration Pattern (Idempotent for Cross-Env)

ALL migrations MUST be idempotent. Pattern:

```php
public function up(): void
{
    if (Schema::hasTable('vector_embeddings')) return;

    // Postgres-only: enable pgvector extension
    if (DB::getDriverName() === 'pgsql') {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
    } else {
        // SQLite / MySQL: skip vector column, table tetap dibuat tanpa pgvector.
        // RAG features disabled di environment tersebut (via config check).
        \Log::info('Skipping pgvector extension on non-Postgres driver');
    }

    Schema::create('vector_embeddings', function (Blueprint $table) {
        // ...
    });
}
```

## AI Agent Tools (added to AiAgentToolExecutor)

New cases di `execute()` match expression:

```php
'search_similar_ropa' => $this->searchSimilarRopa($args),
'search_similar_dpia' => $this->searchSimilarDpia($args),
'search_similar_breach' => $this->searchSimilarBreach($args),
'search_knowledge_base' => $this->searchKb($args),
'find_related_records' => $this->findRelatedRecords($args),
```

Each method:
```php
private function searchSimilarRopa(array $args): array
{
    $query = $args['query'] ?? '';
    $topK = min(10, (int) ($args['top_k'] ?? 5));

    // $this->orgId from constructor — defense layer 4
    return app(VectorSearchService::class)
        ->search($this->orgId, $query, $topK, ['ropa']);
}
```

Tool definitions added to `defineTools()` return array — see existing pattern at line ~924 of AiAgentToolExecutor.

## System Prompt Update

In AiAgentController.php (~line 317-323), add RAG-aware instruction to system prompt rules:

```
16. SEMANTIC SEARCH FIRST: Untuk pertanyaan "mirip apa", "ada yang serupa", "kasus
    sejenis", PRIORITAS pakai tool search_similar_* dulu sebelum list_* —
    semantic search lebih relevan daripada exact filter.
17. CITE RETRIEVED CONTEXT: Saat jawab berdasarkan retrieved chunks, sebutkan
    source_id atau registration_number-nya supaya user bisa verify.
```

## End of spec — agents proceed to their assigned files.
