<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TiaAssessment extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'tia_code', 'title',
        'linked_cross_border_id', 'linked_ropa_id', 'linked_vendor_id',
        'transfer_details', 'legal_framework', 'risk_assessment', 'supplementary_measures',
        'transfer_volume', 'transfer_frequency', 'transfer_basis', 'transfer_basis_other',
        'destination_country', 'destination_has_pdp_law', 'destination_has_pdp_authority',
        'recipient_maturity_score', 'sender_maturity_score',
        'risk_regulation_mismatch', 'risk_contractual_breach', 'risk_admin_sanctions',
        'risk_data_leak', 'risk_data_integrity', 'risk_sovereign_access',
        'security_protocol_score', 'security_encryption_score',
        'overall_risk_score', 'overall_risk_level',
        'supplementary_doc_ids',
        'maker_id', 'checker_id', 'approver_id',
        'submitted_at', 'checked_at', 'approved_at', 'rejected_at',
        'rejection_reason', 'is_locked', 'unlocked_by', 'unlocked_at',
        'conclusion_verdict', 'conclusion_notes',
        'status', 'wizard_data', 'created_by',
    ];

    protected $casts = [
        'transfer_details' => 'array',
        'legal_framework' => 'array',
        'risk_assessment' => 'array',
        'supplementary_measures' => 'array',
        'wizard_data' => 'array',
        'supplementary_doc_ids' => 'array',
        'destination_has_pdp_law' => 'boolean',
        'destination_has_pdp_authority' => 'boolean',
        'is_locked' => 'boolean',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'unlocked_at' => 'datetime',
        'overall_risk_score' => 'decimal:2',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CHECKED = 'checked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const VERDICT_APPROVED    = 'approved';      // safe to transfer
    public const VERDICT_CONDITIONAL = 'conditional';   // safe with mitigations
    public const VERDICT_REJECTED    = 'rejected';      // unsafe — block transfer

    /**
     * 6 risk metric keys (high score = risky). Used by computeOverallRisk()
     * and by the frontend MaturityRuler with `inverted=true`.
     */
    public const RISK_METRIC_KEYS = [
        'risk_regulation_mismatch',
        'risk_contractual_breach',
        'risk_admin_sanctions',
        'risk_data_leak',
        'risk_data_integrity',
        'risk_sovereign_access',
    ];

    /**
     * 2 security metric keys (high score = good — provides mitigation).
     */
    public const SECURITY_METRIC_KEYS = [
        'security_protocol_score',
        'security_encryption_score',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function crossBorder()
    {
        return $this->belongsTo(CrossBorderTransfer::class, 'linked_cross_border_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class, 'linked_ropa_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'linked_vendor_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function maker()
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checker_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function isEditableBy(?User $user): bool
    {
        if (!$user) return false;
        if (!$this->is_locked) return true;
        return $user->role === 'root';
    }

    /**
     * Compute overall risk score on a 1-10 scale.
     *   - Risk metrics (6 of them) average → raw risk
     *   - Security metrics (2 of them) average → mitigation factor (1-10 → 0-1)
     *   - residual = raw_risk × (1 − mitigation_factor × 0.5)
     *     i.e. perfect security halves the raw risk; no security leaves it unchanged.
     *
     * Returns null if any required metric is unset.
     */
    public function computeOverallRisk(): ?float
    {
        $riskValues = collect(self::RISK_METRIC_KEYS)->map(fn ($k) => $this->$k)->filter(fn ($v) => $v !== null);
        $securityValues = collect(self::SECURITY_METRIC_KEYS)->map(fn ($k) => $this->$k)->filter(fn ($v) => $v !== null);

        if ($riskValues->count() < count(self::RISK_METRIC_KEYS)) return null;

        $rawRisk = $riskValues->avg();
        $mitigation = $securityValues->count() > 0 ? $securityValues->avg() / 10 : 0;
        $residual = $rawRisk * (1 - $mitigation * 0.5);

        return round((float) $residual, 2);
    }

    /**
     * Map the overall risk score to a level string for badging.
     */
    public function riskLevel(): ?string
    {
        $score = $this->computeOverallRisk();
        if ($score === null) return null;
        if ($score >= 7) return 'high';
        if ($score >= 4) return 'medium';
        return 'low';
    }
}
