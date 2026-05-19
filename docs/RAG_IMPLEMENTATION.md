# RAG Implementation Guide — Privasimu

> **Audience**: Developer backend Privasimu (Laravel) + DevOps yang akan deploy fitur Retrieval-Augmented Generation (RAG) ke environment SaaS, on-prem, atau hybrid. Dokumen ini turunan dari `RAG_IMPLEMENTATION_SPEC.md` (shared reference untuk parallel agents) dan menjelaskan **bagaimana** RAG diintegrasikan, dideploy, dioperasikan, dan diaudit.
>
> Untuk arsitektur hardware on-prem AI lengkap (vLLM + GPU sizing), lihat `D:\AI\privasimu\docs\PRIVASIMU_NEXUS_ONPREM_AI.md`. Untuk deployment Laravel stack on-prem (Postgres, MinIO, license), lihat `ONPREM_DEPLOY.md` di folder yang sama.

---

## 1. Overview

### 1.1 Apa Itu RAG di Privasimu

**Retrieval-Augmented Generation (RAG)** adalah pola arsitektur AI yang menggabungkan dua langkah:

1. **Retrieval** — Sebelum LLM menjawab, sistem mencari potongan konten relevan (chunks) dari knowledge store (vector database) menggunakan **semantic search** (kemiripan makna, bukan keyword match).
2. **Generation** — LLM dipanggil dengan prompt yang **sudah dilengkapi konteks** hasil retrieval. Jawaban LLM jadi grounded ke data nyata milik tenant, bukan halusinasi training-time.

Untuk Privasimu (compliance platform multi-tenant), RAG memberi tiga kapabilitas baru:

| Kapabilitas | Sebelum RAG | Dengan RAG |
|---|---|---|
| **Semantic search ROPA** | User harus tahu kata kunci persis (filter `purpose LIKE`) | "Cari aktivitas pemrosesan terkait nasabah prima" → AI mengerti maksud user |
| **KB Q&A** | User scroll manual artikel KB | "Apa kewajiban DPO menurut UU PDP?" → AI tarik artikel relevan + jawab dengan sitasi |
| **Doc grounding** | AI menjawab pakai training knowledge (bisa salah/outdated) | AI **harus** kutip pasal/dokumen yang ada di vector store milik tenant |

### 1.2 Use Cases Utama

1. **Semantic ROPA Search** — Compliance team cari aktivitas pemrosesan serupa lintas departemen tanpa hafal naming convention.
2. **DPIA Cross-Reference** — Otomatis surface DPIA terdahulu dengan profil risiko mirip saat menyusun DPIA baru.
3. **Breach Pattern Matching** — Saat incident baru masuk, AI surface incident lama yang containment-nya berhasil.
4. **Knowledge Base Q&A** — Tanya jawab natural language ke KB internal organisasi + Pasal UU PDP reference (global).
5. **Vendor Risk Prior Art** — Cari vendor assessment terdahulu dengan kategori risiko serupa.
6. **Contract / Policy Grounding** — AI review klausa kontrak berdasarkan policy internal yang sudah diembed.

### 1.3 Arsitektur 3-Tier

Privasimu RAG dibangun di atas tiga lapis yang dapat di-deploy independen:

- **Frontend (Next.js)** — UI untuk admin embeddings dashboard, tombol "cari mirip" di list halaman, KB Q&A chat.
- **Application (Laravel)** — `EmbeddingService`, `VectorSearchService`, `EmbedRecordJob`, observers, `AiAgentToolExecutor` integration.
- **Inference + Storage** — Embedding service (TEI bge-m3 on-prem atau OpenAI/Cohere cloud) + PostgreSQL dengan ekstensi `pgvector`.

---

## 2. Architecture Diagram

```
                                       ┌──────────────────────────────────┐
                                       │   PostgreSQL + pgvector          │
                                       │   ┌────────────────────────────┐ │
                                       │   │ vector_embeddings          │ │
                                       │   │ id UUID, org_id UUID,      │ │
                                       │   │ source_type, source_id,    │ │
                                       │   │ embedding vector(1024),    │ │
                                       │   │ content_excerpt, metadata  │ │
                                       │   │                            │ │
                                       │   │ RLS: org_id =              │ │
                                       │   │      current_setting(      │ │
                                       │   │        'app.current_org_id'│ │
                                       │   │      )                     │ │
                                       │   └────────────────────────────┘ │
                                       └────────────▲─────────────────────┘
                                                    │ vector similarity
                                                    │ (cosine, top-K)
                                                    │
   Browser                Next.js                Laravel API                 Embedding Service
 ┌─────────┐           ┌────────────┐         ┌──────────────────┐         ┌──────────────────┐
 │  User   │  HTTPS    │  App       │  HTTPS  │ /api/m/ropa      │   HTTP  │  TEI bge-m3      │
 │ browser │ ────────▶ │  Router    │────────▶│ /api/ai-agent/.. │ ──────▶ │  (on-prem 8080)  │
 │         │           │  (dashboard│         │ /api/kb/ask      │         │       OR         │
 │         │           │  group)    │         │                  │         │  OpenAI /v1/     │
 │         │           │            │         │ EmbeddingService │         │  embeddings      │
 │         │           │            │         │ VectorSearch     │         │       OR         │
 │         │           │            │         │ AiAgentTool      │         │  Cohere /v1/     │
 │         │           │            │         │   Executor       │         │  embed           │
 └─────────┘           └────────────┘         └─────┬────────────┘         └──────────────────┘
                                                    │
                                                    │ retrieved chunks (top_K)
                                                    │ + user query
                                                    ▼
                                          ┌──────────────────────┐
                                          │  LLM (DeepSeek /     │
                                          │  Qwen3-32B vLLM /    │
                                          │  Claude / OpenAI)    │
                                          │                      │
                                          │  → grounded answer   │
                                          │  + citations         │
                                          └──────────────────────┘
```

