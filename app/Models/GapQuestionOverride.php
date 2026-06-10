<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-org override untuk pertanyaan DEFAULT GAP Assessment.
 *
 * Kolom field (question/explanation/recommendation/category/subcategory/
 * article/weight) yang NULL berarti "pakai nilai default dari question bank".
 * is_active=false = pertanyaan default dinonaktifkan untuk org ini
 * (reversible tombstone — default tidak pernah bisa dihapus).
 */
class GapQuestionOverride extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'regulation_code',
        'question_id',
        'question',
        'explanation',
        'recommendation',
        'category',
        'subcategory',
        'article',
        'weight',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'float',
        'is_active' => 'boolean',
    ];

    /** Field default question yang bisa di-override (selain weight). */
    public const OVERRIDABLE_TEXT_FIELDS = [
        'question', 'explanation', 'recommendation', 'category', 'subcategory', 'article',
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

    public function scopeForRegulation($query, string $code)
    {
        return $query->where('regulation_code', $code);
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

        return $this->weight !== null;
    }
}
