<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TPRM Phase 3 — Vendor AI screening result.
 *
 * Append-only history. Setiap klik "AI Screening" -> row baru.
 * Untuk lihat status risk vendor terakhir, query: latest row by vendor_id
 * filter status=completed.
 */
class VendorScreening extends Model
{
    use HasUuids, BelongsToOrg, SoftDeletes;

    public const RISK_LOW = 'low';
    public const RISK_MEDIUM = 'medium';
    public const RISK_HIGH = 'high';
    public const RISK_CRITICAL = 'critical';
    public const RISK_UNKNOWN = 'unknown';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'org_id',
        'vendor_id',
        'triggered_by_user_id',
        'sources_used',
        'overall_risk',
        'risk_score',
        'findings',
        'red_flags',
        'summary',
        'recommendation',
        'search_results_raw',
        'privacy_policy_excerpt',
        'documents_summary',
        'sanctions_hits',
        'search_provider',
        'ai_model',
        'tokens_used',
        'status',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'sources_used' => 'array',
        'findings' => 'array',
        'red_flags' => 'array',
        'search_results_raw' => 'array',
        'privacy_policy_excerpt' => 'array',
        'documents_summary' => 'array',
        'sanctions_hits' => 'array',
        'risk_score' => 'integer',
        'tokens_used' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