**Flow ringkas (KB Q&A example):**

1. User type pertanyaan di chat: *"Apa kewajiban DPO bila terjadi insiden kebocoran?"*
2. Next.js POST ke `/api/ai-agent/chat` dengan org context.
3. Laravel resolve `$orgId` dari Sanctum token → set `app.current_org_id` di koneksi DB.
4. AI Agent (LLM) decide tool call: `search_knowledge_base({query: "kewajiban DPO breach"})`.
5. `AiAgentToolExecutor::searchKb($args)` → `VectorSearchService::search($this->orgId, ...)`.
6. `EmbeddingService::embed($query)` → call TEI / OpenAI → dapat vector 1024-dim.
7. Postgres query: `SELECT ... FROM vector_embeddings WHERE org_id = $1 AND source_type IN ('kb', 'pasal_uu_pdp') ORDER BY embedding <=> $2 LIMIT 5`.
8. Top-5 chunks dikembalikan ke LLM sebagai konteks tambahan.
9. LLM jawab + sebutkan `source_id` chunks yang dipakai.
10. Frontend render jawaban + tombol "Lihat referensi" yang link ke KB article asli.

---

## 3. Multi-Tenant Defense (5 Layers)

**Cross-tenant data leak adalah risiko kritis platform.** RAG menambah satu tabel baru (`vector_embeddings`) yang menyimpan konten organisasi dalam bentuk vector. Tanpa isolasi yang ketat, semantic search bisa menarik vector tenant lain. Privasimu menerapkan **5 layer defense** yang bersifat **redundant** — kalau salah satu layer bocor, layer lain masih menahan.

### Layer 1 — Row-Level `org_id` Column

Setiap row di `vector_embeddings` punya kolom `org_id UUID NOT NULL`. Index gabungan `(org_id, source_type)` memastikan query selalu pakai org filter sebagai leading predicate. Kolom ini **non-nullable** — insert tanpa org_id akan ditolak di level DB constraint.

### Layer 2 — Service Signature Mandatory `$orgId`

```php
public function search(
    string $orgId,                  // ← MANDATORY first parameter, non-nullable
    string $query,
    int $topK = 5,
    array $sourceTypes = [],
    float $minSimilarity = 0.5
): array;
```

Karena `$orgId` adalah parameter posisi pertama dan non-nullable, **tidak mungkin lupa**. PHP akan error fatal kalau dipanggil tanpa `$orgId`. Pattern sama untuk `findRelated()` dan semua public method di `VectorSearchService`.

### Layer 3 — `BelongsToOrg` Trait (Eloquent Global Scope)

`App\Models\VectorEmbedding` pakai trait `BelongsToOrg` (sudah dipakai semua tenant-scoped model). Trait ini menambahkan **global scope** ke setiap query Eloquent yang auto-inject `WHERE org_id = <current org>`. Untuk bypass (misal admin job lintas org) harus eksplisit `withoutGlobalScope(BelongsToOrgScope::class)` — sehingga audit grep mudah ketemu.

### Layer 4 — `AiAgentToolExecutor` Org Scope

`AiAgentToolExecutor` di-construct dengan `$orgId` yang resolved dari Sanctum user. Semua tool RAG (`search_similar_ropa`, `search_knowledge_base`, dst) pakai `$this->orgId` saat panggil `VectorSearchService::search()`. AI tidak pernah dapat akses langsung ke controller atau raw SQL — semua mutasi & query lewat executor ini.

```php
private function searchSimilarRopa(array $args): array
{
    $query = $args['query'] ?? '';
    $topK = min(10, (int) ($args['top_k'] ?? 5));

    return app(VectorSearchService::class)
        ->search($this->orgId, $query, $topK, ['ropa']);
    //              ↑
    //   Diambil dari constructor — AI tidak bisa override
}
```

### Layer 5 — PostgreSQL Row-Level Security (RLS)

Ini adalah **safety net terakhir di level database**. Bahkan kalau aplikasi salah (developer lupa filter, atau ada SQL injection), Postgres akan reject row yang `org_id`-nya tidak match dengan session variable `app.current_org_id`.

```sql
ALTER TABLE vector_embeddings ENABLE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation_policy ON vector_embeddings
    USING (org_id::text = current_setting('app.current_org_id', true));
```

