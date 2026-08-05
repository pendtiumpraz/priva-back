<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Data store yang terdeteksi tetapi belum terdaftar sebagai sistem informasi. */
class DiscoveryCandidate extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const SOURCES = ['cmdb', 'config_file', 'connection_log', 'network_scan', 'manual'];

    public const STATUSES = ['new', 'registered', 'ignored'];

    protected $fillable = [
        'org_id', 'host', 'port', 'service_hint', 'name', 'source', 'evidence',
        'status', 'matched_system_id', 'note', 'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'port' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
