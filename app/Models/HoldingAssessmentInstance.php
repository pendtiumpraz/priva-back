<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Holding Compliance Assessment — Instance (dispatched ke satu target org).
 *
 * Dimiliki org HOLDING (org_id). Diisi via public token (pola TPRM). Pertanyaan
 * dibekukan di questions_snapshot saat dispatch. answers & ai_analyses JSON.
 * Bukti evidence di tabel terpisah (HoldingAssessmentEvidence, 1:N).
 */
class HoldingAssessmentInstance extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $table = 'holding_assessment_instances';

    protected $fillable = [
        'org_id',
        'template_id',
        'source_org_id',
        'target_org_id',
        'target_org_name',
        'title',
        'regulation_code',
        'regulation_name',
        'type',
        'questions_snapshot',
        'answers',
        'ai_analyses',
        'status',
        'overall_score',
        'compliance_level',
        'maturity_level',
        'progress',
        'assessment_token',
        'token_expires_at',
        'token_consumed_at',
        'submitted_at',
        'submitted_ip',
        'submitted_user_agent',
        'reviewer_id',
        'reviewed_at',
        'review_status',
        'review_notes',
        'review_data',
        'dispatched_at',
        'created_by',
    ];

    protected $casts = [
        'questions_snapshot' => 'array',
        'answers' => 'array',
        'ai_analyses' => 'array',
        'review_data' => 'array',
        'overall_score' => 'decimal:2',
        'maturity_level' => 'integer',
        'progress' => 'decimal:2',
        'token_expires_at' => 'datetime',
        'token_consumed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];

    // Hidden dari serialisasi default supaya token tidak bocor ke response biasa.
    protected $hidden = [
        'assessment_token',
    ];

    public function template()
    {
        return $this->belongsTo(HoldingAssessmentTemplate::class, 'template_id');
    }

    public function targetOrg()
    {
        return $this->belongsTo(Organization::class, 'target_org_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function evidence()
    {
        return $this->hasMany(HoldingAssessmentEvidence::class, 'instance_id');
    }

    public function activeEvidence()
    {
        return $this->evidence()->where('is_active', true);
    }

    public function isLocked(): bool
    {
        return $this->token_consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Pertanyaan efektif untuk instance ini: snapshot kalau ada (sudah dibekukan),
     * jika belum di-dispatch fallback ke pertanyaan aktif template.
     */
    public function effectiveQuestions(): array
    {
        if (! empty($this->questions_snapshot) && is_array($this->questions_snapshot)) {
            return $this->questions_snapshot;
        }
        $tpl = $this->template;
        if (! $tpl) {
            return [];
        }

        return $tpl->activeQuestions()->get()->map(fn ($q) => $q->toSnapshot())->all();
    }
}
