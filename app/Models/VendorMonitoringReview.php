<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TPRM Phase 4 — Periodic monitoring review (append-only audit).
 */
class VendorMonitoringReview extends Model
{
    use HasUuids, BelongsToOrg;

    public const DECISION_CONTINUE = 'continue';
    public const DECISION_CONTINUE_WITH_NOTE = 'continue_with_note';
    public const DECISION_IMPROVEMENT_REQUIRED = 'improvement_required';
    public const DECISION_TERMINATE = 'terminate';

    public const ALL_DECISIONS = [
        self::DECISION_CONTINUE,
        self::DECISION_CONTINUE_WITH_NOTE,
        self::DECISION_IMPROVEMENT_REQUIRED,
        self::DECISION_TERMINATE,
    ];

    public const DECISION_LABELS = [
        self::DECISION_CONTINUE => 'Lanjutkan',
        self::DECISION_CONTINUE_WITH_NOTE => 'Lanjut dengan Catatan',
        self::DECISION_IMPROVEMENT_REQUIRED => 'Perlu Perbaikan',
        self::DECISION_TERMINATE => 'Rekomendasi Putus Kontrak',
    ];

    protected $fillable = [
        'org_id',
        'monitoring_id',
        'vendor_id',
        'reviewer_user_id',
        'reviewed_at',
        'checklist_state',
        'decision',
        'notes',
        'action_items',
        'related_incident_ids',
        'risk_level_snapshot',
        'risk_score_snapshot',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'checklist_state' => 'array',
        'related_incident_ids' => 'array',
        'risk_score_snapshot' => 'integer',
    ];

    public function monitoring()
    {
        return $this->belongsTo(VendorMonitoring::class, 'monitoring_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
