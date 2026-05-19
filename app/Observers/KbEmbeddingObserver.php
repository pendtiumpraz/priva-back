<?php

namespace App\Observers;

use App\Jobs\EmbedRecordJob;
use App\Models\KnowledgeBaseSection;
use Illuminate\Support\Facades\DB;

/**
 * Observer untuk auto-dispatch embedding job ketika Knowledge Base entry
 * (KnowledgeBaseSection) dibuat/diubah/dihapus.
 *
 * ──────────────────────────────────────────────────────────────────────
 * SPECIAL HANDLING — Shared platform-level KB articles
 * ──────────────────────────────────────────────────────────────────────
 * KnowledgeBaseSection model mendukung dua mode kepemilikan:
 *
 *   1. Tenant-specific KB  → kolom `org_id` di-isi UUID tenant
 *   2. Shared platform KB  → kolom `org_id` IS NULL (kurasi superadmin
 *                            Privasimu, terlihat oleh semua tenant via
 *                            `scopeVisibleTo()` di model)
 *
 * Tabel `vector_embeddings` punya constraint `org_id UUID NOT NULL`
 * (spec: RAG_IMPLEMENTATION_SPEC.md §"Database Schema"). Kalau kita simpan
 * shared-KB embedding dengan `org_id = NULL`, insert akan reject.
 *
 * STRATEGI: gunakan placeholder UUID khusus untuk system/platform org +
 * source_type berbeda agar VectorSearchService bisa bedakan & cari lintas
 * tenant.
 *
 *   - Tenant KB     → org_id = $kb->org_id,  source_type = 'kb'
 *   - Shared KB     → org_id = SYSTEM_ORG_ID, source_type = 'kb_shared'
 *
 * VectorSearchService HARUS implement OR-clause untuk shared retrieval:
 *
 *     SELECT * FROM vector_embeddings
 *      WHERE (
 *            (org_id = :tenant_org AND source_type IN (:tenant_types))
 *         OR (org_id = :system_org  AND source_type = 'kb_shared')
 *      )
 *      AND deleted_at IS NULL
 *      ORDER BY embedding <=> :query_vector
 *      LIMIT :k;
 *
 * Dengan begitu setiap tenant tetap terisolasi (tidak bisa lihat KB tenant
 * lain) tapi BISA me-retrieve KB shared yang di-kurasi platform — tanpa
 * mengorbankan defense-layer-2 `WHERE org_id = ?` di service.
 *
 * Konstanta `SYSTEM_ORG_ID` di-pakai konsisten oleh observer ini DAN oleh
 * VectorSearchService saat compose OR-clause. Jangan ubah nilai ini tanpa
 * sekaligus migrasi data di `vector_embeddings`.
 * ──────────────────────────────────────────────────────────────────────
 *
 * Soft-delete pattern: KnowledgeBaseSection saat ini TIDAK pakai
 * SoftDeletes (no `deleted_at` di tabel), jadi `deleted()` event = hard
 * delete. Tetap soft-delete row di `vector_embeddings` (set `deleted_at`)
 * supaya history audit ter-preserve. `restored()` di-handler defensif —
 * dipanggil kalau di kemudian hari SoftDeletes ditambahkan.
 */
class KbEmbeddingObserver
{
    /**
     * Placeholder UUID untuk shared/platform-level KB embeddings.
     * Tidak terkait dengan tenant manapun; di-search lintas tenant via
     * source_type = 'kb_shared'.
     *
     * Nilai ini di-share dengan VectorSearchService (pencarian) +
     * EmbeddingsBackfillCommand (re-embed bulk shared KB).
     */
    public const SYSTEM_ORG_ID = '00000000-0000-0000-0000-000000000000';

    public const SOURCE_TYPE_TENANT = 'kb';
    public const SOURCE_TYPE_SHARED = 'kb_shared';

    /**
     * Fields yang trigger re-embedding ketika berubah.
     *
     * Note: skema saat ini punya `content` (bukan `body`) dan `summary`
     * (ditambah lewat migration 2026_04_24). `body` di-included sebagai
     * forward-compat kalau schema berkembang. wasChanged() aman dipanggil
     * dengan nama kolom yang belum ada — return false saja.
     */
    private const SIGNIFICANT_FIELDS = [
        'title',
        'body',
        'content',
        'module_key',
        'summary',
    ];

    /**
     * Max content length to send for embedding (rough char budget).
     */
    private const MAX_CONTENT_CHARS = 3000;

    /**
     * Handle the KB "saved" event (covers create & update).
     */
    public function saved(KnowledgeBaseSection $kb): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        // Skip inactive entries — tidak ada gunanya embed konten yang tidak
        // visible ke user.
        if (property_exists($kb, 'is_active') || isset($kb->is_active)) {
            if ($kb->is_active === false) {
                return;
            }
        }

        // Pada update, skip kalau tidak ada significant field yang berubah.
        // Untuk create (wasRecentlyCreated), wasChanged tetap true untuk
        // setiap dirty field, jadi check ini aman untuk create juga.
        if (! $kb->wasRecentlyCreated && ! $kb->wasChanged(self::SIGNIFICANT_FIELDS)) {
            return;
        }

