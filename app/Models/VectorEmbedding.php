<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Multi-tenant safe via BelongsToOrg global scope. Semua query auto WHERE org_id.
 *
 * Catatan kompatibilitas lintas-environment:
 *   - Di Postgres produksi, kolom `embedding` menggunakan tipe pgvector
 *     (vector(1024)). Cast manual ke/dari format pgvector dilakukan via
 *     accessor / mutator atau di EmbeddingService — jangan andalkan cast
 *     `array` di sini untuk Postgres.
 *   - Cast `array` di properti $casts dipertahankan supaya migration di
 *     SQLite (testing) dan MySQL (dev fallback) tetap bisa menyimpan
 *     embedding sebagai JSON tanpa error serialization.
 */
class VectorEmbedding extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const SOURCE_ROPA = 'ropa';
    public const SOURCE_DPIA = 'dpia';
    public const SOURCE_BREACH = 'breach';
    public const SOURCE_VENDOR = 'vendor';
    public const SOURCE_KB = 'kb';
    public const SOURCE_PASAL_UU_PDP = 'pasal_uu_pdp';
    public const SOURCE_CONTRACT = 'contract';
    public const SOURCE_POLICY = 'policy';

    public const ALLOWED_SOURCES = [
        self::SOURCE_ROPA,
        self::SOURCE_DPIA,
        self::SOURCE_BREACH,
        self::SOURCE_VENDOR,
        self::SOURCE_KB,
        self::SOURCE_PASAL_UU_PDP,
        self::SOURCE_CONTRACT,
        self::SOURCE_POLICY,
    ];

    protected $fillable = [
        'org_id',
        'source_type',
        'source_id',
        'content_hash',
        'embedding',
        'content_excerpt',
        'metadata',
        'embedding_provider',
        'embedding_model',
        'embedding_version',
    ];

    protected $casts = [
        'metadata' => 'array',
        'embedding' => 'array',
        'embedding_version' => 'integer',
    ];
}
