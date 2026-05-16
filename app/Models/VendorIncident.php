<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TPRM Phase 4 — Incident report terhadap pihak ketiga.
 */
class VendorIncident extends Model
{
    use HasUuids, BelongsToOrg, SoftDeletes;

    public const KIND_SLA_BREACH = 'sla_breach';
    public const KIND_DATA_BREACH = 'data_breach';
    public const KIND_CONTRACT_VIOLATION = 'contract_violation';
    public const KIND_COMPLIANCE_FAILURE = 'compliance_failure';
    public const KIND_SERVICE_OUTAGE = 'service_outage';
    public const KIND_FINANCIAL_DEFAULT = 'financial_default';
    public const KIND_REPUTATION_EVENT = 'reputation_event';
    public const KIND_OTHER = 'other';

    public const ALL_KINDS = [
        self::KIND_SLA_BREACH,
        self::KIND_DATA_BREACH,
        self::KIND_CONTRACT_VIOLATION,
        self::KIND_COMPLIANCE_FAILURE,
        self::KIND_SERVICE_OUTAGE,
        self::KIND_FINANCIAL_DEFAULT,
        self::KIND_REPUTATION_EVENT,
        self::KIND_OTHER,
    ];

    public const KIND_LABELS = [
        self::KIND_SLA_BREACH => 'Pelanggaran SLA',
        self::KIND_DATA_BREACH => 'Kebocoran Data',
        self::KIND_CONTRACT_VIOLATION => 'Pelanggaran Kontrak',
        self::KIND_COMPLIANCE_FAILURE => 'Gagal Kepatuhan',
        self::KIND_SERVICE_OUTAGE => 'Service Outage',
        self::KIND_FINANCIAL_DEFAULT => 'Gagal Bayar / Pailit',
        self::KIND_REPUTATION_EVENT => 'Reputational Event',
        self::KIND_OTHER => 'Lainnya',
    ];

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';
    public const ALL_SEVERITIES = [
        self::SEVERITY_LOW, self::SEVERITY_MEDIUM, self::SEVERITY_HIGH, self::SEVERITY_CRITICAL,
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_MITIGATED = 'mitigated';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ESCALATED = 'escalated';
    public const ALL_STATUSES = [
        self::STATUS_OPEN, self::STATUS_INVESTIGATING, self::STATUS_MITIGATED,
        self::STATUS_RESOLVED, self::STATUS_ESCALATED,
    ];

    protected $fillable = [
        'org_id',
        'vendor_id',
        'reporter_user_id',
        'kind',
        'severity',
        'title',
        'description',
        'occurred_at',
        'detected_at',
        'resolved_at',
        'status',
        'resolution_note',
        'resolved_by',
        'evidence_files',
        'impact_score_delta',
        'applied_to_risk_score',
        'related_screening_id',
        'related_review_id',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'evidence_files' => 'array',
        'impact_score_delta' => 'integer',
        'applied_to_risk_score' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
