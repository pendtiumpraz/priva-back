<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Custom Maturity Assessment Question per organisasi.
 *
 * question_code auto-generated (CUST-1, CUST-2, ...). Domain wajib salah
 * satu dari MaturityQuestion::ALL_DOMAINS sehingga skor pertanyaan custom
 * ikut masuk ke rata-rata domain yang dipilih.
 */
class CustomMaturityQuestion extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'question_code',
        'domain',
        'question_text',
        'description',
        'regulation_ref',
        'scoring_guide',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'scoring_guide' => 'array',
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
     * Convert ke format yang sama dengan baris maturity_questions supaya
     * bisa di-merge seamless di MaturityQuestion::effectiveQuestions().
     */
    public function toQuestionFormat(): array
    {
        return [
            'id' => $this->id,
            'question_code' => $this->question_code,
            'domain' => $this->domain,
            'regulation_ref' => $this->regulation_ref,
            'question_text' => $this->question_text,
            'description' => $this->description,
            'scoring_guide' => $this->scoring_guide,
            'sort_order' => $this->sort_order,
            'is_custom' => true,
            'custom_id' => $this->id,
        ];
    }
}
