<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomLiaQuestion;
use App\Models\LiaAssessment;
use App\Models\LiaQuestionOverride;
use App\Models\Ropa;
use App\Services\AssessmentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * LIA — Legitimate Interest Assessment.
 *
 * Lifecycle:
 *   draft → submitted (Maker) → checked (Checker) → approved (Approver)
 *                           ↘                    ↘
 *                            rejected             rejected (Approver)
 *
 * Lock: is_locked flips to true on submit. Editing requires either
 * - Maker re-opens via reject path (Checker/Approver can reject back), or
 * - Root performs emergency unlock (audit-logged).
 *
 * RoPA auto-fill: 13 RoPA fields surfaced read-only at Step 1 of the wizard.
 * The frontend pulls them via the `from-ropa/{ropaId}` shortcut which
 * pre-builds a draft with `wizard_data.ropa_snapshot` populated.
 *
 * See backend/docs/LIA_TIA_MATURITY_PLAN.md for the full design and
 * frontend/docs/LIA_TIA_MATURITY_UI.md for the wizard flow.
 */
class LiaController extends Controller
{
    /**
     * 13 fields auto-filled from the linked RoPA, per PDF spec.
     * Snapshot stored in wizard_data so subsequent RoPA edits don't
     * silently change the frozen LIA.
     */
    public const RoPA_AUTOFILL_FIELDS = [
        'processing_activity',     // Nama Aktivitas
        'entity',
        'division',                // Unit Kerja
        'work_unit',
        'description',             // Tujuan Pemrosesan
        'kategori_pemrosesan',     // Peran (Controller/Processor/Joint)
        'data_subjects',           // Subjek Data
        'data_categories',         // Jenis Data
        'recipients',              // Pihak Lain
        'security_measures',
        'retention_period',
        'risk_level',
        'legal_basis',
    ];

