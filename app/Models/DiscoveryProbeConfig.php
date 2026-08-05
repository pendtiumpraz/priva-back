<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Konfigurasi penemuan data store per organisasi. */
class DiscoveryProbeConfig extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const MODE_PASSIVE = 'passive';

    public const MODE_ACTIVE = 'active';

    public const MODES = [self::MODE_PASSIVE, self::MODE_ACTIVE];

    /** Porta basis data yang lazim, dipakai bila tenant tidak menyebutkannya. */
    public const DEFAULT_PORTS = [1433, 1521, 3306, 5432, 6379, 9042, 27017];

    protected $fillable = [
        'org_id', 'mode', 'cidr_ranges', 'ports', 'is_enabled',
        'active_scan_approved_by', 'active_scan_approved_at', 'last_run_at', 'created_by',
    ];

    protected $casts = [
        'cidr_ranges' => 'array',
        'ports' => 'array',
        'is_enabled' => 'boolean',
        'active_scan_approved_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];
}
