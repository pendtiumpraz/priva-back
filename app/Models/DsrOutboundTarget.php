<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tujuan pengiriman otomatis permohonan subjek data ke sistem luar.
 */
class DsrOutboundTarget extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const FORMATS = ['generic', 'salesforce'];

    public const EVENTS = ['dsr.created', 'dsr.verified', 'dsr.completed', 'dsr.rejected'];

    protected $fillable = [
        'org_id', 'name', 'url', 'payload_format', 'auth_header', 'events',
        'is_active', 'timeout_seconds', 'retry_count', 'last_delivered_at',
        'total_deliveries', 'failed_deliveries', 'created_by',
    ];

    protected $hidden = ['auth_header'];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'last_delivered_at' => 'datetime',
        // Header otentikasi berisi token pembawa milik sistem klien — sama
        // sensitifnya dengan kata sandi, jadi diperlakukan sama.
        'auth_header' => EncryptedString::class,
    ];
}
