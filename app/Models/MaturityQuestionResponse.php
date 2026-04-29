<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-question score for a Maturity Assessment. One row per
 * (assessment_id, question_code).
 */
class MaturityQuestionResponse extends Model
{
    use HasUuids;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTO_DERIVE = 'auto_derive';
    public const SOURCE_DOCUMENT_AI = 'document_ai';

    protected $fillable = [
        'assessment_id', 'question_code', 'domain',
        'score', 'notes', 'source', 'source_metadata',
    ];

    protected $casts = [
        'score' => 'integer',
        'source_metadata' => 'array',
    ];

    public function assessment()
    {
        return $this->belongsTo(MaturityAssessment::class, 'assessment_id');
    }
}
