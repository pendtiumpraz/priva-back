<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Custom pertanyaan triage Pre-Assessment per organisasi.
 *
 * question_code auto-generated (CUST-1, CUST-2, ...). is_core menentukan
 * apakah jawaban 'ya' pada pertanyaan ini membuat scope auto-suggest
 * 'in_scope' (decisive). Jawaban disimpan di VendorPreAssessment.answers
 * keyed by question_code.
 *
 * Mirror dari CustomLiaQuestion / CustomGapQuestion.
 */
class CustomTriageQuestion extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'question_code',
        'text',
        'description',
        'is_core',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_core' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Convert ke format yang sama dengan entri DEFAULT_QUESTIONS supaya bisa
     * di-merge seamless di VendorPreAssessment::effectiveQuestions().
     */
    public function toQuestionFormat(): array
    {
        return [
            'question_code' => $this->question_code,
            'text' => $this->text,
            'description' => $this->description,
            'is_core' => (bool) $this->is_core,
            'sort_order' => $this->sort_order,
            'is_custom' => true,
            'custom_id' => $this->id,
        ];
    }
}
