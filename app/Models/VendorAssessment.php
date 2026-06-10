<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorAssessment extends Model
{
    use HasFactory, HasUuids;

    public const SOURCE_DETERMINISTIC = 'deterministic';
    public const SOURCE_AI = 'ai';
    public const SOURCE_IMPORTED = 'imported';

    // Phase 2 — workflow status constants (3-stage Maker→Reviewer→Approver)
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_SUBMITTED = 'submitted';            // vendor submitted, ready for review
    public const STATUS_REVIEW_IN_PROGRESS = 'review_in_progress';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** Status di mana workflow sudah final dan tidak boleh diubah lagi. */
    public const STATUS_FINAL = [self::STATUS_APPROVED, self::STATUS_REJECTED];

    /**
     * Allowed transitions per state. Format: from => [allowed next states].
     * Centralized di sini supaya controller cuma cek canTransition().
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SENT],
        self::STATUS_SENT => [self::STATUS_SUBMITTED, self::STATUS_DRAFT],
        self::STATUS_SUBMITTED => [self::STATUS_REVIEW_IN_PROGRESS],
        self::STATUS_REVIEW_IN_PROGRESS => [self::STATUS_PENDING_APPROVAL, self::STATUS_SUBMITTED],
        self::STATUS_PENDING_APPROVAL => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_REVIEW_IN_PROGRESS],
        self::STATUS_APPROVED => [],   // final
        self::STATUS_REJECTED => [self::STATUS_REVIEW_IN_PROGRESS], // boleh reopen kalau diperlukan
    ];

    protected $fillable = [
        'vendor_id',
        'org_id',
        'library_id',
        'assessed_by',
        'answers',
        'score',
        'risk_level',
        'recommendations',
        'notes',
        'source',
        'category',
        'score_breakdown',
        'questionnaire_version',
        'assessment_token',
        'token_expires_at',
        'token_consumed_at',
        'status',
        'submitted_at',
        'submitted_ip',
        'submitted_user_agent',
        // Phase 2 workflow fields
        'assigned_reviewer_id',
        'assigned_approver_id',
        'reviewer_id',
        'reviewer_actioned_at',
        'reviewer_note',
        'approver_id',
        'approver_actioned_at',
        'approver_note',
        'rejection_reason',
        'workflow_locked',
        // Sprint X5 follow-up — hasil AI document analysis per pertanyaan
        // (ai_analyses[question_id][] keyed by attachment_path). ADVISORY
        // ONLY — tidak pernah masuk ThirdPartyAssessmentScorer.
        'ai_analyses',
    ];

    protected $casts = [
        'answers' => 'array',
        'ai_analyses' => 'array',
        'recommendations' => 'array',
        'score_breakdown' => 'array',
        'score' => 'integer',
        'token_expires_at' => 'datetime',
        'token_consumed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewer_actioned_at' => 'datetime',
        'approver_actioned_at' => 'datetime',
        'workflow_locked' => 'boolean',
    ];

    /**
     * Cek transisi diperbolehkan dari status saat ini ke status target.
     * Tidak melakukan persistasi — controller harus update sendiri.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->workflow_locked) {
            return false;
        }
        $allowed = self::TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    /**
     * Apakah assessment sudah masuk fase review (sudah di-submit vendor).
     */
    public function isReadyForReview(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUBMITTED,
            self::STATUS_REVIEW_IN_PROGRESS,
        ], true);
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::STATUS_FINAL, true);
    }

    /**
     * Agregasi hasil AI analysis untuk satu pertanyaan ke 1 verdict (mirror
     * TiaAssessment::aggregateAiVerdict). Banyak dokumen per pertanyaan →
     * pilih WORST status (paling konservatif). `unsure` di-skip; kalau semua
     * unsure return null.
     *
     * PENTING: verdict ini ADVISORY ONLY — tidak pernah mengubah jawaban
     * vendor / reviewer dan TIDAK dipakai ThirdPartyAssessmentScorer.
     * UI menampilkannya sebagai badge per pertanyaan di halaman review.
     *
     * @param  mixed  $value  Bisa null, single object (legacy), atau array of objects.
     */
    public static function aggregateAiVerdict(mixed $value): ?string
    {
        if (empty($value) || ! is_array($value)) return null;
        // Legacy single object
        $entries = isset($value['status']) ? [$value] : array_values($value);
        $rank = ['non_comply' => 3, 'partial' => 2, 'comply' => 1];
        $worst = null;
        $worstRank = 0;
        foreach ($entries as $e) {
            $st = is_array($e) ? ($e['status'] ?? null) : null;
            if (! $st || $st === 'unsure') continue;
            $r = $rank[$st] ?? 0;
            if ($r > $worstRank) {
                $worst = $st;
                $worstRank = $r;
            }
        }
        return $worst;
    }

    public function evidence()
    {
        return $this->hasMany(VendorAssessmentEvidence::class, 'assessment_id');
    }

    public function adjustments()
    {
        return $this->hasMany(VendorAssessmentAdjustment::class, 'assessment_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