**Catatan kritis**: RLS aktif setelah migration `2026_05_19_120002_enable_rls_on_vector_embeddings`. Aplikasi **wajib** set `SET LOCAL app.current_org_id = '<uuid>'` di awal setiap request (atau koneksi DB). Kalau tidak diset, **semua row akan ditolak** dan search return 0 results. Lihat section [Migration Steps](#6-migration-steps) untuk detail middleware yang menanganinya.

> **Audit takeaway**: Untuk pass SOC 2 / ISO 27001 / POJK 11 audit, presentasikan 5 layer ini sebagai *defense in depth*. Layer 5 (RLS) khususnya powerful untuk meyakinkan auditor karena enforcement-nya terjadi di **storage engine** — di luar kontrol kode aplikasi.

---

## 4. Deployment Options

Privasimu RAG mendukung **tiga skenario deployment**. Pilih sesuai konstrain regulasi, budget, dan data residency klien.

### 4.1 Option A — On-Prem Penuh (Air-Gap Compatible)

**Target**: Bank tier-1, BUMN, instansi pemerintah, klien dengan kebijakan no-third-party-inference.

**Stack:**
- Embedding: **TEI (Text Embeddings Inference) + bge-m3** di server GPU klien (lihat `PRIVASIMU_NEXUS_ONPREM_AI.md` untuk hardware sizing)
- LLM: **vLLM + Qwen3-32B AWQ** di GPU server yang sama atau terpisah
- Database: **PostgreSQL + pgvector** di container `tenant-db` (sudah ada di `docker-compose.onprem.yml`)

**Setup steps:**

```bash
# 1. Aktifkan pgvector extension (otomatis via migration kalau Postgres detected)
docker compose -f backend/docker/docker-compose.onprem.yml exec tenant-db \
  psql -U privasimu_provisioner -c "CREATE EXTENSION IF NOT EXISTS vector;"

# 2. Deploy TEI embedding container (tambahkan ke ai-onprem/docker-compose.yml)
# Sudah tercakup di stack on-prem AI — lihat section 6.3 di PRIVASIMU_NEXUS_ONPREM_AI.md

# 3. Set env vars di backend/.env.onprem
cat >> backend/.env.onprem <<EOF
AI_EMBEDDING_ENABLED=true
AI_EMBEDDING_PROVIDER=tei
AI_EMBEDDING_TEI_URL=http://privasimu-embeddings:80
AI_EMBEDDING_RATE_LIMIT=100
EOF

# 4. Restart backend + queue worker
docker compose -f backend/docker/docker-compose.onprem.yml restart backend queue-worker

# 5. Run migrations (idempotent)
docker compose -f backend/docker/docker-compose.onprem.yml exec backend \
  php artisan migrate --force

# 6. Verify health
curl http://localhost/api/admin/embeddings/stats
```

**Keuntungan**: Zero data egress, full data residency Indonesia, sesuai UU PDP Pasal 56 (cross-border transfer).

**Trade-off**: Butuh GPU hardware (L40S 48GB minimum untuk bge-m3 + Qwen3-32B). Detail BOM di `PRIVASIMU_NEXUS_ONPREM_AI.md`.

### 4.2 Option B — Cloud Hybrid

**Target**: Klien menengah yang mau ROI cepat tanpa CAPEX hardware, tapi tetap on-prem untuk app/data layer.

**Stack:**
- Embedding: **OpenAI `text-embedding-3-small`** (1536-dim) atau **Cohere `embed-multilingual-v3.0`** (1024-dim)
- LLM: **DeepSeek API** (murah, support Bahasa Indonesia) atau **Claude / GPT-4** untuk kualitas
- Database: **PostgreSQL + pgvector** di on-prem (data tetap di klien)

**Setup steps:**

```bash
# 1. Set env vars
cat >> backend/.env <<EOF
AI_EMBEDDING_ENABLED=true
AI_EMBEDDING_PROVIDER=openai            # atau 'cohere'
OPENAI_API_KEY=sk-...
AI_EMBEDDING_OPENAI_MODEL=text-embedding-3-small
EOF

# 2. Migrate (vector dimension auto-detected dari config)
php artisan migrate --force

# 3. Setting via UI (alternatif env): Superadmin → AI Settings → Embedding Provider
```

**Catatan dimensi**: OpenAI `text-embedding-3-small` adalah **1536-dim**, bukan 1024 seperti bge-m3. Migration akan create kolom `embedding vector(1536)` kalau provider OpenAI dipilih sebelum migrate. Kalau ganti provider setelah backfill, butuh re-embed (akan invalidate cache lama — lihat [Troubleshooting](#10-troubleshooting)).

**Keuntungan**: Tidak butuh GPU, latency embedding ~50-100ms (network RTT ke API), kualitas multilingual bagus untuk Bahasa Indonesia.

**Trade-off**: Data text content dikirim ke OpenAI/Cohere (walaupun hanya excerpt 1000 char). Tidak cocok untuk klien yang ban third-party inference.

### 4.3 Option C — Full SaaS (Cloud-Only)

**Target**: SMB, fintech kecil, startup yang pakai shared SaaS Privasimu di AWS.

**Stack:**
- Embedding: **OpenAI** atau **Cohere** (managed by Privasimu, shared API key)
- LLM: **DeepSeek / OpenAI** (shared)
- Database: **AWS RDS Postgres dengan pgvector extension enabled**

**Setup steps:**

Untuk SaaS shared, embedding sudah pre-configured oleh Privasimu Engineering. Tenant cukup:

1. Login sebagai admin tenant.
2. **Settings → AI Features → Enable Embedding Search** (toggle on).
3. Run backfill untuk data existing: tombol **"Index existing records"** di admin UI (atau via support ticket).
4. Verify di `/admin/embeddings/stats`.

**Tidak ada akses env** — semua via system settings UI atau Privasimu support.

---

## 5. Configuration

### 5.1 `config/ai_embedding.php`

File config utama, dipublish saat `composer install` atau bisa di-customize:

```php
return [
    'enabled' => env('AI_EMBEDDING_ENABLED', false),
    'provider' => env('AI_EMBEDDING_PROVIDER', 'tei'),  // 'tei' | 'openai' | 'cohere'

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

    'cache_ttl_seconds' => 86400 * 30,        // 30 days cache embedding result
    'chunk_size_chars' => 1000,                // panjang chunk text yang di-embed
    'chunk_overlap_chars' => 200,              // overlap antar chunk
    'batch_size' => 32,                        // batch embed (TEI support s.d. 128)
    'rate_limit_per_minute' => env('AI_EMBEDDING_RATE_LIMIT', 100),
];
```

### 5.2 Env Vars Reference

| Env Var | Default | Deskripsi |
|---|---|---|
| `AI_EMBEDDING_ENABLED` | `false` | Master toggle. `false` → semua tool RAG di AI Agent disabled, observer skip embedding job dispatch. |
| `AI_EMBEDDING_PROVIDER` | `tei` | `tei` (on-prem) / `openai` (cloud) / `cohere` (cloud) |
| `AI_EMBEDDING_TEI_URL` | `http://privasimu-embeddings:80` | Endpoint TEI service (Docker network name di on-prem) |
| `OPENAI_API_KEY` | — | Required kalau `provider=openai` |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1` | Override untuk Azure OpenAI atau proxy |
| `AI_EMBEDDING_OPENAI_MODEL` | `text-embedding-3-small` | Model OpenAI. Bisa pakai `text-embedding-3-large` (3072-dim) untuk akurasi lebih tinggi |
| `COHERE_API_KEY` | — | Required kalau `provider=cohere` |
| `AI_EMBEDDING_RATE_LIMIT` | `100` | Request per menit ke embedding service (per org). Default mencegah cost spike OpenAI |

### 5.3 System Settings UI (Admin Toggle)

Sesuai prinsip **operational settings di DB, bukan env** (lihat `MEMORY.md`), embedding config juga dapat di-override via UI superadmin:

- Path: **Superadmin → Settings → AI Features**
- Section: **Embedding Provider**
- Fields:
  - Enabled (boolean)
  - Provider (dropdown: TEI / OpenAI / Cohere)
  - TEI URL (kalau provider=TEI)
  - API Key (kalau provider=OpenAI/Cohere, encrypted di DB)
  - Rate limit per minute

Settings di DB **override** env var. Restart tidak diperlukan — `EmbeddingService` re-read config setiap request (cached 60 detik).

---

## 6. Migration Steps

Ada **dua migration** yang harus dijalankan berurutan:

### 6.1 Migration 1 — Create Table + Extension + IVFFlat Index

File: `database/migrations/2026_05_19_120001_create_vector_embeddings_table.php`

```bash
php artisan migrate
```

Yang terjadi:

1. **Postgres**: `CREATE EXTENSION IF NOT EXISTS vector` — aktivasi pgvector.
2. Buat tabel `vector_embeddings` dengan kolom `embedding vector(1024)` atau `vector(1536)` sesuai provider di config.
3. Buat **IVFFlat index** untuk approximate nearest neighbor search:
   ```sql
   CREATE INDEX vector_embeddings_embedding_idx
       ON vector_embeddings USING ivfflat (embedding vector_cosine_ops)
       WITH (lists = 100);
   ```
4. **SQLite / MySQL**: Skip pgvector extension + skip vector column. Tabel tetap dibuat (struktur scaffold) tapi RAG features auto-disabled via config check `EmbeddingService::isAvailable()`. Cocok untuk dev local + test environment.

> **Catatan IVFFlat `lists`**: Default `lists = 100` cocok untuk < 1M rows per tabel. Untuk org dengan > 1M ROPA records, tune ke `lists = sqrt(N)` (e.g. 1000 untuk 1M rows). Rebuild index dengan `REINDEX INDEX vector_embeddings_embedding_idx`.

### 6.2 Migration 2 — Enable RLS Policy

File: `database/migrations/2026_05_19_120002_enable_rls_on_vector_embeddings.php`

```bash
php artisan migrate
```

Yang terjadi (Postgres only, di-skip untuk SQLite/MySQL):

```sql
ALTER TABLE vector_embeddings ENABLE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation_policy ON vector_embeddings
    USING (org_id::text = current_setting('app.current_org_id', true));

-- Bypass policy untuk superuser (untuk backfill, audit, dst)
CREATE POLICY superuser_bypass ON vector_embeddings
    AS PERMISSIVE
    FOR ALL
    TO privasimu_provisioner
    USING (current_setting('app.bypass_rls', true) = 'true');
```

### 6.3 Middleware `SetCurrentOrgContext` — Wajib

Setelah RLS aktif, **wajib ada middleware** yang set `app.current_org_id` di setiap request authenticated. Tanpa ini, **semua query vector_embeddings akan return 0 rows** karena RLS reject semua.

Lokasi: `app/Http/Middleware/SetCurrentOrgContext.php` (atau equivalent — cek `bootstrap/app.php` untuk middleware stack existing).

```php
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();

    if ($user && $user->org_id) {
        DB::statement("SET LOCAL app.current_org_id = ?", [$user->org_id]);
    }

    return $next($request);
}
```

**Critical detail — `SET LOCAL` vs `SET`:**
- `SET LOCAL` → scope ke transaksi current. Auto-reset setelah request selesai. ✅ **Pakai ini**.
- `SET` → scope ke session. Bisa bocor ke request berikutnya kalau connection di-reuse via pgbouncer. ❌ **Jangan**.

Pastikan setiap request mulai dengan `DB::beginTransaction()` (atau Laravel default request lifecycle yang sudah handle ini).

### 6.4 Verification

```bash
# 1. Check migration applied
php artisan migrate:status | grep vector_embeddings

# 2. Check RLS enabled (Postgres)
docker compose exec tenant-db psql -U privasimu_provisioner -d privasimu_landlord -c \
  "SELECT relname, relrowsecurity FROM pg_class WHERE relname = 'vector_embeddings';"
# Expected: relrowsecurity = true

# 3. Test isolation (run sebagai tenant A, query → should not see tenant B's data)
php artisan tinker
>>> auth()->loginUsingId('user-tenant-a');
>>> \App\Models\VectorEmbedding::count();
# Expected: hanya count untuk tenant A
```

---

## 7. Backfill Existing Data

Saat fitur RAG baru diaktifkan, **data existing belum punya embedding**. Jalankan command backfill:

### 7.1 Command Signatures

```bash
# Embed semua ROPA di semua org
php artisan embeddings:backfill ropa

# Embed semua source type sekaligus (batch 200 per chunk DB query)
php artisan embeddings:backfill all --chunk=200

# Embed ROPA di org specific (untuk staged rollout)
php artisan embeddings:backfill ropa --org=uuid-tenant-A

# Re-embed semua (force, abaikan content_hash cache)
php artisan embeddings:backfill ropa --force

# Dry-run untuk lihat berapa records yang akan di-embed
php artisan embeddings:backfill ropa --dry-run
```

### 7.2 Behavior

- Command **dispatch `EmbedRecordJob` ke queue** (async), bukan blocking. Monitor queue depth via `php artisan queue:work --queue=embeddings` atau Horizon.
- Setiap job:
  1. Hitung `content_hash` dari source record.
  2. Cek apakah sudah ada di `vector_embeddings` dengan hash sama → skip (idempotent).
  3. Kalau belum, panggil `EmbeddingService::embed($content)` → simpan ke DB.
- **Rate limiting** mencegah burst yang melebihi quota OpenAI/Cohere (default 100 req/min per org).
- Cost estimate untuk OpenAI `text-embedding-3-small`: ~USD 0.02 per 1M tokens. ROPA average ~500 token → 1000 ROPA = USD 0.01.

### 7.3 Monitoring Backfill Progress

**Via API admin endpoint:**

```bash
curl -H "Authorization: Bearer <admin-token>" \
  https://app-privasimu.esteh.id/api/admin/embeddings/stats
```

Response:

```json
{
  "total_embeddings": 12450,
  "by_source_type": {
    "ropa": 8200,
    "dpia": 1800,
    "breach": 350,
    "kb": 2100
  },
  "pending_backfill": {
    "ropa": 0,
    "dpia": 12,
    "breach": 0
  },
  "queue_depth": 12,
  "embedding_service_health": "ok",
  "last_embedded_at": "2026-05-19T10:23:14Z",
  "vector_table_size_mb": 245.7
}
```

**Via UI:**

`/platform-admin/embeddings` — dashboard real-time dengan progress bar per source type, queue depth chart, dan health indicator.

---

## 8. AI Agent Integration

### 8.1 Lima Tool Baru di `AiAgentToolExecutor`

Lokasi: `app/Services/AiAgentToolExecutor.php` — tambahan di method `execute()` match expression.

| Tool Name | Args | Returns |
|---|---|---|
| `search_similar_ropa` | `query: string, top_k?: int` | Top-K RoPA records mirip secara semantic |
| `search_similar_dpia` | `query: string, top_k?: int` | Top-K DPIA records mirip |
| `search_similar_breach` | `query: string, top_k?: int` | Top-K Breach incident mirip |
| `search_knowledge_base` | `query: string, top_k?: int, include_pasal?: bool` | KB articles + (optional) Pasal UU PDP |
| `find_related_records` | `source_type: string, source_id: string, top_k?: int` | Records mirip dengan record X (by embedding lookup, bukan re-embed query) |

**Contoh implementasi `searchSimilarRopa`:**

```php
private function searchSimilarRopa(array $args): array
{
    $query = $args['query'] ?? '';
    $topK = min(10, (int) ($args['top_k'] ?? 5));

    if (empty($query)) {
        return ['error' => 'Query tidak boleh kosong'];
    }

    return app(VectorSearchService::class)
        ->search($this->orgId, $query, $topK, ['ropa']);
    //              ↑
    //   $this->orgId dari constructor — defense layer 4
}
```

**Tool definition** (untuk LLM tool calling) ditambahkan ke `defineTools()` return array (~line 924 di file existing):

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'search_similar_ropa',
        'description' => 'Cari aktivitas pemrosesan data (RoPA) yang mirip secara semantic dengan query user. Gunakan ini untuk pertanyaan "ada yang mirip", "kasus serupa", "aktivitas pemrosesan apa saja yang berkaitan dengan X". Lebih relevan daripada list_ropa untuk pencarian konseptual.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Natural language query, contoh: "aktivitas yang melibatkan data biometrik nasabah"',
                ],
                'top_k' => [
                    'type' => 'integer',
                    'description' => 'Jumlah hasil (1-10, default 5)',
                    'default' => 5,
                ],
            ],
            'required' => ['query'],
        ],
    ],
],
```

### 8.2 System Prompt Updates

Di `AiAgentController.php` (~line 317-323), tambah rule 16 + 17 ke system prompt:

```
16. SEMANTIC SEARCH FIRST: Untuk pertanyaan "mirip apa", "ada yang serupa",
    "kasus sejenis", "yang berkaitan dengan X", PRIORITAS pakai tool
    search_similar_* atau search_knowledge_base dulu sebelum list_* —
    semantic search lebih relevan daripada exact filter.

17. CITE RETRIEVED CONTEXT: Saat menjawab berdasarkan retrieved chunks,
    sebutkan source_id atau registration_number-nya supaya user bisa verify
    (contoh: "Berdasarkan RoPA ROPA-2026-042 dan ROPA-2026-088 ..."). Jangan
    mengarang fakta yang tidak ada di chunks yang Anda terima.
```

### 8.3 Example Interactions

**Interaction 1 — Semantic ROPA search:**

```
User:   "Apa saja aktivitas pemrosesan yang berkaitan dengan nasabah prima?"

AI:     [tool_call: search_similar_ropa({query: "aktivitas pemrosesan nasabah prima", top_k: 5})]

Tool:   [
          {source_id: "uuid-1", source_type: "ropa", similarity: 0.87,
           content_excerpt: "RoPA ROPA-2026-042: Aktivitas pemrosesan data
           nasabah segment Prima Banking untuk personalisasi penawaran..."},
          {source_id: "uuid-2", similarity: 0.81, ...},
          ...
        ]

AI:     "Saya menemukan 3 aktivitas pemrosesan yang berkaitan dengan
         nasabah prima:
         1. ROPA-2026-042 — Personalisasi penawaran Prima Banking
         2. ROPA-2026-088 — Wealth management advisory untuk nasabah prima
         3. ROPA-2026-115 — Risk profiling segment HNI

         Apakah Anda ingin saya menunjukkan detail salah satunya?"
```

**Interaction 2 — KB Q&A dengan grounding:**

```
User:   "Apa kewajiban DPO bila terjadi insiden kebocoran data?"

AI:     [tool_call: search_knowledge_base({query: "kewajiban DPO insiden breach",
         include_pasal: true})]

Tool:   [
          {source_type: "pasal_uu_pdp", source_id: "pasal-46",
           content_excerpt: "Pasal 46 UU PDP: Pengendali Data Pribadi wajib
           menyampaikan pemberitahuan tertulis dalam waktu paling lambat
           3x24 jam..."},
          {source_type: "kb", source_id: "kb-breach-runbook",
           content_excerpt: "DPO Internal SOP: Saat menerima alert breach,
           DPO harus dalam 2 jam: (1) verifikasi insiden, (2) notifikasi
           CISO, ..."},
        ]

AI:     "Berdasarkan UU PDP Pasal 46 dan SOP internal organisasi Anda,
         kewajiban DPO bila terjadi insiden kebocoran data adalah:

         **Wajib menurut UU PDP:**
         - Notifikasi tertulis ke Lembaga Pelindungan Data dalam 3x24 jam
           (Pasal 46 ayat 1)
         - Notifikasi ke Subjek Data yang terdampak dalam 3x24 jam

         **Wajib menurut SOP internal (kb-breach-runbook):**
         - Verifikasi insiden dalam 2 jam
         - Notifikasi CISO dan Legal
         - Aktivasi containment checklist

         Referensi: Pasal 46 UU PDP, SOP DPO Internal."
```

---

## 9. Operational Concerns

### 9.1 Performance Characteristics

| Metric | Expected | Catatan |
|---|---|---|
| Embedding latency (TEI on-prem GPU) | ~20-50ms per text | bge-m3 di L40S |
| Embedding latency (OpenAI cloud) | ~80-200ms per text | Network RTT dominan |
| Vector search latency | ~50-200ms | IVFFlat top-K=5 pada ~100K rows |
| End-to-end AI Agent RAG response | ~2-8 detik | Embed + search + LLM call (DeepSeek) |
| Backfill throughput | ~30-50 records/sec | Per worker, OpenAI rate-limited |

### 9.2 Cache Strategy

`EmbeddingService::embed()` cache hasil embedding pakai `Cache::remember()` dengan key:

```
embedding:{provider}:{model}:{sha256(text)}:{org_id_hash}
```

- **TTL**: 30 hari (`cache_ttl_seconds` di config).
- **Org-scoped**: `org_id_hash` di key mencegah cross-tenant cache pollution (defense layer untuk cache).
- **Hit rate target**: > 80% untuk workload steady (banyak query repeat).
- **Cache invalidation**: Otomatis via TTL, atau manual `php artisan cache:forget embedding:*` saat ganti provider.

### 9.3 Rate Limiting

- Config `rate_limit_per_minute` (default 100) — enforce per org via Laravel `RateLimiter::for('embeddings', ...)`.
- Throttle response: HTTP 429 dengan `Retry-After` header.
- Backfill command respect rate limit — auto-pause kalau hit limit, resume di next minute.

### 9.4 Monitoring Checklist

| Metric | Source | Alert Threshold |
|---|---|---|
| Queue depth `embeddings` queue | Horizon / `php artisan queue:size embeddings` | > 1000 selama 10 menit |
| Embedding service health | `/api/admin/embeddings/health` (TEI `/health` proxy) | Status != "ok" |
| Vector table size | `pg_total_relation_size('vector_embeddings')` | Growth > 10 GB / bulan unexpected |
| Failed `EmbedRecordJob` | `failed_jobs` table | > 10 per jam |
| OpenAI/Cohere API errors | Laravel log + Sentry | > 5% error rate |
| Cache hit rate | Redis `INFO stats` | < 50% sustained |

Recommended dashboard: import Prometheus exporter untuk queue + redis + postgres, render di Grafana dengan panel khusus RAG (latency, throughput, error rate).

---

## 10. Troubleshooting

### 10.1 "Search return 0 results" walau data sudah di-embed

**Penyebab paling umum**: RLS policy aktif tapi `app.current_org_id` tidak ter-set di koneksi.

**Cek:**

```bash
docker compose exec backend php artisan tinker
>>> DB::select("SHOW app.current_org_id");
# Kalau error "unrecognized configuration parameter" atau empty → middleware
# SetCurrentOrgContext tidak jalan
```

**Fix:**
1. Verifikasi middleware `SetCurrentOrgContext` ada di kernel middleware stack (`bootstrap/app.php`).
2. Verifikasi user terautentikasi (`auth:sanctum`) sebelum middleware ini.
3. Kalau pakai pgbouncer, pastikan `pool_mode=transaction` (bukan `session`) — RLS `SET LOCAL` butuh transaksi.

### 10.2 "Embedding service down"

**Cek:**

```bash
curl http://localhost/api/admin/embeddings/health
# Expected: {"provider": "tei", "available": true, "latency_ms": 23}

# Kalau TEI:
docker compose ps privasimu-embeddings
docker compose logs privasimu-embeddings --tail=50

# Kalau OpenAI:
curl -H "Authorization: Bearer $OPENAI_API_KEY" \
  https://api.openai.com/v1/models | head
```

**Fix:**
- TEI down → restart container, cek GPU availability (`nvidia-smi`).
- OpenAI rate limited → upgrade tier atau lower `AI_EMBEDDING_RATE_LIMIT`.
- Network issue → cek firewall rules antara `backend` ↔ `privasimu-embeddings`.

### 10.3 "Token explosion di AI Agent context"

AI Agent terima terlalu banyak chunks → context window overflow → LLM error / cost spike.

**Fix:**
- Lower `top_k` di tool call (default 5, drop ke 3).
- Lower `chunk_size_chars` di config (default 1000, drop ke 500).
- Implement **reranker** di future enhancement (Cohere Rerank API).

### 10.4 "Vector dimension mismatch"

Saat ganti provider (misal TEI 1024-dim → OpenAI 1536-dim), kolom `embedding vector(1024)` tidak match.

**Fix:**

```bash
# 1. Backup table
docker compose exec tenant-db pg_dump -t vector_embeddings ... > backup.sql

# 2. Drop dan re-create kolom dengan dimensi baru
php artisan tinker
>>> DB::statement("ALTER TABLE vector_embeddings DROP COLUMN embedding");
>>> DB::statement("ALTER TABLE vector_embeddings ADD COLUMN embedding vector(1536)");
>>> DB::statement("CREATE INDEX vector_embeddings_embedding_idx ON vector_embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)");

# 3. Re-embed (force, abaikan cache karena dimensi beda)
php artisan embeddings:backfill all --force

# 4. Invalidate cache lama
php artisan cache:forget "embedding:*"
```

### 10.5 "IVFFlat index slow setelah data grow"

Symptom: search latency naik dari 50ms → 500ms+ setelah > 1M rows.

**Fix:**

```sql
-- Tune lists parameter (sqrt(N) rule of thumb)
DROP INDEX vector_embeddings_embedding_idx;
CREATE INDEX vector_embeddings_embedding_idx
    ON vector_embeddings USING ivfflat (embedding vector_cosine_ops)
    WITH (lists = 1000);   -- untuk ~1M rows

-- Alternatif: switch ke HNSW (lebih cepat, pakai memory lebih)
-- Butuh pgvector >= 0.5.0
DROP INDEX vector_embeddings_embedding_idx;
CREATE INDEX vector_embeddings_embedding_idx
    ON vector_embeddings USING hnsw (embedding vector_cosine_ops);
```

---

## 11. Right to Erasure (UU PDP Pasal 8) Compliance

UU PDP Pasal 8 menjamin hak Subjek Data untuk menghapus data pribadinya. Karena embedding adalah **representasi vector dari content asli**, embedding juga harus dihapus saat data sumber dihapus — disebut **machine unlearning**.

### 11.1 Pattern Hapus Embedding Saat Source Dihapus

Setiap observer (`RopaEmbeddingObserver`, dst) implement `deleted` hook:

```php
public function deleted(Ropa $ropa): void
{
    VectorEmbedding::where('source_type', 'ropa')
        ->where('source_id', $ropa->id)
        ->delete();   // soft delete (deleted_at set), kemudian purge job hapus permanent

    AuditLog::create([
        'org_id' => $ropa->org_id,
        'actor_type' => 'system',
        'action' => 'embedding.purge',
        'subject_type' => 'ropa',
        'subject_id' => $ropa->id,
        'metadata' => ['reason' => 'source_deleted'],
    ]);
}

public function forceDeleted(Ropa $ropa): void
{
    // Hard delete — UU PDP Pasal 8 compliance
    VectorEmbedding::where('source_type', 'ropa')
        ->where('source_id', $ropa->id)
        ->forceDelete();
}
```

### 11.2 Bulk Erasure (DSR Right to Erasure)

Saat DSR Erasure request approved dan disetujui DPO:

```php
// Di DsrApprovalController atau equivalent
public function approveErasure(DsrRequest $dsr): void
{
    DB::transaction(function () use ($dsr) {
        // 1. Hapus source records
        $affected = Ropa::where('data_subject_email', $dsr->subject_email)->get();

        foreach ($affected as $record) {
            // Observer auto-trigger embedding purge
            $record->forceDelete();
        }

        // 2. Audit
        AuditLog::create([
            'org_id' => $dsr->org_id,
            'actor_type' => 'dsr',
            'action' => 'right_to_erasure.executed',
            'subject_type' => 'dsr',
            'subject_id' => $dsr->id,
            'metadata' => [
                'records_purged' => $affected->count(),
                'embeddings_purged' => true,
            ],
        ]);
    });
}
```

### 11.3 Compliance Statement untuk Auditor

> "Privasimu melakukan **synchronous embedding purge** saat source record dihapus. Observer pattern memastikan setiap soft-delete + force-delete pada model RoPA, DPIA, Breach, Vendor, KB akan memicu penghapusan row terkait di tabel `vector_embeddings`. Audit trail tersimpan di `audit_logs` dengan `action=embedding.purge`. Hal ini memenuhi UU PDP Pasal 8 ayat 1 huruf c (hak menghapus) dan praktik machine unlearning di literatur AI compliance."

---

## 12. Security Checklist (SOC / ISO Audit)

Gunakan checklist ini saat code review fitur baru yang menyentuh RAG, atau saat persiapan SOC 2 / ISO 27001 audit:

- [ ] **`org_id` filter di setiap query `vector_embeddings`** — verifikasi via `grep -rn "vector_embeddings" app/ | grep -v "org_id"` (harus return 0 hasil di luar `BelongsToOrgScope`)
- [ ] **Cache key include `org_id` hash component** — verifikasi di `EmbeddingService::buildCacheKey()`
- [ ] **RLS policy aktif di Postgres production** — `SELECT relrowsecurity FROM pg_class WHERE relname='vector_embeddings'` → `true`
- [ ] **Middleware `SetCurrentOrgContext` registered** dan di-execute sebelum semua DB query authenticated
- [ ] **Service signature `search(string $orgId, ...)`** — type-hinted, non-nullable, first parameter
- [ ] **Model `VectorEmbedding` pakai `BelongsToOrg` trait** — global scope active
- [ ] **AI Agent tool methods pakai `$this->orgId`** dari constructor, bukan dari `$args`
- [ ] **No raw query bypass** di code — `grep -rn "DB::statement\|DB::select" app/Services/Embedding* app/Services/VectorSearch*` review setiap occurrence
- [ ] **Embedding service credentials** (OpenAI/Cohere API key) di-encrypt di DB (`system_settings.encrypted_value`) atau di-mount sebagai Docker secret, **bukan** plain env var di production
- [ ] **Audit log entry** untuk setiap embedding create, update, purge — query `audit_logs WHERE action LIKE 'embedding.%'` harus comprehensive
- [ ] **Rate limit aktif** per org — verifikasi via test: spam 200 req/min → 429 setelah 100
- [ ] **Test `VectorSearchTenantIsolationTest`** pass — verifikasi tenant A query tidak return tenant B data (positive test + RLS bypass attempt test)
- [ ] **Backup strategy** include `vector_embeddings` table — pg_dump default sudah include, tapi ukurannya bisa besar, konfirmasi backup window OK
- [ ] **Right to Erasure pipeline** verified end-to-end — DSR erasure → record delete → observer fire → vector_embeddings purged → audit log

---

## 13. Future Enhancements

Roadmap improvement yang relevan untuk Privasimu (priority-ranked):

### 13.1 Hybrid Search (Vector + BM25)

**Problem**: Pure vector search kadang miss query yang sangat literal (exact term match seperti registration number, nama orang).

**Solusi**: Kombinasi vector similarity + Postgres full-text search (BM25-like via `tsvector`). Score fusion via **Reciprocal Rank Fusion (RRF)**:

```sql
WITH vector_results AS (
    SELECT id, source_id, 1.0 - (embedding <=> $query_vec) AS vec_score
    FROM vector_embeddings WHERE org_id = $org_id
    ORDER BY embedding <=> $query_vec LIMIT 50
),
text_results AS (
    SELECT id, source_id,
           ts_rank(to_tsvector('indonesian', content_excerpt),
                   plainto_tsquery('indonesian', $query_text)) AS text_score
    FROM vector_embeddings WHERE org_id = $org_id
    AND to_tsvector('indonesian', content_excerpt) @@
        plainto_tsquery('indonesian', $query_text)
    LIMIT 50
)
SELECT source_id, 1.0 / (60 + rank() OVER (ORDER BY vec_score DESC)) +
                  1.0 / (60 + rank() OVER (ORDER BY text_score DESC)) AS rrf_score
FROM vector_results FULL OUTER JOIN text_results USING (source_id)
ORDER BY rrf_score DESC LIMIT 5;
```

**Effort**: ~3 hari engineering. **Impact**: 15-25% improvement di recall untuk query mixed.

### 13.2 Reranker (Cohere Rerank API atau on-prem cross-encoder)

**Problem**: Top-K initial dari vector search tidak selalu urutan terbaik. Reranker pakai cross-encoder yang baca query + dokumen sebagai pair → score lebih akurat.

**Pattern**:
1. Vector search top-50 (recall tinggi).
2. Rerank top-50 → ambil top-5 (precision tinggi).

**Provider options**:
- Cohere Rerank API (cloud, USD 1 per 1000 search).
- On-prem: `bge-reranker-v2-m3` via TEI.

**Effort**: ~2 hari. **Impact**: 20-30% improvement nDCG.

### 13.3 Incremental Embedding Updates

**Problem**: Saat ROPA wizard edited (banyak field change kecil), saat ini re-embed seluruh content. Inefficient untuk dokumen besar.

**Solusi**: Chunk-level dirty tracking. Hanya re-embed chunk yang berubah, bukan seluruh dokumen.

**Effort**: ~5 hari (perubahan observer + chunking logic). **Impact**: 60-80% reduction embedding cost untuk edit-heavy workflow.

### 13.4 Multi-Vector per Record (Late Interaction)

**Problem**: Satu vector per ROPA loses information untuk dokumen panjang. Pasal UU PDP misalnya bisa 5+ paragraf dengan topik beda.

**Solusi**: Simpan multiple vector per `source_id` (ColBERT-style late interaction). Query match terhadap semua vector, ambil max similarity.

**Effort**: ~7 hari (schema change + search algo). **Impact**: Significant untuk long-form documents (KB articles, kontrak panjang).

### 13.5 Embedding Quantization (Storage Optimization)

**Problem**: 1M rows × 1024 dim × 4 bytes (float) = 4 GB per tabel. Untuk klien enterprise dengan 10M+ rows, storage bloat.

**Solusi**: pgvector mendukung **scalar quantization** (1 byte per dim) atau **binary quantization** (1 bit per dim). 4-32x storage saving dengan recall loss ~1-3%.

**Effort**: ~2 hari (migration + config flag). **Impact**: 4-32x storage reduction.

---

## 14. Reference Files

- `backend/database/migrations/2026_05_19_120001_create_vector_embeddings_table.php` — table + extension + index
- `backend/database/migrations/2026_05_19_120002_enable_rls_on_vector_embeddings.php` — RLS policy
- `backend/app/Models/VectorEmbedding.php` — Eloquent model dengan `BelongsToOrg`
- `backend/app/Services/EmbeddingService.php` — provider abstraction (TEI / OpenAI / Cohere)
- `backend/app/Services/VectorSearchService.php` — semantic search + findRelated
- `backend/app/Jobs/EmbedRecordJob.php` — async embed worker
- `backend/app/Console/Commands/EmbeddingsBackfillCommand.php` — backfill CLI
- `backend/app/Observers/{Ropa,Dpia,Breach,Vendor,Kb}EmbeddingObserver.php` — auto-embed on create/update, purge on delete
- `backend/app/Services/AiAgentToolExecutor.php` — 5 new RAG tools
- `backend/app/Http/Controllers/Api/Admin/EmbeddingStatsController.php` — admin stats endpoint
- `backend/config/ai_embedding.php` — config file
- `backend/tests/Feature/VectorSearchTenantIsolationTest.php` — isolation test (Tenant A cannot see Tenant B)
- `frontend/src/app/(dashboard)/platform-admin/embeddings/page.tsx` — admin dashboard UI
- `frontend/src/app/(dashboard)/settings/page.tsx` — AI Embedding section
- `backend/docs/RAG_IMPLEMENTATION_SPEC.md` — shared reference spec (read-only, agent assembly)
- `backend/docs/ONPREM_DEPLOY.md` — Laravel on-prem deployment (sister doc)
- `D:\AI\privasimu\docs\PRIVASIMU_NEXUS_ONPREM_AI.md` — GPU hardware sizing untuk on-prem AI

---

*Dokumen ini adalah implementasi guide. Untuk perubahan kontrak service / signature / migration, update `RAG_IMPLEMENTATION_SPEC.md` terlebih dahulu — itu source of truth untuk parallel agent assembly.*
