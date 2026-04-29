<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaturityAssessment extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'title', 'version', 'dimensions', 'overall_level', 'overall_score',
        'recommendations', 'status', 'created_by',
        'input_method', 'domain_scores', 'uploaded_doc_ids',
        'submitted_at', 'submitted_by', 'auto_derived_at', 'auto_derive_metadata',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'recommendations' => 'array',
        'overall_level' => 'integer',
        'overall_score' => 'decimal:2',
        'domain_scores' => 'array',
        'uploaded_doc_ids' => 'array',
        'auto_derive_metadata' => 'array',
        'submitted_at' => 'datetime',
        'auto_derived_at' => 'datetime',
    ];

    public const INPUT_QUESTIONNAIRE = 'questionnaire';
    public const INPUT_DOCUMENT = 'document';
    public const INPUT_AUTO_DERIVE = 'auto_derive';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PUBLISHED = 'published';

    public const LEVEL_AD_HOC = 1;
    public const LEVEL_DEFINED = 2;
    public const LEVEL_MANAGED = 3;
    public const LEVEL_OPTIMIZED = 4;

    public const LEVEL_LABELS = [
        self::LEVEL_AD_HOC    => 'Ad-hoc',
        self::LEVEL_DEFINED   => 'Defined',
        self::LEVEL_MANAGED   => 'Managed',
        self::LEVEL_OPTIMIZED => 'Optimized',
    ];

    public function responses()
    {
        return $this->hasMany(MaturityQuestionResponse::class, 'assessment_id');
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Map an overall_score (0-10 range) to one of the 4 maturity levels
     * per PDF spec: 1-3 ad-hoc, 4-6 defined, 7-8 managed, 9-10 optimized.
     */
    public static function scoreToLevel(float $score): int
    {
        if ($score >= 9) return self::LEVEL_OPTIMIZED;
        if ($score >= 7) return self::LEVEL_MANAGED;
        if ($score >= 4) return self::LEVEL_DEFINED;
        return self::LEVEL_AD_HOC;
    }

    public function levelLabel(): string
    {
        return self::LEVEL_LABELS[$this->overall_level] ?? 'Ad-hoc';
    }

    /**
     * Compute domain_scores + overall_score from the responses() collection.
     * Called after every save in the controller.
     */
    public function recompute(): void
    {
        $byDomain = $this->responses()->get()->groupBy('domain');
        $domainScores = [];
        foreach (MaturityQuestion::ALL_DOMAINS as $d) {
            $items = $byDomain->get($d, collect());
            $domainScores[$d] = $items->isEmpty() ? null : round($items->avg('score'), 2);
        }
        $this->domain_scores = $domainScores;

        $allScores = $this->responses()->pluck('score');
        if ($allScores->isNotEmpty()) {
            $this->overall_score = round($allScores->avg(), 2);
            $this->overall_level = self::scoreToLevel((float) $this->overall_score);
        }
    }

    public const DIMENSIONS = ['governance', 'process', 'technology', 'people', 'compliance'];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
