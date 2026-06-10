<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-org override untuk metrik DEFAULT TIA.
 *
 * Kolom field (label/description/weight) yang NULL berarti "pakai nilai
 * default dari TiaAssessment::DEFAULT_METRICS" (weight default = 1).
 * Kind (risk|security) TIDAK pernah bisa di-override. is_active=false =
 * metrik default dinonaktifkan untuk org ini (reversible tombstone —
 * default tidak pernah bisa dihapus).
 *
 * Mirror dari GapQuestionOverride / MaturityQuestionOverride.
 */
class TiaMetricOverride extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'metric_code',
        'label',
        'description',
        'weight',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /** Field metrik default yang bisa di-override per org. */
    public const OVERRIDABLE_FIELDS = [
        'label', 'description', 'weight',
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

    /**
     * True kalau override ini benar-benar mengubah sesuatu:
     * minimal satu field non-null ATAU is_active=false.
     * Override yang semua kolomnya null + masih aktif adalah no-op
     * dan boleh dihapus.
     */
    public function hasEffect(): bool
    {
        if (! $this->is_active) {
            return true;
        }
        foreach (self::OVERRIDABLE_FIELDS as $field) {
            if ($this->{$field} !== null) {
                return true;
            }
        }

        return false;
    }
}
