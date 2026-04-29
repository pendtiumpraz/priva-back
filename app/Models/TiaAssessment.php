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
     * Build the standard CBDT-derived prefill payload. Used by both the
     * explicit "Buat TIA" button (TiaController::fromCrossBorder) AND
     * the auto-trigger that fires on CrossBorder create
     * (AssessmentAutoTriggerService::fromCrossBorder) — single source of
     * truth so the two paths can't drift.
     *
     * Caller is responsible for tia_code, title, status, maker_id /
     * created_by — those vary by trigger source.
     *
     * @param  CrossBorderTransfer  $cbt
     * @return array<string, mixed>
     */
    public static function buildPrefillFromCrossBorder(CrossBorderTransfer $cbt): array
    {
        $adequacy = $cbt->adequacy();

        $volumeRisk = match ($cbt->transfer_volume_band) {
            'mass'   => 9,
            'large'  => 7,
            'medium' => 5,
            'small'  => 3,
            default  => null,
        };
        $sensitivityRisk = match ($cbt->data_sensitivity) {
            'extra_sensitive'    => 9,
            'sensitive_specific' => 7,
            'personal'           => 5,
            'general'            => 2,
            default              => null,
        };
        $protocolScore   = $cbt->encryption_in_transit ? 7 : 3;
        $encryptionScore = $cbt->encryption_at_rest    ? 7 : 3;

        return [
            'org_id' => $cbt->org_id,
            'linked_cross_border_id' => $cbt->id,
            'linked_ropa_id' => $cbt->linked_ropa_id,
            'destination_country' => $cbt->destination_country,
            'destination_has_pdp_law' => $adequacy?->has_pdp_law,
            'destination_has_pdp_authority' => $adequacy?->has_pdp_authority,
            'transfer_basis' => $cbt->legal_basis,
            'transfer_volume' => $cbt->transfer_volume_band,
            'transfer_frequency' => $cbt->transfer_frequency,
            'transfer_details' => [
                'destination_entity' => $cbt->destination_entity,
                'transfer_purpose' => $cbt->transfer_purpose,
                'data_categories' => $cbt->data_categories,
                'data_sensitivity' => $cbt->data_sensitivity,
                'transfer_mechanism' => $cbt->transfer_mechanism,
                'retention_period_days' => $cbt->retention_period_days,
                'recipient_dpo_name' => $cbt->recipient_dpo_name,
                'recipient_dpo_email' => $cbt->recipient_dpo_email,
            ],
            'risk_regulation_mismatch' => $adequacy?->default_regulation_mismatch,
            'risk_sovereign_access'    => $adequacy?->default_sovereign_access_risk,
            'risk_admin_sanctions'     => $adequacy?->default_admin_sanctions,
            'risk_data_leak'           => $volumeRisk,
            'risk_data_integrity'      => $sensitivityRisk,
            'security_protocol_score'   => $protocolScore,
            'security_encryption_score' => $encryptionScore,
            'wizard_data' => [
                'cross_border_id' => $cbt->id,
                'snapshot_taken_at' => now()->toIso8601String(),
                'adequacy_tier' => $adequacy?->tier,
                'adequacy_basis' => $adequacy?->basis,
                'cross_border_snapshot' => [
                    'destination_country' => $cbt->destination_country,
                    'destination_entity' => $cbt->destination_entity,
                    'transfer_purpose' => $cbt->transfer_purpose,
                    'legal_basis' => $cbt->legal_basis,
                    'data_categories' => $cbt->data_categories,
                    'transfer_volume_band' => $cbt->transfer_volume_band,
                    'transfer_frequency' => $cbt->transfer_frequency,
                    'data_sensitivity' => $cbt->data_sensitivity,
                    'transfer_mechanism' => $cbt->transfer_mechanism,
                    'encryption_in_transit' => $cbt->encryption_in_transit,
                    'encryption_at_rest' => $cbt->encryption_at_rest,
                    'data_minimization_applied' => $cbt->data_minimization_applied,
                    'retention_period_days' => $cbt->retention_period_days,
                    'recipient_dpo_name' => $cbt->recipient_dpo_name,
                    'recipient_dpo_email' => $cbt->recipient_dpo_email,
                ],
            ],
        ];
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
