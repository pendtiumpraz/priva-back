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

    /**
     * choices with any item-UUID keys resolved to titles: { label: bool }.
     * V2 cookie captures key by category name (necessary/analytics/…), which
     * pass through unchanged; legacy rows migrated from consent_logs may key
     * by item UUID, which this resolves. Pass a prebuilt [id => title] map to
     * avoid a per-row query when labeling a page of logs.
     */
    public function labeledChoices(?array $titleById = null): array
    {
        $titleById ??= ConsentItem::titleMap([$this->collection_id]);
        $out = [];
        foreach (($this->choices ?? []) as $key => $val) {
            $out[$titleById[$key] ?? $key] = (bool) $val;
        }

        return $out;
    }
}
