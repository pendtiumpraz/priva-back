<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * RAG Infrastructure — vector_embeddings table.
 *
 * Tabel penyimpanan embedding vector untuk Retrieval-Augmented Generation.
 * Setiap record adalah satu chunk dokumen / record domain yang sudah
 * di-embed via provider (TEI/bge-m3, OpenAI text-embedding-3-small, Cohere).
 *
 * Cross-env strategy:
 *   - Postgres prod  → CREATE EXTENSION vector, kolom `embedding` pakai
 *                      tipe pgvector `vector(1024)` + IVFFlat cosine index.
 *   - SQLite/MySQL   → skip extension, kolom `embedding` jadi JSON/TEXT
 *                      fallback (RAG features di-disable via config check
 *                      di environment tsb — search tetap aman, cuma akan
 *                      no-op atau pakai LIKE fallback bukan ANN).
 *
 * Cross-tenant isolation:
 *   - `org_id` MANDATORY di semua query (defense layer: BelongsToOrg trait
 *     di model + RLS policy di migration 2026_05_19_120002).
 *   - Unique constraint (org_id, source_type, source_id, content_hash)
 *     prevents duplicate embedding generation untuk konten yang sama.
 *
 * source_type enum (string, not DB enum supaya extensible):
 *   ropa | dpia | breach | vendor | kb | pasal_uu_pdp | contract | policy
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vector_embeddings')) return;

        $driver = DB::getDriverName();

        // Postgres-only: enable pgvector extension. CREATE EXTENSION IF NOT
        // EXISTS aman dipanggil berulang. Non-Postgres drivers skip — tabel
        // tetap dibuat dengan kolom embedding sebagai JSON fallback.
        if ($driver === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            } catch (\Throwable $e) {
                // Extension mungkin perlu superuser; log + lanjut. Kolom
                // akan dibuat sebagai JSON fallback kalau pgvector gagal.
                \Log::warning('pgvector extension creation failed, falling back to JSON column: ' . $e->getMessage());
            }
        } else {
            \Log::info("Skipping pgvector extension on driver [{$driver}] — embedding column will be JSON/TEXT fallback");
        }

        Schema::create('vector_embeddings', function (Blueprint $table) use ($driver) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('source_type', 50);
            $table->uuid('source_id');
            $table->char('content_hash', 64);                  // SHA-256 hex

            // Kolom embedding: pgvector di Postgres, JSON di lainnya.
            // Postgres path di-handle via raw DB::statement setelah create,
            // sini cuma reserve placeholder JSON supaya schema valid di
            // semua driver. Untuk pgsql kita drop & re-add via ALTER TABLE
            // di bawah dengan tipe vector(1024).
            $table->json('embedding')->nullable();

            $table->text('content_excerpt');

            // JSONB di Postgres untuk indexability; driver lain fallback ke JSON.
            if ($driver === 'pgsql') {
                $table->jsonb('metadata')->nullable();
            } else {
                $table->json('metadata')->nullable();
            }

            $table->string('embedding_provider', 50)->nullable();
            $table->string('embedding_model', 100)->nullable();
            $table->unsignedInteger('embedding_version')->default(1);

            $table->timestamps();
            $table->softDeletes();

            // Non-unique indexes (driver-agnostic via Blueprint).
            $table->index(['org_id', 'source_type'], 'vector_embeddings_org_source_idx');
            $table->index(['org_id', 'source_type', 'source_id'], 'vector_embeddings_lookup_idx');
        });

        // Postgres-only: swap JSON embedding column → vector(1024) bila
        // extension berhasil di-load. Kalau extension gagal load (mis. di
        // managed Postgres tanpa pgvector), kolom JSON tetap dipakai dan
        // RAG semantic search di-disable via config.
        if ($driver === 'pgsql') {
            try {
                DB::statement('ALTER TABLE vector_embeddings DROP COLUMN embedding');
                DB::statement('ALTER TABLE vector_embeddings ADD COLUMN embedding vector(1024)');
            } catch (\Throwable $e) {
                \Log::warning('Could not promote embedding column to vector(1024), keeping JSON fallback: ' . $e->getMessage());
            }
        }

        // Partial unique index (org_id, source_type, source_id, content_hash)
        // WHERE deleted_at IS NULL — Postgres native syntax. Driver lain
        // pakai unique penuh (soft-delete duplicates akan jarang terjadi
        // di SQLite/MySQL karena env tsb biasanya test/dev).
        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX vector_embeddings_hash_unique '
                . 'ON vector_embeddings(org_id, source_type, source_id, content_hash) '
                . 'WHERE deleted_at IS NULL'
            );

            // IVFFlat cosine index untuk approximate nearest neighbor.
            // lists=100 cocok untuk dataset puluhan ribu rows; nanti bisa
            // di-rebuild dengan lists lebih besar untuk dataset jutaan.
            // Index ini hanya di-create kalau kolom embedding berhasil
            // jadi tipe vector.
            try {
                DB::statement(
                    'CREATE INDEX vector_embeddings_embedding_idx '
                    . 'ON vector_embeddings USING ivfflat (embedding vector_cosine_ops) '
                    . 'WITH (lists = 100)'
                );
            } catch (\Throwable $e) {
                \Log::warning('IVFFlat index creation skipped (kolom embedding bukan tipe vector?): ' . $e->getMessage());
            }
        } else {
            // SQLite/MySQL fallback — unique penuh tanpa partial predicate.
            Schema::table('vector_embeddings', function (Blueprint $table) {
                $table->unique(
                    ['org_id', 'source_type', 'source_id', 'content_hash'],
                    'vector_embeddings_hash_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vector_embeddings');
        // Note: TIDAK drop extension vector — bisa dipakai tabel lain
        // dan biasanya butuh superuser.
    }
};