        $content = $this->buildContent($kb);
        if ($content === '') {
            return;
        }

        $isShared = $kb->org_id === null;

        // Untuk shared KB (platform-level), embed di bawah SYSTEM_ORG_ID
        // dengan source_type 'kb_shared'. VectorSearchService akan
        // include row ini via OR-clause saat tenant lain search.
        $targetOrgId = $isShared ? self::SYSTEM_ORG_ID : $kb->org_id;
        $sourceType = $isShared ? self::SOURCE_TYPE_SHARED : self::SOURCE_TYPE_TENANT;

        $metadata = [
            'title' => $kb->title,
            'module_key' => $kb->module_key,
            'is_shared' => $isShared,
            // Schema saat ini tidak punya kolom `slug`; module_key berfungsi
            // sebagai natural identifier (UNIQUE constraint). Tetap expose
            // key `slug` di metadata supaya konsumen punya stable contract
            // — fallback ke module_key.
            'slug' => $kb->slug ?? $kb->module_key,
            'category' => $kb->category ?? null,
            'feature_tags' => $kb->feature_tags ?? null,
        ];

        EmbedRecordJob::dispatch(
            $targetOrgId,
            $sourceType,
            $kb->id,
            $content,
            $metadata,
        );
    }

    /**
     * Handle the KB "deleted" event.
     *
     * Vector rows tidak di-hard-delete — tandai `deleted_at` supaya
     * restore() bisa balikin tanpa re-embed. Tetap scope ke org_id yang
     * benar (system org untuk shared, tenant org untuk tenant-specific)
     * + source_type yang sesuai.
     */
    public function deleted(KnowledgeBaseSection $kb): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        $isShared = $kb->org_id === null;
        $targetOrgId = $isShared ? self::SYSTEM_ORG_ID : $kb->org_id;
        $sourceType = $isShared ? self::SOURCE_TYPE_SHARED : self::SOURCE_TYPE_TENANT;

        DB::table('vector_embeddings')
            ->where('org_id', $targetOrgId)
            ->where('source_type', $sourceType)
            ->where('source_id', $kb->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    /**
     * Handle the KB "restored" event — un-set deleted_at on matching
     * vector rows. Hanya relevan kalau di kemudian hari SoftDeletes
     * ditambahkan ke KnowledgeBaseSection.
     */
    public function restored(KnowledgeBaseSection $kb): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        $isShared = $kb->org_id === null;
        $targetOrgId = $isShared ? self::SYSTEM_ORG_ID : $kb->org_id;
        $sourceType = $isShared ? self::SOURCE_TYPE_SHARED : self::SOURCE_TYPE_TENANT;

        DB::table('vector_embeddings')
            ->where('org_id', $targetOrgId)
            ->where('source_type', $sourceType)
            ->where('source_id', $kb->id)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);
    }

    /**
     * Compose content string dari KB entry. Format:
     *   "[module_key] Title\n\nSummary\n\nFull content"
     *
     * module_key disertakan di body teks supaya embedding model menangkap
     * konteks modul (mis. 'ropa' vs 'dpia') sebagai bagian semantik —
     * bukan hanya filter di metadata.
     */
    private function buildContent(KnowledgeBaseSection $kb): string
    {
        $parts = [];

        // Header: module key + title
        $title = trim((string) $kb->title);
        $moduleKey = trim((string) $kb->module_key);
        if ($title !== '' && $moduleKey !== '') {
            $parts[] = "[{$moduleKey}] {$title}";
        } elseif ($title !== '') {
            $parts[] = $title;
        } elseif ($moduleKey !== '') {
            $parts[] = "Module: {$moduleKey}";
        }

        // Summary (short version, bila ada)
        $summary = trim((string) ($kb->summary ?? ''));
        if ($summary !== '') {
            $parts[] = 'Ringkasan: '.$summary;
        }

        // Body / content — coba kolom `body` dulu (forward-compat) lalu
        // jatuh ke `content` (skema saat ini).
        $body = '';
        if (isset($kb->body) && is_string($kb->body) && trim($kb->body) !== '') {
            $body = trim($kb->body);
        } elseif (isset($kb->content) && is_string($kb->content) && trim($kb->content) !== '') {
            $body = trim($kb->content);
        }
        if ($body !== '') {
            $parts[] = $body;
        }

        // Keywords (kalau ada) — bantu retrieval untuk query yang persis
        // match terminology yang dipakai author KB.
        $keywords = trim((string) ($kb->keywords ?? ''));
        if ($keywords !== '') {
            $parts[] = 'Kata kunci: '.$keywords;
        }

        $joined = trim(implode("\n\n", array_filter($parts, fn ($p) => $p !== '')));
        if ($joined === '') {
            return '';
        }

        if (mb_strlen($joined) > self::MAX_CONTENT_CHARS) {
            $joined = mb_substr($joined, 0, self::MAX_CONTENT_CHARS);
        }

        return $joined;
    }
}
