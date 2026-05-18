<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorAssessmentAdjustment;
use App\Models\VendorAssessmentEvidence;
use App\Models\VendorQuestionnaire;
use App\Services\ThirdPartyAssessmentScorer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TPRM Phase 2 — Reviewer workflow (stage 2 dari 3-stage approval).
 *
 * Flow:
 *   submitted (vendor done)
 *     → POST /start-review   → status=review_in_progress, reviewer_id=user_aktual
 *     → POST /adjust         → tulis ke vendor_assessment_adjustments, update answers
 *     → POST /submit-to-approver → status=pending_approval, kirim ke approver
 *     → POST /reject-to-vendor   → status=sent, vendor harus isi ulang
 *
 * Permission slug: vendor_risk,write.
 *
 * Catatan multi-tenant: VendorAssessment belum pakai BelongsToOrg trait,
 * jadi filter org_id manual di setiap query.
 */
class TprmReviewController extends Controller
{
    /**
     * GET /api/tprm/review/inbox
     *
     * List assessment yang menunggu di-review (status=submitted) atau sedang
     * di-review (review_in_progress) oleh user ini.
     */
    public function inbox(Request $request)
    {
        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;

        // Filter: assessment yang assigned ke user ini ATAU semua yang
        // belum di-assign (open pool) — tergantung kebijakan organisasi.
        // Default tampilkan keduanya supaya tim review fleksibel.
        $rows = VendorAssessment::query()
            ->where('org_id', $orgId)
            ->whereIn('status', [
                VendorAssessment::STATUS_SUBMITTED,
                VendorAssessment::STATUS_REVIEW_IN_PROGRESS,
            ])
            ->where(function ($q) use ($userId) {
                $q->whereNull('assigned_reviewer_id')
                    ->orWhere('assigned_reviewer_id', $userId);
            })
            ->orderByDesc('submitted_at')
            ->limit(100)
            ->get();

        $vendorIds = $rows->pluck('vendor_id')->unique();
        $vendors = Vendor::query()
            ->where('org_id', $orgId)
            ->whereIn('id', $vendorIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(function ($a) use ($vendors, $userId) {
                $v = $vendors->get($a->vendor_id);
                return [
                    'id' => $a->id,
                    'vendor_id' => $a->vendor_id,
                    'vendor_name' => $v?->name,
                    'vendor_category' => $v?->category,
                    'status' => $a->status,
                    'score' => $a->score,
                    'risk_level' => $a->risk_level,
                    'submitted_at' => $a->submitted_at?->toIso8601String(),
                    'assigned_reviewer_id' => $a->assigned_reviewer_id,
                    'is_assigned_to_me' => $a->assigned_reviewer_id === $userId,
                    'library_id' => $a->library_id,
                ];
            })->values(),
        ]);
    }

    /**
     * GET /api/tprm/review/{id}
     *
     * Detail assessment + jawaban + evidence + adjustment history untuk
     * halaman review side-by-side.
     */
    public function show(Request $request, string $id)
    {
        try {
            return $this->doShow($request, $id);
        } catch (\Throwable $e) {
            \Log::error('TprmReviewController::show failed', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
            return response()->json([
                'message' => 'Gagal memuat detail review.',
                'error' => $e->getMessage(),
                'hint' => 'Pastikan migration Phase 2 sudah dijalankan: workflow_locked, reviewer_id, assigned_reviewer_id columns ada di vendor_assessments + tabel vendor_assessment_evidence + vendor_assessment_adjustments.',
            ], 500);
        }
    }

    private function doShow(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);
        $vendor = Vendor::find($assessment->vendor_id);

        // Load questions yang relevan untuk library_id assessment ini.
        // Fallback ke effectiveForOrg legacy kalau library_id null.
        if (! empty($assessment->library_id)) {
            $questions = VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('library_id', $assessment->library_id)
                ->where('is_active', true)
                ->orderBy('section')
                ->orderBy('sort_order')
                ->get();
        } else {
            $questions = VendorQuestionnaire::effectiveForOrg($assessment->org_id)
                ->filter(fn ($q) => $q->is_active && $q->version === ThirdPartyAssessmentScorer::VERSION)
                ->sortBy(['section', 'sort_order'])
                ->values();
        }

        $evidenceRows = VendorAssessmentEvidence::query()
            ->withoutGlobalScope('org')
            ->where('assessment_id', $assessment->id)
            ->where('is_active', true)
            ->get()
            ->groupBy('question_id');

        $adjustments = VendorAssessmentAdjustment::query()
            ->withoutGlobalScope('org')
            ->where('assessment_id', $assessment->id)
            ->orderByDesc('adjusted_at')
            ->get();

        return response()->json([
            'data' => [
                'assessment' => [
                    'id' => $assessment->id,
                    'status' => $assessment->status,
                    'score' => $assessment->score,
                    'risk_level' => $assessment->risk_level,
                    'submitted_at' => $assessment->submitted_at?->toIso8601String(),
                    'assigned_reviewer_id' => $assessment->assigned_reviewer_id,
                    'reviewer_id' => $assessment->reviewer_id,
                    'reviewer_actioned_at' => $assessment->reviewer_actioned_at?->toIso8601String(),
                    'reviewer_note' => $assessment->reviewer_note,
                    'assigned_approver_id' => $assessment->assigned_approver_id,
                    'workflow_locked' => $assessment->workflow_locked,
                ],
                'vendor' => $vendor ? [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'category' => $vendor->category,
                ] : null,
                'questions' => $questions->map(fn ($q) => [
                    'id' => $q->id,
                    'question_code' => $q->question_code,
                    'section' => $q->section,
                    'question_text' => $q->question_text,
                    'description' => $q->description,
                    'regulation_ref' => $q->regulation_ref,
                    'answer_type' => $q->answer_type,
                    'requires_evidence_upload' => (bool) $q->requires_evidence_upload,
                    'weight' => $q->weight,
                    'direction' => $q->direction,
                ])->values(),
                'answers' => $assessment->answers ?? [],
                'evidence_by_question' => $evidenceRows->map(fn ($items) => $items->map(fn ($e) => [
                    'id' => $e->id,
                    'original_name' => $e->original_name,
                    'mime_type' => $e->mime_type,
                    'file_size' => $e->file_size,
                    'uploaded_at' => $e->created_at?->toIso8601String(),
                    'uploaded_by_token' => $e->uploaded_by_token,
                ])->values()),
                'adjustments' => $adjustments->map(fn ($a) => [
                    'id' => $a->id,
                    'question_id' => $a->question_id,
                    'action' => $a->action,
                    'before_value' => $a->before_value,
                    'after_value' => $a->after_value,
                    'before_note' => $a->before_note,
                    'after_note' => $a->after_note,
                    'reason' => $a->reason,
                    'adjusted_by_user_id' => $a->adjusted_by_user_id,
                    'adjusted_by_role' => $a->adjusted_by_role,
                    'adjusted_at' => $a->adjusted_at?->toIso8601String(),
                ]),
            ],
        ]);
    }

    /**
     * POST /api/tprm/review/{id}/start
     *
     * Claim assessment untuk di-review oleh user ini. Status submitted →
     * review_in_progress, reviewer_id=user, assigned_reviewer_id juga
     * di-set kalau masih NULL.
     */
    public function start(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! $assessment->canTransitionTo(VendorAssessment::STATUS_REVIEW_IN_PROGRESS)) {
            return response()->json([
                'message' => "Tidak dapat memulai review dari status '{$assessment->status}'.",
            ], 422);
        }

        $assessment->forceFill([
            'status' => VendorAssessment::STATUS_REVIEW_IN_PROGRESS,
            'reviewer_id' => $request->user()->id,
            'assigned_reviewer_id' => $assessment->assigned_reviewer_id ?: $request->user()->id,
        ])->save();

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reviewer',
            'module' => 'tprm.review',
            'action' => 'start_review',
            'record_id' => $assessment->id,
        ]);

        return response()->json([
            'message' => 'Review dimulai.',
            'data' => ['status' => $assessment->status],
        ]);
    }

    /**
     * POST /api/tprm/review/{id}/adjust
     *
     * Reviewer override jawaban untuk satu pertanyaan. Tulis row baru ke
     * vendor_assessment_adjustments (append-only audit) + update assessment.answers.
     *
     * Body: { question_id, new_value, new_note, reason }
     */
    public function adjust(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! in_array($assessment->status, [
            VendorAssessment::STATUS_REVIEW_IN_PROGRESS,
            VendorAssessment::STATUS_PENDING_APPROVAL,
        ], true)) {
            return response()->json([
                'message' => "Adjustment hanya bisa dilakukan saat status review_in_progress atau pending_approval (sekarang: {$assessment->status}).",
            ], 422);
        }

        $data = $request->validate([
            'question_id' => 'required|string',
            'new_value' => 'nullable',
            'new_note' => 'nullable|string|max:2000',
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $answers = is_array($assessment->answers) ? $assessment->answers : [];
        $qId = $data['question_id'];
        $existing = $answers[$qId] ?? [];

        $beforeValue = isset($existing['value']) ? json_encode($existing['value']) : null;
        $beforeNote = $existing['note'] ?? null;

        // Merge update — preserve evidence array
        $newEntry = $existing;
        if (array_key_exists('new_value', $data)) {
            $newEntry['value'] = $data['new_value'];
        }
        if (array_key_exists('new_note', $data) && $data['new_note'] !== null) {
            $newEntry['note'] = $data['new_note'];
        }
        $answers[$qId] = $newEntry;

        $afterValue = isset($newEntry['value']) ? json_encode($newEntry['value']) : null;

        DB::transaction(function () use ($assessment, $answers, $data, $beforeValue, $beforeNote, $afterValue, $newEntry, $request) {
            $assessment->forceFill(['answers' => $answers])->save();

            VendorAssessmentAdjustment::create([
                'id' => (string) Str::uuid(),
                'org_id' => $assessment->org_id,
                'assessment_id' => $assessment->id,
                'question_id' => $data['question_id'],
                'action' => VendorAssessmentAdjustment::ACTION_OVERRIDE,
                'before_value' => $beforeValue,
                'before_note' => $beforeNote,
                'after_value' => $afterValue,
                'after_note' => $newEntry['note'] ?? null,
                'reason' => $data['reason'],
                'adjusted_by_user_id' => $request->user()->id,
                'adjusted_by_role' => $assessment->status === VendorAssessment::STATUS_PENDING_APPROVAL
                    ? VendorAssessmentAdjustment::ROLE_APPROVER
                    : VendorAssessmentAdjustment::ROLE_REVIEWER,
                'adjusted_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Jawaban disesuaikan dan tercatat di audit.',
        ]);
    }

    /**
     * POST /api/tprm/review/{id}/submit-to-approver
     *
     * Reviewer selesai → kirim ke Approver. Status review_in_progress →
     * pending_approval, set assigned_approver_id.
     */
    public function submitToApprover(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! $assessment->canTransitionTo(VendorAssessment::STATUS_PENDING_APPROVAL)) {
            return response()->json([
                'message' => "Tidak dapat submit ke approver dari status '{$assessment->status}'.",
            ], 422);
        }

        $data = $request->validate([
            'assigned_approver_id' => 'required|string',
            'reviewer_note' => 'nullable|string|max:2000',
        ]);

        // Recompute score setelah semua adjustment
        try {
            $result = app(ThirdPartyAssessmentScorer::class)->score(
                $assessment->library_id ? null : ($assessment->category ?? 'pdp_compliance'),
                $assessment->answers ?? [],
                $assessment->library_id
            );
            $scoreUpdate = [
                'score' => (int) round($result['score'] ?? $assessment->score),
                'risk_level' => $result['risk_level'] ?? $assessment->risk_level,
                'recommendations' => $result['recommendations'] ?? $assessment->recommendations,
                'score_breakdown' => $result['breakdown'] ?? $assessment->score_breakdown,
            ];
        } catch (\Throwable $e) {
            // Scoring opsional — kalau gagal, lanjutkan tanpa recompute
            \Log::warning('TPRM rescore at submit-to-approver failed: '.$e->getMessage());
            $scoreUpdate = [];
        }

        $assessment->forceFill(array_merge($scoreUpdate, [
            'status' => VendorAssessment::STATUS_PENDING_APPROVAL,
            'assigned_approver_id' => $data['assigned_approver_id'],
            'reviewer_actioned_at' => now(),
            'reviewer_note' => $data['reviewer_note'] ?? $assessment->reviewer_note,
        ]))->save();

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reviewer',
            'module' => 'tprm.review',
            'action' => 'submit_to_approver',
            'record_id' => $assessment->id,
        ]);

        return response()->json([
            'message' => 'Review selesai, dikirim ke approver.',
            'data' => ['status' => $assessment->status],
        ]);
    }

    /**
     * POST /api/tprm/review/{id}/reject-to-vendor
     *
     * Reviewer kembalikan ke vendor untuk diisi ulang (misal jawaban tidak
     * lengkap / bukti kurang). Status → sent, vendor dapat buka link lagi
     * (kalau token belum expired). Catat alasan untuk transparansi vendor.
     */
    public function rejectToVendor(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! in_array($assessment->status, [
            VendorAssessment::STATUS_REVIEW_IN_PROGRESS,
            VendorAssessment::STATUS_SUBMITTED,
        ], true)) {
            return response()->json([
                'message' => "Hanya bisa dikembalikan saat review_in_progress atau submitted (sekarang: {$assessment->status}).",
            ], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $assessment->forceFill([
            'status' => VendorAssessment::STATUS_SENT,
            'token_consumed_at' => null,  // re-open token supaya vendor bisa buka lagi
            'reviewer_note' => "Dikembalikan ke vendor: ".$data['reason'],
            'reviewer_actioned_at' => now(),
            'reviewer_id' => $request->user()->id,
        ])->save();

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reviewer',
            'module' => 'tprm.review',
            'action' => 'reject_to_vendor',
            'record_id' => $assessment->id,
            'changes' => ['reason' => $data['reason']],
        ]);

        return response()->json([
            'message' => 'Assessment dikembalikan ke pihak ketiga untuk diisi ulang.',
        ]);
    }

    private function findInOrg(string $id, ?string $orgId): VendorAssessment
    {
        if (! $orgId) {
            abort(403, 'Org context required.');
        }
        return VendorAssessment::query()
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->firstOrFail();
    }
}
