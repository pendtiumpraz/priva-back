<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-org override untuk pertanyaan triage DEFAULT Pre-Assessment.
 *
 * Field (text/description/is_core) yang NULL berarti "pakai nilai default
 * dari VendorPreAssessment::DEFAULT_QUESTIONS". is_active=false = pertanyaan
 * default dinonaktifkan untuk org ini (reversible tombstone — default tidak
 * pernah bisa dihapus).
 *
 * Berbeda dengan LiaQuestionOverride: di sini is_core BISA di-override (per
 * org boleh menjadikan pertanyaan tertentu decisive / tidak), karena is_core
 * yang menentukan suggestScope.
 *
 * Mirror dari LiaQuestionOverride / GapQuestionOverride.
 */
class TriageQuestionOverride extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'question_code',
        'text',
        'description',
        'is_core',
        'is_active',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'is_active' => 'boolean',
    ];

    /** Field pertanyaan default yang bisa di-override per org. */
    public const OVERRIDABLE_FIELDS = [
        'text', 'description', 'is_core',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    /**
     * True kalau override ini benar-benar mengubah sesuatu: minimal satu
     * field non-null ATAU is_active=false. Override yang semua kolomnya null
     * + masih aktif adalah no-op dan boleh dihapus.
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
