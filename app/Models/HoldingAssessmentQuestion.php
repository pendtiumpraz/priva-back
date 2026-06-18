<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Holding Compliance Assessment — Question (milik satu template).
 *
 * Field mirror GAP question bank: category/subcategory (grouping), weight
 * (scoring), recommendation (saat jawaban non-comply), regulation_ref (pasal),
 * requires_evidence (pihak yang dinilai wajib upload bukti).
 */
class HoldingAssessmentQuestion extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $table = 'holding_assessment_questions';

    protected $fillable = [
        'org_id',
        'template_id',
        'category',
        'subcategory',
        'question_code',
        'question',
        'explanation',
        'recommendation',
        'regulation_ref',
        'weight',
        'requires_evidence',
        'answer_type',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'requires_evidence' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(HoldingAssessmentTemplate::class, 'template_id');
    }

    /**
     * Bentuk snapshot ringkas untuk dibekukan di instance saat dispatch + dikirim
     * ke public page (tanpa info scoring internal seperti weight kalau perlu).
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'question_code' => $this->question_code,
            'question' => $this->question,
            'explanation' => $this->explanation,
            'recommendation' => $this->recommendation,
            'regulation_ref' => $this->regulation_ref,
            'weight' => (float) $this->weight,
            'requires_evidence' => (bool) $this->requires_evidence,
            'answer_type' => $this->answer_type,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
