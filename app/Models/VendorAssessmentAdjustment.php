<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TPRM Phase 2 — Reviewer adjustment audit log.
 *
 * Setiap kali Reviewer (atau Approver) mengubah jawaban yang sudah
 * di-submit pihak ketiga, row baru ditulis di sini. Append-only —
 * tidak ada update / delete supaya audit trail tetap kredibel.
 *
 * Untuk audit eksternal BUMN: "kenapa skor vendor X turun dari 85 jadi
 * 60 setelah review padahal jawaban Ya semua?" → row di sini menjawab
 * dengan: siapa, kapan, dari apa ke apa, dan alasan.
 */
class VendorAssessmentAdjustment extends Model
{
    use HasUuids, BelongsToOrg;

    protected $table = 'vendor_assessment_adjustments';

    public const ACTION_OVERRIDE = 'override';
    public const ACTION_COMMENT = 'comment';
    public const ACTION_EVIDENCE_REVIEW = 'evidence_review';
    public const ACTION_RESTORE = 'restore';

    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_APPROVER = 'approver';

    protected $fillable = [
        'org_id',
        'assessment_id',
        'question_id',
        'action',
        'before_value',
        'before_note',
        'after_value',
        'after_note',
        'reason',
        'adjusted_by_user_id',
        'adjusted_by_role',
        'adjusted_at',
    ];

    protected $casts = [
        'adjusted_at' => 'datetime',
    ];

    public function assessment()
    {
        return $this->belongsTo(VendorAssessment::class, 'assessment_id');
    }

    public function question()
    {
        return $this->belongsTo(VendorQuestionnaire::class, 'question_id');
    }

    public function adjustedBy()
    {
        return $this->belongsTo(User::class, 'adjusted_by_user_id');
    }
}
