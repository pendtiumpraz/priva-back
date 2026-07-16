<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomLiaQuestion;
use App\Models\LiaAssessment;
use App\Models\LiaQuestionOverride;
use App\Models\Organization;
use App\Models\Ropa;
use App\Services\AiDocumentAnalyzer;
use App\Services\AssessmentPdfService;
use App\Services\CreditService;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

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
        // Skip answer_notes if the column has not been migrated yet in this environment.
        if (array_key_exists('answer_notes', $data) && ! \Illuminate\Support\Facades\Schema::hasColumn('lia_assessments', 'answer_notes')) {
            unset($data['answer_notes']);
        }
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
        // Skip answer_notes if the column has not been migrated yet in this environment.
        if (array_key_exists('answer_notes', $data) && ! \Illuminate\Support\Facades\Schema::hasColumn('lia_assessments', 'answer_notes')) {
            unset($data['answer_notes']);
        }
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

    /**
     * POST /lia/questions/factory-reset
     * Reset TOTAL ke default pabrikan (mirror GapAssessmentController::
     * factoryResetQuestions):
     * - Semua override pertanyaan default org dihapus permanen (force delete,
     *   sama seperti resetDefaultQuestion) → edit di-revert + default yang
     *   dinonaktifkan otomatis aktif lagi.
     * - Semua pertanyaan custom org di-soft-delete (semantik sama dengan
     *   destroyCustomQuestion) → hilang dari list & set efektif.
     */
    public function factoryResetQuestions(Request $request)
    {
        $orgId = $request->user()->org_id;

        $overridesRemoved = LiaQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->forceDelete();

        $customsRemoved = CustomLiaQuestion::forOrg($orgId)->delete();

        AuditLog::log('lia', $orgId, 'questions_factory_reset', [
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
        ]);

        return response()->json([
            'message' => 'Semua pertanyaan dikembalikan ke default pabrikan.',
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
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

    // =============================================
    // Evidence Upload + AI Analysis (parity dgn GAP/Maturity)
    // =============================================
    //
    // "Pertanyaan" di LIA = 11 pertanyaan katalog (effectiveQuestions:
    // default + override + custom CUST-N, grouped per uji purpose|
    // necessity|balancing). Evidence di-key by question_code. LIA TIDAK
    // punya scoring — verdict AI murni advisory; lulus/tidak_lulus tetap
    // manual oleh Approver.

    /**
     * POST /lia/{id}/upload-evidence — mirror
     * MaturityController::uploadEvidence. Multi-file per pertanyaan;
     * attachments[question_code][] = { path, url, name, driver, uploaded_at }.
     *
     * Lock semantics: mirror update() — upload ditolak 423 saat record
     * locked (is_locked=true sejak submit), kecuali root. Draft / hasil
     * reject (in-revision, is_locked=false) boleh upload.
     */
    public function uploadEvidence(Request $request, string $id, TenantStorageService $storage, FileUploadValidator $validator)
    {
        // Kalau body request melebihi `post_max_size` PHP, $_POST + $_FILES
        // dibuang dan request seolah kosong — tanpa pesan jelas. Cek
        // CONTENT_LENGTH manual supaya bisa kasih error "PHP tolak karena
        // upload limit hosting < X MB" alih-alih "field required".
        $contentLength = (int) $request->server('CONTENT_LENGTH');
        $postMax = $this->bytesFromIni((string) ini_get('post_max_size'));
        $uploadMax = $this->bytesFromIni((string) ini_get('upload_max_filesize'));
        if ($postMax > 0 && $contentLength > $postMax) {
            return response()->json([
                'message' => sprintf(
                    'Ukuran upload (%s) melebihi batas server PHP post_max_size (%s). Minta admin naikkan post_max_size & upload_max_filesize di php.ini hosting.',
                    $this->humanBytes($contentLength),
                    ini_get('post_max_size'),
                ),
            ], 413);
        }
        if ($request->hasFile('file') && $uploadMax > 0 && $request->file('file')->getSize() > $uploadMax) {
            return response()->json([
                'message' => sprintf(
                    'Ukuran file melebihi batas server PHP upload_max_filesize (%s).',
                    ini_get('upload_max_filesize'),
                ),
            ], 413);
        }

        $request->validate([
            'question_id' => 'required|string|max:128',
            'file' => 'required|file|max:10240',
        ]);

        $assessment = LiaAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        if (! $assessment->isEditableBy($request->user())) {
            return response()->json([
                'message' => 'LIA is locked (status='.$assessment->status.'). Use the reject flow or root unlock to edit.',
            ], 423);
        }

        // Validasi question_code terhadap set pertanyaan EFEKTIF org
        // (default aktif + override + custom aktif) — evidence hanya bisa
        // di-attach ke pertanyaan yang benar-benar ada untuk org ini.
        $qCode = $request->input('question_id');
        $question = collect(LiaAssessment::effectiveQuestions($assessment->org_id))
            ->firstWhere('question_code', $qCode);
        if (! $question) {
            return response()->json(['message' => 'Pertanyaan tidak ditemukan di set pertanyaan aktif organisasi.'], 422);
        }

        $file = $request->file('file');

        try {
            $validator->validate($file, FileUploadValidator::PRESET_MATURITY_EVIDENCE);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $org = Organization::findOrFail($assessment->org_id);

        try {
            $result = $storage->storePublicAsset(
                $org,
                $file,
                "lia/{$assessment->id}/evidence"
            );
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Gagal menyimpan file ke storage: ' . $e->getMessage(),
            ], 500);
        }

        if (empty($result['path'])) {
            return response()->json([
                'message' => 'Gagal menyimpan file (path kosong). Periksa konfigurasi storage.',
            ], 500);
        }

        $attachments = $assessment->attachments ?? [];

        if (!isset($attachments[$qCode]) || !is_array($attachments[$qCode])) {
            $attachments[$qCode] = [];
        }

        $attachments[$qCode][] = [
            'path' => $result['path'],
            'url' => $result['url'],
            'name' => $file->getClientOriginalName(),
            'driver' => $result['driver'],
            'uploaded_at' => now()->toIso8601String(),
        ];

        $assessment->update(['attachments' => $attachments]);

        AuditLog::log('lia', $assessment->id, 'evidence_uploaded', [
            'question_code' => $qCode,
            'name' => $file->getClientOriginalName(),
        ], 'manual');

        return response()->json([
            'message' => 'Bukti berhasil diunggah',
            'data' => end($attachments[$qCode]),
            'attachments' => $attachments,
        ]);
    }

    /**
     * POST /lia/{id}/analyze-evidence — mirror
     * MaturityController::analyzeEvidence.
     *
     * Body: question_id (= question_code) + attachment_path. Teks pertanyaan
     * untuk analyzer di-compose dari label + description pertanyaan EFEKTIF
     * org (hasil override per-org, bukan default asli):
     *   "Pertanyaan LIA ({uji}): {label}. {description}"
     *
     * Hasil disimpan ke ai_analyses[question_code][] keyed by
     * attachment_path. 1 kredit per analisis (deduct di AiDocumentAnalyzer
     * untuk panggilan sukses non-cache); 402 kalau kredit habis.
     *
     * PENTING: LIA tidak punya scoring — verdict AI murni ADVISORY.
     * Keputusan lulus/tidak_lulus per uji tetap manual oleh Approver.
     */
    public function analyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $request->validate([
            'question_id' => 'required|string|max:128',
            'attachment_path' => 'required|string|max:1024',
        ]);

        $assessment = LiaAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        $qCode = $request->input('question_id');
        $question = collect(LiaAssessment::effectiveQuestions($assessment->org_id))
            ->firstWhere('question_code', $qCode);

        if (! $question) {
            return response()->json(['message' => 'Pertanyaan tidak ditemukan.'], 404);
        }

        // Verify the attachment_path actually belongs to this assessment
        // (per-question attachments map) — defence against tampering.
        $attachments = $assessment->attachments ?? [];
        $questionAttachments = $attachments[$qCode] ?? [];
        $matched = collect($questionAttachments)->first(function ($att) use ($request) {
            $path = is_array($att) ? ($att['path'] ?? null) : $att;
            return $path === $request->attachment_path;
        });

        if (! $matched) {
            return response()->json([
                'message' => 'Lampiran tidak ditemukan pada pertanyaan ini.',
            ], 404);
        }

        $localPath = $this->resolveAttachmentPath($assessment, $request->attachment_path);
        if (! $localPath || ! is_file($localPath)) {
            return response()->json([
                'message' => 'File tidak ditemukan pada penyimpanan.',
            ], 404);
        }

        // Credit gate (skip for superadmin / on-prem).
        $orgId = $request->user()->org_id;
        if ($orgId) {
            CreditService::resetIfNeeded($orgId);
            if (! CreditService::hasCredit($orgId, 'ai_doc_analyze')) {
                $cost = CreditService::getCost('ai_doc_analyze');
                return response()->json([
                    'message' => "Kredit AI Anda habis. Dibutuhkan {$cost} kredit untuk analisis ini. Silakan top up kredit melalui menu Konfigurasi Platform.",
                    'credits_exhausted' => true,
                ], 402);
            }
        }

        $result = $analyzer->analyze(
            documentPath: $localPath,
            question: $this->liaQuestionText($question),
            regulationRef: '',
            orgId: $orgId,
        );

        $newEntry = array_merge($result->toArray(), [
            'analyzed_at' => now()->toIso8601String(),
            'attachment_path' => $request->attachment_path,
        ]);

        // ai_analyses[qCode] = ARRAY (satu entri per attachment) supaya
        // banyak dokumen di 1 pertanyaan bisa punya verdict masing-masing.
        $analyses = $assessment->ai_analyses ?? [];
        $listForQ = $this->normalizeAnalysesForQuestion($analyses[$qCode] ?? null);
        // Replace kalau attachment_path sudah ada di array; else append.
        $found = false;
        foreach ($listForQ as $i => $item) {
            if (($item['attachment_path'] ?? null) === $request->attachment_path) {
                $listForQ[$i] = $newEntry;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $listForQ[] = $newEntry;
        }
        $analyses[$qCode] = $listForQ;

        // Advisory only: LIA tidak punya scoring sama sekali — verdict
        // lulus/tidak_lulus tetap keputusan manual Approver.
        $assessment->update(['ai_analyses' => $analyses]);

        return response()->json($newEntry);
    }

    /**
     * POST /lia/{id}/analyze-evidence-bulk — mirror
     * MaturityController::bulkAnalyzeEvidence.
     *
     * Iterasi setiap (question, attachment). Skip: pertanyaan tanpa
     * attachment, pasangan yang sudah cached di ai_analyses (no
     * re-charge), dan file gambar (OCR belum didukung). Stop early kalau
     * kredit habis di tengah — return stats + flag `credits_exhausted`.
     */
    public function bulkAnalyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $assessment = LiaAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);
        $orgId = $request->user()->org_id;

        // Question map: set pertanyaan EFEKTIF org (default + override +
        // custom aktif) — keyed by question_code, termasuk CUST-x.
        $questionMap = collect(LiaAssessment::effectiveQuestions($orgId))
            ->keyBy('question_code');

        $attachments = $assessment->attachments ?? [];
        $existing = $assessment->ai_analyses ?? [];
        $newAnalyses = $existing;

        $stats = ['analyzed' => 0, 'cached' => 0, 'skipped' => 0, 'failed' => 0];
        $creditsExhausted = false;
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

        foreach ($attachments as $qCode => $files) {
            if (empty($files) || ! is_array($files)) {
                continue;
            }

            $question = $questionMap->get($qCode);
            if (! $question) {
                $stats['skipped'] += is_array($files) ? count($files) : 1;
                continue;
            }

            $prevList = $this->normalizeAnalysesForQuestion($existing[$qCode] ?? null);
            $prevByPath = [];
            foreach ($prevList as $p) {
                if (! empty($p['attachment_path'])) {
                    $prevByPath[$p['attachment_path']] = $p;
                }
            }

            $newListForQ = [];
            foreach ($files as $att) {
                $attachmentPath = is_array($att) ? ($att['path'] ?? null) : $att;
                if (! $attachmentPath) {
                    $stats['skipped']++;
                    continue;
                }

                // Cache hit: skip kalau hasil analysis sudah ada untuk attachment yang sama.
                $prev = $prevByPath[$attachmentPath] ?? null;
                if ($prev && ! empty($prev['status'])) {
                    $newListForQ[] = $prev;
                    $stats['cached']++;
                    continue;
                }

                // Image: skip (OCR not supported)
                $ext = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
                if (in_array($ext, $imageExts, true)) {
                    $newListForQ[] = [
                        'status' => 'unsure',
                        'analysis' => 'Gambar belum didukung untuk analisis AI (perlu OCR engine).',
                        'cited_passages' => [],
                        'confidence' => 0,
                        'analyzed_at' => now()->toIso8601String(),
                        'attachment_path' => $attachmentPath,
                    ];
                    $stats['skipped']++;
                    continue;
                }

                // Credit gate per-item supaya tidak bocor kuota.
                if ($orgId) {
                    CreditService::resetIfNeeded($orgId);
                    if (! CreditService::hasCredit($orgId, 'ai_doc_analyze')) {
                        $creditsExhausted = true;
                        break 2; // break out of inner+outer loops
                    }
                }

                $localPath = $this->resolveAttachmentPath($assessment, $attachmentPath);
                if (! $localPath || ! is_file($localPath)) {
                    $newListForQ[] = [
                        'status' => 'unsure',
                        'analysis' => 'File tidak ditemukan di storage.',
                        'cited_passages' => [],
                        'confidence' => 0,
                        'analyzed_at' => now()->toIso8601String(),
                        'attachment_path' => $attachmentPath,
                    ];
                    $stats['failed']++;
                    continue;
                }

                try {
                    $result = $analyzer->analyze(
                        documentPath: $localPath,
                        question: $this->liaQuestionText($question),
                        regulationRef: '',
                        orgId: $orgId,
                    );
                    $newListForQ[] = array_merge($result->toArray(), [
                        'analyzed_at' => now()->toIso8601String(),
                        'attachment_path' => $attachmentPath,
                    ]);
                    $stats['analyzed']++;
                } catch (\Throwable $e) {
                    $newListForQ[] = [
                        'status' => 'unsure',
                        'analysis' => 'Gagal analisis: '.$e->getMessage(),
                        'cited_passages' => [],
                        'confidence' => 0,
                        'analyzed_at' => now()->toIso8601String(),
                        'attachment_path' => $attachmentPath,
                    ];
                    $stats['failed']++;
                }
            }

            if (! empty($newListForQ)) {
                $newAnalyses[$qCode] = $newListForQ;
            }
        }

        // Advisory only — LIA tidak punya scoring; verdict tetap manual.
        $assessment->update(['ai_analyses' => $newAnalyses]);

        $msg = "Bulk analisis selesai: {$stats['analyzed']} baru, {$stats['cached']} cache, {$stats['skipped']} skip, {$stats['failed']} gagal.";
        if ($creditsExhausted) {
            $msg .= ' Kredit habis di tengah — sebagian belum dianalisis. Top up dan klik analisis ulang untuk lanjut.';
        }

        return response()->json([
            'message' => $msg,
            'stats' => $stats,
            'credits_exhausted' => $creditsExhausted,
            'ai_analyses' => $newAnalyses,
        ]);
    }

    /**
     * Compose teks pertanyaan untuk AiDocumentAnalyzer dari satu pertanyaan
     * effective set: "Pertanyaan LIA ({uji}): {label}. {description}".
     * Label/description sudah hasil override per-org (Kelola Pertanyaan).
     *
     * @param  array<string, mixed>  $question
     */
    private function liaQuestionText(array $question): string
    {
        $testLabels = [
            'purpose' => 'Uji Tujuan',
            'necessity' => 'Uji Kebutuhan',
            'balancing' => 'Uji Keseimbangan',
        ];
        $test = $testLabels[$question['test'] ?? ''] ?? ($question['test'] ?? '');

        $text = 'Pertanyaan LIA (Legitimate Interest Assessment'.($test ? " — {$test}" : '').'): '
            .($question['label'] ?? $question['question_code'] ?? '');
        if (! empty($question['description'])) {
            $text .= '. '.$question['description'];
        }

        return $text;
    }

    /**
     * Normalisasi nilai ai_analyses[qCode] ke array entries.
     * Format lama: object tunggal { status, analysis, attachment_path, ... }
     * Format baru: array [ { ... }, { ... } ]
     */
    private function normalizeAnalysesForQuestion(mixed $value): array
    {
        if (empty($value) || ! is_array($value)) {
            return [];
        }
        // Object tunggal punya field 'status' di root → bungkus jadi array.
        if (isset($value['status'])) {
            return [$value];
        }
        // Sudah berupa list (numeric indexed).
        return array_values($value);
    }

    /** Convert PHP ini shorthand (50M, 8K, 2G) ke byte integer. */
    private function bytesFromIni(string $val): int
    {
        $val = trim($val);
        if ($val === '') return 0;
        $unit = strtolower(substr($val, -1));
        $num = (int) $val;
        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => $num,
        };
    }

    /** Format byte ke MB/KB human-readable. */
    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . 'MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 1) . 'KB';
        return $bytes . 'B';
    }

    /**
     * Resolve attachment relative path ke absolute filesystem path (mirror
     * MaturityController::resolveAttachmentPath). Layer 1: local public/app
     * disk; Layer 2: tenant disk (S3/GCS) → download ke temp file supaya
     * analyzer (PdfParser/PhpWord/PhpSpreadsheet) bisa baca.
     */
    private function resolveAttachmentPath(LiaAssessment $assessment, string $relativePath): ?string
    {
        $rel = ltrim($relativePath, '/');

        $localCandidates = [
            storage_path('app/public/'.$rel),
            storage_path('app/'.$rel),
        ];
        foreach ($localCandidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        try {
            $org = Organization::find($assessment->org_id);
            if (! $org) {
                return null;
            }
            /** @var TenantStorageService $svc */
            $svc = app(TenantStorageService::class);
            $disk = $svc->getDisk($org);
            if (! $disk->exists($rel)) {
                return null;
            }
            $contents = $disk->get($rel);
            if ($contents === null || $contents === '') {
                return null;
            }
            $ext = pathinfo($rel, PATHINFO_EXTENSION) ?: 'bin';
            $tmpDir = sys_get_temp_dir();
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.'lia_evidence_'.substr(hash('sha256', $rel), 0, 16).'.'.$ext;
            if (file_put_contents($tmpPath, $contents) === false) {
                return null;
            }
            return $tmpPath;
        } catch (\Throwable $e) {
            \Log::warning('[LIA resolveAttachmentPath] tenant disk fetch failed', [
                'org_id' => $assessment->org_id,
                'path' => $rel,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
            'answer_notes' => 'nullable|array',
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
