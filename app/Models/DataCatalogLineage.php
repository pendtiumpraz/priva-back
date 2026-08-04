<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu tepi silsilah antar aset data.
 *
 * Tanpa soft delete: tepi tidak diarsipkan melainkan dibangun ulang setiap
 * sinkronisasi. Yang dipertahankan hanyalah tepi bersumber manual dan impor,
 * karena keduanya tidak dapat diturunkan ulang.
 */
class DataCatalogLineage extends Model
{
    use BelongsToOrg, HasUuids;

    protected $table = 'data_catalog_lineage';

    public const RELATIONS = ['feeds', 'derives', 'copies', 'exports', 'references', 'processes'];

    public const SOURCES = ['auto', 'manual', 'imported'];

    protected $fillable = [
        'org_id', 'from_key', 'to_key', 'relation', 'description', 'source', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];
}
