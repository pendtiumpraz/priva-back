<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily-ish snapshot of an org's privacy/security posture. The trend
 * chart on the Security page is built from this — no synthetic data.
 *
 * Three layers map to UU PDP compliance reality:
 *   - Layer 1 Data (50%):     DSPM core — discovery, classification, encryption
 *   - Layer 2 Process (30%):  RoPA, DPIA, RTP, vendor, cross-border
 *   - Layer 3 Response (20%): breach readiness, DSR SLA, maturity
 */
class PostureSnapshot extends Model
{
    use BelongsToOrg, HasUuids;

    public const SOURCE_SCHEDULED = 'scheduled';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_EVENT = 'event';

    protected $fillable = [
        'org_id', 'taken_at',
        'overall_score',
        'layer_data_score', 'layer_process_score', 'layer_response_score',
        'pillar_breakdown',
        'source',
    ];

    protected $casts = [
        'taken_at' => 'datetime',
        'overall_score' => 'integer',
        'layer_data_score' => 'integer',
        'layer_process_score' => 'integer',
        'layer_response_score' => 'integer',
        'pillar_breakdown' => 'array',
    ];
}
