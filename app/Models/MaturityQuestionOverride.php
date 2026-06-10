<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-org override untuk pertanyaan DEFAULT Maturity Assessment.
 *
 * Kolom field (question_text/description/regulation_ref/scoring_guide)
 * yang NULL berarti "pakai nilai default dari maturity_questions".
 * Domain TIDAK pernah bisa di-override. is_active=false = pertanyaan
 * default dinonaktifkan untuk org ini (reversible tombstone — default
 * tidak pernah bisa dihapus).
 */
class MaturityQuestionOverride extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'question_code',
        'question_text',
        'description',
        'regulation_ref',
        'scoring_guide',
        'is_active',
    ];

    protected $casts = [
        'scoring_guide' => 'array',
        'is_active' => 'boolean',
    ];

    /** Field teks default question yang bisa di-override (selain scoring_guide). */
    public const OVERRIDABLE_TEXT_FIELDS = [
        'question_text', 'description', 'regulation_ref',
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
        foreach (self::OVERRIDABLE_TEXT_FIELDS as $field) {
            if ($this->{$field} !== null) {
                return true;
            }
        }

        return $this->scoring_guide !== null;
    }
}
