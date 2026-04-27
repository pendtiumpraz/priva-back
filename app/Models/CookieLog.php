<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Anonymous cookie banner consent capture.
 *
 * Distinct from ConsentLog — this table is for visitor / homepage banners
 * (no email/name). Auto-pruned after retention period; choices and audit
 * fields are kept long enough to defend regulator queries about whether
 * a particular cookie set was active at a given timestamp.
 */
class CookieLog extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'collection_id',
        'visitor_id',
        'session_id',
        'ip_address',
        'ip_country',
        'ip_city',
        'user_agent',
        'browser_name',
        'browser_version',
        'os_name',
        'device_type',
        'referrer',
        'page_url',
        'choices',
        'policy_version',
        'captured_at',
        'expires_at',
    ];

    protected $casts = [
        'choices' => 'array',
        'captured_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function collectionPoint()
    {
        return $this->belongsTo(\App\Models\ConsentCollectionPoint::class, 'collection_id');
    }
}
