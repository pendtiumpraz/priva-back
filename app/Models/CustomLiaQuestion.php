<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Custom LIA wizard question per organisasi.
 *
 * question_code auto-generated (CUST-1, CUST-2, ...). Test wajib
 * 'purpose' | 'necessity' | 'balancing' supaya pertanyaan muncul di step
 * wizard yang tepat. Jawaban disimpan free-text (textarea) di JSON
 * per-test record LIA (purpose_test / necessity_test / balancing_test),
 * keyed by question_code. TIDAK ada dampak scoring — verdict LIA tetap
 * diputuskan manual oleh Approver.
 *
 * Mirror dari CustomGapQuestion / CustomMaturityQuestion / CustomTiaMetric.
 */
class CustomLiaQuestion extends Model
{
    use HasUuids, SoftDeletes;

    public const TEST_PURPOSE = 'purpose';
    public const TEST_NECESSITY = 'necessity';
    public const TEST_BALANCING = 'balancing';

    public const ALL_TESTS = [
        self::TEST_PURPOSE,
        self::TEST_NECESSITY,
        self::TEST_BALANCING,
    ];

    protected $fillable = [
        'org_id',
        'question_code',
        'test',
        'label',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
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
     * Convert ke format yang sama dengan entri DEFAULT_QUESTIONS supaya
     * bisa di-merge seamless di LiaAssessment::effectiveQuestions().
     * Custom question selalu dijawab free-text (textarea), disimpan di
     * JSON test terkait keyed by question_code.
     */
    public function toQuestionFormat(): array
    {
        return [
            'question_code' => $this->question_code,
            'test' => $this->test,
            'input_type' => 'textarea',
            'storage' => 'json',
            'label' => $this->label,
            'description' => $this->description,
            'reason_code' => null,
            'other_code' => null,
            'options' => null,
            'sort_order' => $this->sort_order,
            'is_custom' => true,
            'custom_id' => $this->id,
        ];
    }
}
