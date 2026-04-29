<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class LiaAssessment extends Model
{
    use HasUuids, SoftDeletes, BelongsToOrg;

    protected $fillable = [
        'org_id', 'lia_code', 'title', 'description', 'processing_activity',
        'linked_ropa_id', 'linked_dpia_id',
        'legitimate_interest_basis', 'legitimate_interest_reason',
        'purpose_test', 'necessity_test', 'balancing_test',
        'balancing_risk_events',
        'subject_loses_control', 'subject_loses_control_reason',
        'conclusion_purpose', 'conclusion_necessity', 'conclusion_balancing', 'conclusion_notes',
        'maker_id', 'checker_id', 'approver_id',
        'submitted_at', 'checked_at', 'approved_at', 'rejected_at',
        'rejection_reason', 'is_locked', 'unlocked_by', 'unlocked_at',
        'overall_score', 'assessment_result', 'status',
        'wizard_data', 'created_by',
    ];

    protected $casts = [
        'purpose_test' => 'array',
        'necessity_test' => 'array',
        'balancing_test' => 'array',
        'balancing_risk_events' => 'array',
        'wizard_data' => 'array',
        'overall_score' => 'decimal:2',
        'is_locked' => 'boolean',
        'submitted_at' => 'datetime',
        'checked_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'unlocked_at' => 'datetime',
    ];

    // Status state machine
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CHECKED = 'checked';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const VERDICT_PASS = 'lulus';
    public const VERDICT_FAIL = 'tidak_lulus';

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class, 'linked_ropa_id');
    }

    public function dpia()
    {
        return $this->belongsTo(\App\Models\Dpia::class, 'linked_dpia_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function maker()
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checker_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Whether the record is editable for the given user. Locked records
     * are read-only except for root performing an emergency unlock.
     */
    public function isEditableBy(?\App\Models\User $user): bool
    {
        if (!$user) return false;
        if (!$this->is_locked) return true;
        return $user->role === 'root';
    }

    /**
     * Compute the overall LIA verdict from the three test conclusions.
     * Returns 'lulus' only if all three are pass, otherwise 'tidak_lulus'.
     * Null kalau approver belum mengisi.
     */
    public function overallVerdict(): ?string
    {
        $verdicts = array_filter([
            $this->conclusion_purpose,
            $this->conclusion_necessity,
            $this->conclusion_balancing,
        ]);
        if (count($verdicts) < 3) return null;
        return collect($verdicts)->every(fn ($v) => $v === self::VERDICT_PASS)
            ? self::VERDICT_PASS
            : self::VERDICT_FAIL;
    }
}