    public function index(Request $request)
    {
        $query = LiaAssessment::query();
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }
        $this->applyFilters($query, $request);
        $records = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 25));

        return response()->json(['data' => $records]);
    }

    public function show(string $id)
    {
        $record = LiaAssessment::query()
            ->with(['ropa:id,custom_number,registration_number,processing_activity,division,risk_level',
                'dpia:id,custom_number,registration_number,description',
                'maker:id,name,email', 'checker:id,name,email', 'approver:id,name,email'])
            ->withTrashed()
            ->findOrFail($id);

        return response()->json(['data' => $this->presentRecord($record)]);
    }

    /**
     * Generic create — empty draft. Most callers should use fromRopa instead.
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data['org_id'] = $request->user()->org_id;
        $data['created_by'] = $request->user()->id;
        $data['maker_id'] = $request->user()->id;
        $data['status'] = LiaAssessment::STATUS_DRAFT;

        $record = LiaAssessment::create($data);
        AuditLog::log('lia', $record->id, 'created', ['lia_code' => $record->lia_code], 'manual');

        return response()->json(['message' => 'LIA draft created.', 'data' => $record], 201);
    }

    /**
     * Quick-create from a RoPA — auto-fill 13 fields snapshot. The
     * frontend "Create LIA" button on the RoPA detail page hits this.
     */
    public function fromRopa(Request $request, string $ropaId)
    {
        $ropa = Ropa::query()->findOrFail($ropaId);
        $orgId = $request->user()->org_id;
        if ($ropa->org_id !== $orgId) {
            abort(403, 'RoPA belongs to another org.');
        }

        // Auto-suggest LIA code: LIA-[UNIT]-[ACTIVITY]-[NEXT]
        $unit = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $ropa->division ?? $ropa->work_unit ?? 'GEN'), 0, 4));
        $activity = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $ropa->processing_activity ?? 'ACT'), 0, 4));
        $existingCount = LiaAssessment::query()
            ->where('org_id', $orgId)
            ->where('lia_code', 'like', "LIA-{$unit}-{$activity}-%")
            ->count();
        $codeSuggestion = sprintf('LIA-%s-%s-%02d', $unit, $activity, $existingCount + 1);

        $snapshot = [];
        foreach (self::RoPA_AUTOFILL_FIELDS as $f) {
            if (isset($ropa->$f)) {
                $snapshot[$f] = $ropa->$f;
            }
        }
        // Resolve people from RoPA (DPO from org, maker = current user, checker = supervisor lookup)
        $org = $ropa->organization;

        $record = LiaAssessment::create([
            'org_id' => $orgId,
            'lia_code' => $codeSuggestion,
            'title' => "LIA — {$ropa->processing_activity}",
            'processing_activity' => $ropa->processing_activity,
            'linked_ropa_id' => $ropa->id,
            'maker_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'status' => LiaAssessment::STATUS_DRAFT,
            'wizard_data' => [
                'ropa_snapshot' => $snapshot,
                'ropa_id' => $ropa->id,
                'snapshot_taken_at' => now()->toIso8601String(),
            ],
        ]);

        AuditLog::log('lia', $record->id, 'created_from_ropa', [
            'lia_code' => $record->lia_code,
            'ropa_id' => $ropa->id,
        ], 'manual');

        return response()->json([
            'message' => "LIA draft '{$record->lia_code}' created from RoPA.",
            'data' => $record,
        ], 201);
    }

    /**
     * Update payload. Locked records: only root can edit (and must call
     * unlock first; this endpoint refuses).
     */
    public function update(Request $request, string $id)
    {
        $record = LiaAssessment::query()->findOrFail($id);
        if (! $record->isEditableBy($request->user())) {
            return response()->json([
                'message' => 'LIA is locked (status='.$record->status.'). Use the reject flow or root unlock to edit.',
            ], 423);
        }

        $data = $this->validatePayload($request, $id);
        $record->update($data);
        AuditLog::log('lia', $record->id, 'updated', [], 'manual');

        return response()->json(['message' => 'Updated.', 'data' => $record->fresh()]);
    }

    /**
     * Maker submits — locks record, status → submitted. The PDF spec
     * requires a confirmation pop-up on the frontend before this hits;
     * the backend still requires `confirm=true` query param as a safety
     * net against accidental submission.
     */
    public function submit(Request $request, string $id)
    {
        $record = LiaAssessment::query()->findOrFail($id);

        if ($record->is_locked || $record->status !== LiaAssessment::STATUS_DRAFT) {
            return response()->json([
                'message' => "Cannot submit from state '{$record->status}'. Only draft records can be submitted.",
            ], 409);
        }

        if (! $request->boolean('confirm')) {
            return response()->json([
                'message' => 'Submission requires confirmation. Set confirm=true.',
            ], 400);
        }

        // Sanity check the wizard payload — surface obvious gaps
        $issues = $this->validateForSubmission($record);
        if (! empty($issues) && ! $request->boolean('force')) {
            return response()->json([
                'message' => 'LIA has missing required fields. Use force=true to submit anyway.',
                'issues' => $issues,
            ], 422);
        }

        DB::transaction(function () use ($record, $request) {
            $record->status = LiaAssessment::STATUS_SUBMITTED;
            $record->is_locked = true;
            $record->maker_id = $record->maker_id ?? $request->user()->id;
            $record->submitted_at = now();
            $record->save();
        });

        AuditLog::log('lia', $record->id, 'submitted', [
            'lia_code' => $record->lia_code,
            'maker_id' => $record->maker_id,
        ], 'manual');

        try {
            \App\Services\NotificationService::dispatch(
                kind: 'warning', severity: 'medium', module: 'lia',
                type: 'lia.submitted', recipient: 'role:dpo,admin', orgId: $record->org_id,
                title: "LIA menunggu review: {$record->lia_code}",
                body: ($record->title ?? 'Legitimate Interest Assessment').' — perlu Checker/Approver.',
                actionUrl: "/lia/{$record->id}", metadata: ['record_id' => $record->id],
            );
        } catch (\Throwable $e) { \Log::warning('lia.submitted notif failed: '.$e->getMessage()); }

        return response()->json([
            'message' => 'Submitted to Checker / Approver. The record is now read-only.',
            'data' => $record->fresh(),
        ]);
    }

    /**
     * Checker reviews + adds comments. Optional step — Approver can
     * proceed even without Checker pass-through. If checker rejects,
     * unlock + send back to Maker (status=draft, keep maker_id).
     */
    public function check(Request $request, string $id)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['pass', 'reject'])],
            'notes' => 'nullable|string|max:2000',
        ]);

        $record = LiaAssessment::query()->findOrFail($id);
        if (! in_array($record->status, [LiaAssessment::STATUS_SUBMITTED], true)) {
            return response()->json(['message' => "Cannot check from state '{$record->status}'."], 409);
        }

        DB::transaction(function () use ($record, $request, $data) {
            $record->checker_id = $request->user()->id;
            $record->checked_at = now();

            if ($data['action'] === 'pass') {
                $record->status = LiaAssessment::STATUS_CHECKED;
            } else {
                $record->status = LiaAssessment::STATUS_DRAFT;
                $record->is_locked = false;        // unlock for Maker to edit
                $record->rejected_at = now();
                $record->rejection_reason = $data['notes'] ?? 'Rejected by checker.';
            }
            $record->save();
        });

        AuditLog::log('lia', $record->id, 'checked_'.$data['action'], [
            'lia_code' => $record->lia_code,
            'checker_id' => $record->checker_id,
            'notes' => $data['notes'] ?? null,
        ], 'manual');

        return response()->json([
            'message' => $data['action'] === 'pass'
                ? 'Checked and forwarded to Approver.'
                : 'Rejected back to Maker.',
            'data' => $record->fresh(),
        ]);
    }

    /**
     * Approver (DPO) decides — must fill the 3 conclusion verdicts.
     * Approving with any "tidak_lulus" verdict is allowed; the result
     * just reflects partial pass.
     */
    public function approve(Request $request, string $id)
    {
        $data = $request->validate([
            'conclusion_purpose' => ['required', Rule::in([LiaAssessment::VERDICT_PASS, LiaAssessment::VERDICT_FAIL])],
            'conclusion_necessity' => ['required', Rule::in([LiaAssessment::VERDICT_PASS, LiaAssessment::VERDICT_FAIL])],
            'conclusion_balancing' => ['required', Rule::in([LiaAssessment::VERDICT_PASS, LiaAssessment::VERDICT_FAIL])],
            'conclusion_notes' => 'nullable|string|max:5000',
        ]);

        $record = LiaAssessment::query()->findOrFail($id);
        if (! in_array($record->status, [LiaAssessment::STATUS_SUBMITTED, LiaAssessment::STATUS_CHECKED], true)) {
            return response()->json(['message' => "Cannot approve from state '{$record->status}'."], 409);
        }

        DB::transaction(function () use ($record, $request, $data) {
            $record->fill($data);
            $record->approver_id = $request->user()->id;
            $record->approved_at = now();
            $record->status = LiaAssessment::STATUS_APPROVED;
            // assessment_result is the legacy text; keep in sync
            $record->assessment_result = $record->overallVerdict();
            $record->save();
        });

        AuditLog::log('lia', $record->id, 'approved', [
            'lia_code' => $record->lia_code,
            'approver_id' => $record->approver_id,
            'overall_verdict' => $record->overallVerdict(),
            'verdicts' => [
                'purpose' => $record->conclusion_purpose,
                'necessity' => $record->conclusion_necessity,
                'balancing' => $record->conclusion_balancing,
            ],
        ], 'manual');

        try {
            \App\Services\NotificationService::dispatch(
                kind: 'info', severity: 'medium', module: 'lia',
                type: 'lia.approved', recipient: 'role:dpo,admin', orgId: $record->org_id,
                title: "LIA disetujui: {$record->lia_code}",
                body: 'Verdict keseluruhan: '.$record->overallVerdict().'.',
                actionUrl: "/lia/{$record->id}", metadata: ['record_id' => $record->id],
            );
        } catch (\Throwable $e) { \Log::warning('lia.approved notif failed: '.$e->getMessage()); }

        return response()->json([
            'message' => 'LIA approved. Overall verdict: '.$record->overallVerdict(),
            'data' => $record->fresh(),
        ]);
    }

    /**
     * Approver reject — unlock + send back to Maker as draft.
     */
    public function reject(Request $request, string $id)
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|max:5000',
        ]);

        $record = LiaAssessment::query()->findOrFail($id);
        if (! in_array($record->status, [LiaAssessment::STATUS_SUBMITTED, LiaAssessment::STATUS_CHECKED], true)) {
            return response()->json(['message' => "Cannot reject from state '{$record->status}'."], 409);
        }

        DB::transaction(function () use ($record, $request, $data) {
            $record->approver_id = $request->user()->id;
            $record->rejected_at = now();
            $record->rejection_reason = $data['rejection_reason'];
            $record->status = LiaAssessment::STATUS_DRAFT;
            $record->is_locked = false;
            $record->save();
        });

        AuditLog::log('lia', $record->id, 'rejected', [
            'lia_code' => $record->lia_code,
            'approver_id' => $record->approver_id,
            'reason' => $data['rejection_reason'],
        ], 'manual');

        return response()->json(['message' => 'Rejected back to Maker.', 'data' => $record->fresh()]);
    }

    /**
     * Root-only emergency unlock. Audit-logged. After unlock the LIA
     * goes back to draft and Maker can edit, but the rejection_reason
     * field surfaces who unlocked it for traceability.
     */
    public function unlock(Request $request, string $id)
    {
        if ($request->user()->role !== 'root') {
            return response()->json(['message' => 'Only root can unlock submitted LIA records.'], 403);
        }

        $record = LiaAssessment::query()->findOrFail($id);
        if (! $record->is_locked) {
            return response()->json(['message' => 'LIA is not locked.'], 200);
        }

        DB::transaction(function () use ($record, $request) {
            $record->is_locked = false;
            $record->unlocked_by = $request->user()->id;
            $record->unlocked_at = now();
            $record->status = LiaAssessment::STATUS_DRAFT;
            $record->save();
        });

        AuditLog::log('lia', $record->id, 'unlocked_emergency', [
            'lia_code' => $record->lia_code,
            'unlocked_by' => $request->user()->id,
        ], 'manual');

        return response()->json(['message' => 'Unlocked. Maker can now edit.', 'data' => $record->fresh()]);
    }

    public function destroy(string $id)
    {
        $record = LiaAssessment::query()->findOrFail($id);
        $record->delete();
        AuditLog::log('lia', $record->id, 'soft_deleted', [], 'manual');

        return response()->json(['message' => 'Moved to trash.']);
    }

    public function restore(string $id)
    {
        $record = LiaAssessment::onlyTrashed()->findOrFail($id);
        $record->restore();
        AuditLog::log('lia', $record->id, 'restored', [], 'manual');

        return response()->json(['message' => 'Restored.']);
    }

    public function forceDelete(string $id)
    {
        $record = LiaAssessment::withTrashed()->findOrFail($id);
        $record->forceDelete();
        AuditLog::log('lia', $id, 'hard_deleted', [], 'manual');

        return response()->json(['message' => 'Permanently deleted.']);
    }

    /**
     * Stream the LIA as a branded PDF for board/regulator review.
     * Uses the tenant's active DocumentTemplate for fonts/colors/watermark.
     */
    public function exportPdf(Request $request, AssessmentPdfService $pdf, string $id)
    {
        $record = LiaAssessment::query()->findOrFail($id);
        $filename = "LIA_{$record->lia_code}.pdf";

        AuditLog::log('lia', $record->id, 'pdf_exported', [
            'filename' => $filename,
            'status' => $record->status,
        ], 'manual');

        return $pdf->lia($record, $request->user())->download($filename);
    }

    // =============================================
    // Kelola Pertanyaan — effective set (default + override + custom)
    // =============================================

    /**
     * GET /lia/questions
     * Set pertanyaan panduan EFEKTIF untuk org pemanggil: katalog default
     * (LiaAssessment::DEFAULT_QUESTIONS) + override per-org (default
     * nonaktif di-drop) + pertanyaan custom aktif. include_inactive=1
     * dipakai management UI supaya pertanyaan yang dinonaktifkan tetap
     * tampil (flag is_active=false) dan bisa diaktifkan lagi.
     *
     * TIDAK ada dampak scoring — LIA kualitatif, verdict tetap manual.
     */
    public function questions(Request $request)
    {
        $questions = LiaAssessment::effectiveQuestions(
            $request->user()?->org_id,
            $request->boolean('include_inactive'),
        );

        return response()->json(['data' => array_values($questions)]);
    }

    // =============================================
    // Default Question Overrides (copy-on-write)
    // =============================================
    //
    // Pertanyaan DEFAULT bisa di-EDIT (label/description) dan
    // di-NONAKTIFKAN per org, tapi TIDAK bisa dihapus dan test
    // (purpose|necessity|balancing) TIDAK bisa diubah. Edit mem-fork
    // baris override (lia_question_overrides); reset menghapus override
    // sehingga kembali ke nilai katalog default.

    /**
     * PUT /lia/default-questions/{questionCode}
     * Upsert override untuk org pemanggil. Field yang nilainya sama dengan
     * default disimpan NULL (= tidak di-override) supaya flag is_overridden
     * akurat dan reset semantics bersih.
     */
    public function updateDefaultQuestion(Request $request, string $questionCode)
    {
        $request->validate([
            'label' => 'nullable|string|max:500',
            'description' => 'nullable|string|max:2000',
            'is_active' => 'nullable|boolean',
        ]);

        $default = collect(LiaAssessment::DEFAULT_QUESTIONS)->firstWhere('question_code', $questionCode);
        if (! $default) {
            return response()->json(['message' => 'Pertanyaan default tidak ditemukan.'], 404);
        }

        $orgId = $request->user()->org_id;

        // Hanya simpan field yang BERBEDA dari nilai default — yang sama
        // (atau kosong) disimpan NULL = "pakai default".
        $values = [];
        foreach (['label', 'description'] as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                $val = is_string($val) ? trim($val) : $val;
                $values[$field] = ($val === null || $val === '' || $val === ($default[$field] ?? null)) ? null : $val;
            }
        }
        if ($request->has('is_active')) {
            $values['is_active'] = $request->boolean('is_active');
        }

        // Upsert — restore dulu kalau row pernah soft-deleted (unique
        // constraint org+question_code mencegah duplikat).
        $override = LiaQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('question_code', $questionCode)
            ->first();

        if ($override) {
            if ($override->trashed()) {
                $override->restore();
            }
            $override->fill($values)->save();
        } else {
            $override = LiaQuestionOverride::create(array_merge([
                'org_id' => $orgId,
                'question_code' => $questionCode,
                'is_active' => true,
            ], $values));
        }

        // No-op override (semua field null + masih aktif) → buang row
        // supaya pertanyaan kembali murni default.
        if (! $override->hasEffect()) {
            $override->forceDelete();
        }

        $effective = collect(LiaAssessment::effectiveQuestions($orgId, true))
            ->firstWhere('question_code', $questionCode);

        return response()->json([
            'message' => 'Pertanyaan default diperbarui.',
            'data' => $effective,
        ]);
    }

    /**
     * POST /lia/default-questions/{questionCode}/reset
     * Hapus override org → pertanyaan kembali ke nilai katalog default.
     */
    public function resetDefaultQuestion(Request $request, string $questionCode)
    {
        $orgId = $request->user()->org_id;

        LiaQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('question_code', $questionCode)
            ->forceDelete();

        $effective = collect(LiaAssessment::effectiveQuestions($orgId, true))
            ->firstWhere('question_code', $questionCode);

        return response()->json([
            'message' => 'Pertanyaan dikembalikan ke default.',
            'data' => $effective,
        ]);
    }

    // =============================================
    // Custom Questions CRUD (Kelola Pertanyaan)
    // =============================================

    public function customQuestions(Request $request)
    {
        $questions = CustomLiaQuestion::forOrg($request->user()->org_id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $questions]);
    }

    public function storeCustomQuestion(Request $request)
    {
        $data = $request->validate([
            'test' => ['required', Rule::in(CustomLiaQuestion::ALL_TESTS)],
            'label' => 'required|string|max:500',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $orgId = $request->user()->org_id;

        // question_code auto: CUST-1, CUST-2, ... — withTrashed supaya
        // tidak menabrak unique constraint dengan row yang soft-deleted.
        $lastNum = CustomLiaQuestion::withTrashed()
            ->where('org_id', $orgId)
            ->pluck('question_code')
            ->map(fn ($c) => (int) preg_replace('/\D+/', '', (string) $c))
            ->max() ?? 0;

        $question = CustomLiaQuestion::create([
            'org_id' => $orgId,
            'question_code' => 'CUST-'.($lastNum + 1),
            'test' => $data['test'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order']
                ?? ((int) CustomLiaQuestion::forOrg($orgId)->max('sort_order') + 1),
        ]);

        return response()->json(['message' => 'Pertanyaan custom ditambahkan.', 'data' => $question], 201);
    }

    public function updateCustomQuestion(Request $request, string $id)
    {
        $question = CustomLiaQuestion::forOrg($request->user()->org_id)->findOrFail($id);

        $request->validate([
            'test' => ['sometimes', Rule::in(CustomLiaQuestion::ALL_TESTS)],
            'label' => 'sometimes|string|max:500',
            'description' => 'nullable|string|max:2000',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $question->update($request->only([
            'test', 'label', 'description', 'sort_order', 'is_active',
        ]));

        return response()->json(['message' => 'Pertanyaan custom diperbarui.', 'data' => $question->fresh()]);
    }

    public function destroyCustomQuestion(Request $request, string $id)
    {
        $question = CustomLiaQuestion::forOrg($request->user()->org_id)->findOrFail($id);
        $question->delete();

        return response()->json(['message' => 'Pertanyaan custom dihapus.']);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function applyFilters($query, Request $request): void
    {
        if ($s = $request->get('status')) {
            $query->where('status', $s);
        }
        if ($r = $request->get('result')) {
            $query->where('assessment_result', $r);
        }
        if ($u = $request->get('unit')) {
            $query->whereHas('ropa', fn ($q) => $q->where('division', $u));
        }
        if ($s = $request->get('q')) {
            $query->where(fn ($q) => $q->where('lia_code', 'like', "%{$s}%")
                ->orWhere('title', 'like', "%{$s}%")
                ->orWhere('processing_activity', 'like', "%{$s}%"));
        }
    }

    private function validatePayload(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'lia_code' => ['nullable', 'string', 'max:64',
                Rule::unique('lia_assessments', 'lia_code')
                    ->ignore($id)
                    ->where('org_id', $request->user()->org_id)
                    ->whereNull('deleted_at')],
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'processing_activity' => 'sometimes|required|string|max:255',
            'linked_ropa_id' => 'nullable|uuid|exists:ropas,id',
            'linked_dpia_id' => 'nullable|uuid|exists:dpias,id',
            'legitimate_interest_basis' => 'nullable|in:yes,no',
            'legitimate_interest_reason' => 'nullable|string',
            'purpose_test' => 'nullable|array',
            'necessity_test' => 'nullable|array',
            'balancing_test' => 'nullable|array',
            'balancing_risk_events' => 'nullable|array',
            'subject_loses_control' => 'nullable|in:yes,no',
            'subject_loses_control_reason' => 'nullable|string',
            'wizard_data' => 'nullable|array',
        ]);
    }

    /**
     * Surface obvious incompleteness before allowing submit. Not exhaustive —
     * Approver still has final say. These are just sanity-check gates.
     *
     * Section emptiness is gated on the org's EFFECTIVE question set
     * (Kelola Pertanyaan): a test with no active question — or a
     * deactivated risk register — is never flagged as missing.
     */
    private function validateForSubmission(LiaAssessment $r): array
    {
        $effective = collect(LiaAssessment::effectiveQuestions($r->org_id));

        $issues = [];
        if (empty($r->lia_code)) {
            $issues[] = 'lia_code is required';
        }
        if (empty($r->processing_activity)) {
            $issues[] = 'processing_activity is required';
        }
        if (empty($r->linked_ropa_id)) {
            $issues[] = 'linked_ropa_id is required';
        }
        if (empty($r->legitimate_interest_basis)) {
            $issues[] = 'Section 3 (Dasar Pemrosesan) is empty';
        }
        if ($effective->contains(fn ($q) => $q['test'] === 'purpose') && empty($r->purpose_test)) {
            $issues[] = 'Section 4 (Purpose Test) is empty';
        }
        if ($effective->contains(fn ($q) => $q['test'] === 'necessity') && empty($r->necessity_test)) {
            $issues[] = 'Section 5 (Necessity Test) is empty';
        }
        if ($effective->contains(fn ($q) => $q['question_code'] === 'risk_register') && empty($r->balancing_risk_events)) {
            $issues[] = 'Section 6 (Balancing risk register) is empty';
        }

        return $issues;
    }

    private function presentRecord(LiaAssessment $r): array
    {
        $arr = $r->toArray();
        $arr['overall_verdict'] = $r->overallVerdict();
        $arr['is_editable'] = $r->isEditableBy(request()->user());

        return $arr;
    }
}
