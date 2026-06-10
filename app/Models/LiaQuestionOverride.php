<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-org override untuk pertanyaan panduan DEFAULT wizard LIA.
 *
 * Kolom field (label/description) yang NULL berarti "pakai nilai default
 * dari LiaAssessment::DEFAULT_QUESTIONS". Test (purpose|necessity|
 * balancing) TIDAK pernah bisa di-override. is_active=false = pertanyaan
 * default dinonaktifkan untuk org ini (reversible tombstone — default
 * tidak pernah bisa dihapus).
 *
 * LIA kualitatif — tidak ada weight/scoring di sini.
 * Mirror dari GapQuestionOverride / MaturityQuestionOverride /
 * TiaMetricOverride.
 */
class LiaQuestionOverride extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'question_code',
        'label',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Field pertanyaan default yang bisa di-override per org. */
    public const OVERRIDABLE_FIELDS = [
        'label', 'description',
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
