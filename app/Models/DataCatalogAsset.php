<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Satu aset data di katalog: sistem, dataset, kolom, berkas, atau laporan. */
class DataCatalogAsset extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const TYPES = ['system', 'dataset', 'field', 'file', 'report'];

    public const SOURCES = ['internal', 'manual', 'collibra', 'alation', 'purview', 'custom'];

    protected $fillable = [
        'org_id', 'asset_key', 'asset_type', 'name', 'qualified_name', 'description',
        'source', 'source_ref', 'information_system_id', 'owner_user_id', 'steward',
        'classification', 'pdp_category', 'encryption_required',
        'tags', 'metadata', 'is_active', 'last_synced_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'metadata' => 'array',
        'encryption_required' => 'boolean',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
