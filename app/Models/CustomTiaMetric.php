<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Custom TIA metric per organisasi.
 *
 * metric_code auto-generated (CUST-1, CUST-2, ...). Kind wajib 'risk'
 * (skor 10 = paling berisiko, masuk komponen risiko) atau 'security'
 * (skor 10 = paling aman, masuk komponen mitigasi) sehingga skor metrik
 * custom ikut masuk weighted average yang tepat di
 * TiaAssessment::computeOverallRisk().
 *
 * Mirror dari CustomGapQuestion / CustomMaturityQuestion.
 */
class CustomTiaMetric extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_RISK = 'risk';
    public const KIND_SECURITY = 'security';

    public const ALL_KINDS = [
        self::KIND_RISK,
        self::KIND_SECURITY,
    ];

    protected $fillable = [
        'org_id',
        'metric_code',
        'kind',
        'label',
        'description',
        'weight',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    // =============================================
    // Relationships
    // =============================================

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    // =============================================
    // Scopes
    // =============================================

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Convert ke format yang sama dengan entri DEFAULT_METRICS supaya
     * bisa di-merge seamless di TiaAssessment::effectiveMetrics().
     */
    public function toMetricFormat(): array
    {
        return [
            'metric_code' => $this->metric_code,
            'kind' => $this->kind,
            'label' => $this->label,
            'description' => $this->description,
            'weight' => (float) $this->weight,
            'sort_order' => $this->sort_order,
            'is_custom' => true,
            'custom_id' => $this->id,
        ];
    }
}
