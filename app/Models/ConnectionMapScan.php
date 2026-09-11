<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu kali scan Peta Koneksi seluruh modul (DSPM).
 *
 * Grafnya tinggal di storage tenant (storage_location = 'storage') atau di
 * kolom `payload` (storage_location = 'database') — selalu dibaca lewat
 * ConnectionMapService::loadGraph(), jangan langsung dari atribut.
 */
class ConnectionMapScan extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const LOCATION_STORAGE = 'storage';

    public const LOCATION_DATABASE = 'database';

    protected $fillable = [
        'org_id',
        'storage_location', 'storage_driver', 'storage_path', 'storage_note',
        'payload', 'payload_sha256', 'payload_bytes',
        'node_count', 'edge_count', 'summary',
        'scanned_by', 'scanned_at',
    ];

    // Graf bisa ratusan KB — jangan pernah ikut terserialisasi tanpa sengaja
    // (daftar riwayat, audit log, respons API).
    protected $hidden = ['payload'];

    protected $casts = [
        'payload' => 'array',
        'summary' => 'array',
        'payload_bytes' => 'integer',
        'node_count' => 'integer',
        'edge_count' => 'integer',
        'scanned_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
