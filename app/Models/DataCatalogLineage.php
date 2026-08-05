<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu tepi silsilah antar aset data.
 *
 * Soft delete berlaku, tetapi hanya bermakna untuk tepi bersumber MANUAL dan
 * IMPOR — keduanya pengetahuan yang diketik orang dan tidak dapat diturunkan
 * ulang, jadi salah klik tidak boleh menghilangkannya selamanya.
 *
 * Tepi turunan ('auto') justru dibuang permanen oleh
 * DataCatalogService::rebuildLineage(): ia dibangun ulang utuh setiap
 * sinkronisasi, sehingga mengarsipkannya hanya menggelembungkan keranjang
 * sampah dan membuat updateOrCreate menabrak indeks unik dcl_unique_edge.
 */
class DataCatalogLineage extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $table = 'data_catalog_lineage';

    public const RELATIONS = ['feeds', 'derives', 'copies', 'exports', 'references', 'processes'];

    public const SOURCES = ['auto', 'manual', 'imported'];

    protected $fillable = [
        'org_id', 'from_key', 'to_key', 'relation', 'description', 'source', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];
}
