<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CrossBorderTransfer;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\Vendor;
use App\Services\AssessmentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * TIA — Transfer Impact Assessment.
 *
 * Lifecycle (mirror LIA):
 *   draft → submitted (Maker) → checked (Checker) → approved (Approver)
 *                            ↘                    ↘
 *                             rejected             rejected
 *
 * Sources (any one or combination):
 *   - RoPA           (data flow that triggers cross-border concern)
 *   - CrossBorder    (a registered cross-border transfer)
 *   - Vendor (TPRM)  (the foreign processor / recipient)
 *
 * Computed: `overall_risk_score` derived from 6 risk + 2 security metrics
 * via TiaAssessment::computeOverallRisk(). Persisted at submit time so
 * downstream queries don't recompute.
 */
class TiaController extends Controller
{
    /**
     * Auto-fill snapshot fields drawn from RoPA. Smaller than LIA's set
     * because TIA's transfer focus differs from LIA's purpose focus.
     */
    public const RoPA_AUTOFILL_FIELDS = [
        'processing_activity',
        'entity',
        'division',
        'work_unit',
        'description',
        'kategori_pemrosesan',
        'data_subjects',
        'data_categories',
        'recipients',
        'security_measures',
        'retention_period',
    ];

    public function index(Request $request)
    {
        $query = TiaAssessment::query();
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        }
        $this->applyFilters($query, $request);
        $records = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 25));

        return response()->json(['data' => $records]);
    }

    public function show(string $id)
    {
        $record = TiaAssessment::query()
            ->with([
                'crossBorder:id,destination_country,activity_name,sender_organization,recipient_organization',
                'ropa:id,custom_number,registration_number,processing_activity,division,risk_level',
                'vendor:id,vendor_name,country,risk_level,is_data_processor',
                'maker:id,name,email', 'checker:id,name,email', 'approver:id,name,email',
            ])
            ->withTrashed()
            ->findOrFail($id);

        return response()->json(['data' => $this->presentRecord($record)]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        $data['org_id'] = $request->user()->org_id;
        $data['created_by'] = $request->user()->id;
        $data['maker_id'] = $request->user()->id;
        $data['status'] = TiaAssessment::STATUS_DRAFT;

        $record = TiaAssessment::create($data);
        AuditLog::log('tia', $record->id, 'created', ['tia_code' => $record->tia_code], 'manual');

        return response()->json(['message' => 'TIA draft created.', 'data' => $record], 201);
    }

    /**
     * Quick-create from a RoPA. Snapshots transfer-relevant fields and
     * suggests a tia_code based on the RoPA's division + activity.
     */
    public function fromRopa(Request $request, string $ropaId)
    {
        $ropa = Ropa::query()->findOrFail($ropaId);
        $orgId = $request->user()->org_id;
        if ($ropa->org_id !== $orgId) {
            abort(403, 'RoPA belongs to another org.');
        }

        $code = $this->suggestCode($orgId, $ropa->division ?? 'GEN', $ropa->processing_activity ?? 'ACT');

        $snapshot = [];
        foreach (self::RoPA_AUTOFILL_FIELDS as $f) {
            if (isset($ropa->$f)) {
                $snapshot[$f] = $ropa->$f;
            }
        }

        $record = TiaAssessment::create([
            'org_id' => $orgId,
            'tia_code' => $code,
            'title' => "TIA — {$ropa->processing_activity}",
            'linked_ropa_id' => $ropa->id,
            'maker_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'status' => TiaAssessment::STATUS_DRAFT,
            'wizard_data' => [
                'source' => 'ropa',
                'ropa_snapshot' => $snapshot,
                'ropa_id' => $ropa->id,
                'snapshot_taken_at' => now()->toIso8601String(),
            ],
        ]);

        AuditLog::log('tia', $record->id, 'created_from_ropa', [
            'tia_code' => $record->tia_code, 'ropa_id' => $ropa->id,
        ], 'manual');

        return response()->json([
            'message' => "TIA draft '{$record->tia_code}' created from RoPA.",
            'data' => $record,
        ], 201);
    }

    /**
     * Quick-create from a CrossBorderTransfer. Pulls destination, transfer
     * profile, and security flags from the inventory so the operator only
     * needs to verify and complete supplementary fields. Pre-fills the 3
     * regulatory risk metrics from country adequacy tier so a transfer to
     * Singapore doesn't start at the same risk score as a transfer to China.
     */
    public function fromCrossBorder(Request $request, string $cbtId)
    {
        $cbt = CrossBorderTransfer::query()->with('ropa:id,registration_number,division,processing_activity')->findOrFail($cbtId);
        $orgId = $request->user()->org_id;
        if ($cbt->org_id !== $orgId) {
            abort(403, 'Cross-border transfer belongs to another org.');
        }

        $unit = $cbt->ropa?->division ?? 'CBDT';
        $activity = $cbt->ropa?->processing_activity ?? $cbt->destination_country ?? 'TRANSFER';
        $code = $this->suggestCode($orgId, $unit, $activity);

        // Single source of truth for the CBDT→TIA prefill (risk metrics,
        // security scores, snapshot). Same helper is reused by the auto-
        // trigger service so explicit + auto paths produce identical drafts.
        $prefill = TiaAssessment::buildPrefillFromCrossBorder($cbt);

        $record = TiaAssessment::create(array_merge($prefill, [
            'tia_code' => $code,
            'title' => "TIA — Transfer ke {$cbt->destination_country}",
            'maker_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'status' => TiaAssessment::STATUS_DRAFT,
            'wizard_data' => array_merge($prefill['wizard_data'], ['source' => 'cross_border']),
        ]));

        AuditLog::log('tia', $record->id, 'created_from_cross_border', [
            'tia_code' => $record->tia_code,
            'cross_border_id' => $cbt->id,
            'adequacy_tier' => $prefill['wizard_data']['adequacy_tier'] ?? null,
        ], 'manual');

        return response()->json([
            'message' => "TIA draft '{$record->tia_code}' created from cross-border transfer.",
            'data' => $record,
        ], 201);
    }

    /**
     * Quick-create from a Vendor (TPRM). Useful when vendor risk
     * assessment flags the vendor as a cross-border processor.
     */
    public function fromVendor(Request $request, string $vendorId)
    {
        $vendor = Vendor::query()->findOrFail($vendorId);
        $orgId = $request->user()->org_id;
        if ($vendor->org_id !== $orgId) {
            abort(403, 'Vendor belongs to another org.');
        }

        $unit = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $vendor->vendor_name ?? 'VND'), 0, 4));
        $code = $this->suggestCode($orgId, $unit, 'TIA');

        $record = TiaAssessment::create([
            'org_id' => $orgId,
            'tia_code' => $code,
            'title' => "TIA — Vendor: {$vendor->vendor_name}",
            'linked_vendor_id' => $vendor->id,
            'destination_country' => $vendor->country ?? null,
            'maker_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'status' => TiaAssessment::STATUS_DRAFT,
            'wizard_data' => [
                'source' => 'vendor',
                'vendor_id' => $vendor->id,
                'vendor_snapshot' => [
                    'vendor_name' => $vendor->vendor_name,
                    'country' => $vendor->country ?? null,
                    'is_data_processor' => $vendor->is_data_processor ?? null,
                    'risk_level' => $vendor->risk_level ?? null,
                ],
                'snapshot_taken_at' => now()->toIso8601String(),
            ],
        ]);

        AuditLog::log('tia', $record->id, 'created_from_vendor', [
            'tia_code' => $record->tia_code, 'vendor_id' => $vendor->id,
        ], 'manual');

        return response()->json([
            'message' => "TIA draft '{$record->tia_code}' created from vendor.",
            'data' => $record,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $record = TiaAssessment::query()->findOrFail($id);
        if (! $record->isEditableBy($request->user())) {
            return response()->json([
                'message' => 'TIA is locked (status='.$record->status.'). Use the reject flow or root unlock to edit.',
            ], 423);
        }

        $data = $this->validatePayload($request, $id);

        // Auto-recompute overall_risk_score whenever any metric changes
        $record->fill($data);
        $record->overall_risk_score = $record->computeOverallRisk();
        $record->save();

        AuditLog::log('tia', $record->id, 'updated', [], 'manual');

        return response()->json(['message' => 'Updated.', 'data' => $record->fresh()]);
    }

    public function submit(Request $request, string $id)
    {
        $record = TiaAssessment::query()->findOrFail($id);
        if ($record->is_locked || $record->status !== TiaAssessment::STATUS_DRAFT) {
            return response()->json(['message' => "Cannot submit from state '{$record->status}'."], 409);
        }
        if (! $request->boolean('confirm')) {
            return response()->json(['message' => 'Submission requires confirmation. Set confirm=true.'], 400);
        }

        $issues = $this->validateForSubmission($record);
        if (! empty($issues) && ! $request->boolean('force')) {
            return response()->json([
                'message' => 'TIA has missing required fields. Use force=true to submit anyway.',
                'issues' => $issues,
            ], 422);
        }

        DB::transaction(function () use ($record, $request) {
            $record->status = TiaAssessment::STATUS_SUBMITTED;
            $record->is_locked = true;
            $record->maker_id = $record->maker_id ?? $request->user()->id;
            $record->submitted_at = now();
            // Final compute pinned at submission so downstream queries are stable
            $record->overall_risk_score = $record->computeOverallRisk();
            $record->save();
        });

        AuditLog::log('tia', $record->id, 'submitted', [
            'tia_code' => $record->tia_code,
            'overall_risk_score' => $record->overall_risk_score,
            'risk_level' => $record->riskLevel(),
        ], 'manual');

        try {
            \App\Services\NotificationService::dispatch(
                kind: 'warning', severity: 'medium', module: 'tia',
                type: 'tia.submitted', recipient: 'role:dpo,admin', orgId: $record->org_id,
                title: "TIA menunggu review: {$record->tia_code}",
                body: 'Skor risiko '.round((float) $record->overall_risk_score, 1).' ('.$record->riskLevel().') — perlu Checker/Approver.',
                actionUrl: "/tia/{$record->id}", metadata: ['record_id' => $record->id],
            );
        } catch (\Throwable $e) { \Log::warning('tia.submitted notif failed: '.$e->getMessage()); }

        return response()->json([
            'message' => 'Submitted to Checker / Approver. Read-only now.',
            'data' => $record->fresh(),
        ]);
    }

    public function check(Request $request, string $id)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['pass', 'reject'])],
            'notes' => 'nullable|string|max:2000',
        ]);

        $record = TiaAssessment::query()->findOrFail($id);
        if (! in_array($record->status, [TiaAssessment::STATUS_SUBMITTED], true)) {
            return response()->json(['message' => "Cannot check from state '{$record->status}'."], 409);
        }

        DB::transaction(function () use ($record, $request, $data) {
            $record->checker_id = $request->user()->id;
            $record->checked_at = now();

            if ($data['action'] === 'pass') {
                $record->status = TiaAssessment::STATUS_CHECKED;
            } else {
                $record->status = TiaAssessment::STATUS_DRAFT;
                $record->is_locked = false;
                $record->rejected_at = now();
                $record->rejection_reason = $data['notes'] ?? 'Rejected by checker.';
            }
            $record->save();
        });

        AuditLog::log('tia', $record->id, 'checked_'.$data['action'], [
            'tia_code' => $record->tia_code,
            'notes' => $data['notes'] ?? null,
        ], 'manual');

        return response()->json([
            'message' => $data['action'] === 'pass' ? 'Forwarded to Approver.' : 'Rejected to Maker.',
            'data' => $record->fresh(),
        ]);
    }

    public function approve(Request $request, string $id)
    {
        $data = $request->validate([
            'conclusion_verdict' => ['required', Rule::in([
                TiaAssessment::VERDICT_APPROVED,
                TiaAssessment::VERDICT_CONDITIONAL,
                TiaAssessment::VERDICT_REJECTED,
            ])],
            'conclusion_notes' => 'nullable|string|max:5000',
        ]);

        $record = TiaAssessment::query()->findOrFail($id);
        if (! in_array($record->status, [TiaAssessment::STATUS_SUBMITTED, TiaAssessment::STATUS_CHECKED], true)) {
            return response()->json(['message' => "Cannot approve from state '{$record->status}'."], 409);
        }

        DB::transaction(function () use ($record, $request, $data) {
            $record->fill($data);
            $record->approver_id = $request->user()->id;
            $record->approved_at = now();
            $record->status = TiaAssessment::STATUS_APPROVED;
            $record->save();
        });

        AuditLog::log('tia', $record->id, 'approved', [
            'tia_code' => $record->tia_code,
            'verdict' => $record->conclusion_verdict,
            'overall_risk_score' => $record->overall_risk_score,
        ], 'manual');

        try {
            \App\Services\NotificationService::dispatch(
                kind: 'info', severity: 'medium', module: 'tia',
                type: 'tia.approved', recipient: 'role:dpo,admin', orgId: $record->org_id,
                title: "TIA disetujui ({$record->conclusion_verdict}): {$record->tia_code}",
                body: 'Verdict: '.$record->conclusion_verdict.' · skor risiko '.round((float) $record->overall_risk_score, 1).'.',
                actionUrl: "/tia/{$record->id}", metadata: ['record_id' => $record->id],
            );
        } catch (\Throwable $e) { \Log::warning('tia.approved notif failed: '.$e->getMessage()); }

        return response()->json([
            'message' => 'TIA approved with verdict: '.$record->conclusion_verdict,
            'data' => $record->fresh(),
        ]);
    }

    public function reject(Request $request, string $id)
    {
        $data = $request->validate([
            'rejection_reason' => 'required|string|max:5000',
        ]);

        $record = TiaAssessment::query()->findOrFail($id);
        if (! in_array($record->status, [TiaAssessment::STATUS_SUBMITTED, TiaAssessment::STATUS_CHECKED], true)) {
            return response()->json(['message' => "Cannot reject from state '{$record->status}'."], 409);
        }

        DB::transaction(function () use ($record, $request, $data) {
            $record->approver_id = $request->user()->id;
            $record->rejected_at = now();
            $record->rejection_reason = $data['rejection_reason'];
            $record->status = TiaAssessment::STATUS_DRAFT;
            $record->is_locked = false;
            $record->save();
        });

        AuditLog::log('tia', $record->id, 'rejected', [
            'tia_code' => $record->tia_code,
            'reason' => $data['rejection_reason'],
        ], 'manual');

        return response()->json(['message' => 'Rejected to Maker.', 'data' => $record->fresh()]);
    }

    public function unlock(Request $request, string $id)
    {
        if ($request->user()->role !== 'root') {
            return response()->json(['message' => 'Only root can unlock submitted TIA records.'], 403);
        }

        $record = TiaAssessment::query()->findOrFail($id);
        if (! $record->is_locked) {
            return response()->json(['message' => 'TIA is not locked.'], 200);
        }

        DB::transaction(function () use ($record, $request) {
            $record->is_locked = false;
            $record->unlocked_by = $request->user()->id;
            $record->unlocked_at = now();
            $record->status = TiaAssessment::STATUS_DRAFT;
            $record->save();
        });

        AuditLog::log('tia', $record->id, 'unlocked_emergency', [
            'tia_code' => $record->tia_code,
            'unlocked_by' => $request->user()->id,
        ], 'manual');

        return response()->json(['message' => 'Unlocked. Maker can now edit.', 'data' => $record->fresh()]);
    }

    public function destroy(string $id)
    {
        $record = TiaAssessment::query()->findOrFail($id);
        $record->delete();
        AuditLog::log('tia', $record->id, 'soft_deleted', [], 'manual');

        return response()->json(['message' => 'Moved to trash.']);
    }

    public function restore(string $id)
    {
        $record = TiaAssessment::onlyTrashed()->findOrFail($id);
        $record->restore();
        AuditLog::log('tia', $record->id, 'restored', [], 'manual');

        return response()->json(['message' => 'Restored.']);
    }

    public function forceDelete(string $id)
    {
        $record = TiaAssessment::withTrashed()->findOrFail($id);
        $record->forceDelete();
        AuditLog::log('tia', $id, 'hard_deleted', [], 'manual');

        return response()->json(['message' => 'Permanently deleted.']);
    }

    /**
     * Stream the TIA as a branded PDF for board/regulator review.
     */
    public function exportPdf(Request $request, AssessmentPdfService $pdf, string $id)
    {
        $record = TiaAssessment::query()->findOrFail($id);
        $filename = "TIA_{$record->tia_code}.pdf";

        AuditLog::log('tia', $record->id, 'pdf_exported', [
            'filename' => $filename,
            'status' => $record->status,
        ], 'manual');

        return $pdf->tia($record, $request->user())->download($filename);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function applyFilters($query, Request $request): void
    {
        if ($s = $request->get('status')) {
            $query->where('status', $s);
        }
        if ($v = $request->get('verdict')) {
            $query->where('conclusion_verdict', $v);
        }
        if ($c = $request->get('country')) {
            $query->where('destination_country', $c);
        }
        if ($r = $request->get('risk_level')) {
            $query->where(function ($q) use ($r) {
                if ($r === 'high') {
                    $q->where('overall_risk_score', '>=', 7);
                } elseif ($r === 'medium') {
                    $q->whereBetween('overall_risk_score', [4, 6.99]);
                } elseif ($r === 'low') {
                    $q->where('overall_risk_score', '<', 4);
                }
            });
        }
        if ($s = $request->get('q')) {
            $query->where(fn ($q) => $q->where('tia_code', 'like', "%{$s}%")
                ->orWhere('title', 'like', "%{$s}%")
                ->orWhere('destination_country', 'like', "%{$s}%"));
        }
    }

    private function validatePayload(Request $request, ?string $id = null): array
    {
        return $request->validate([
            'tia_code' => ['nullable', 'string', 'max:64',
                Rule::unique('tia_assessments', 'tia_code')
                    ->ignore($id)
                    ->where('org_id', $request->user()->org_id)
                    ->whereNull('deleted_at')],
            'title' => 'sometimes|required|string|max:255',
            'linked_ropa_id' => 'nullable|uuid|exists:ropas,id',
            'linked_cross_border_id' => 'nullable|uuid|exists:cross_border_transfers,id',
            'linked_vendor_id' => 'nullable|uuid|exists:vendors,id',

            'transfer_volume' => 'nullable|in:low,medium,high',
            'transfer_frequency' => 'nullable|in:one_time,periodic,continuous',
            'transfer_basis' => 'nullable|in:contract,consent,bcr,other',
            'transfer_basis_other' => 'nullable|string|max:255',

            'destination_country' => 'nullable|string|max:64',
            'destination_has_pdp_law' => 'nullable|boolean',
            'destination_has_pdp_authority' => 'nullable|boolean',
            'recipient_maturity_score' => 'nullable|integer|min:1|max:10',
            'sender_maturity_score' => 'nullable|integer|min:1|max:10',

            // 6 risk metrics
            'risk_regulation_mismatch' => 'nullable|integer|min:1|max:10',
            'risk_contractual_breach' => 'nullable|integer|min:1|max:10',
            'risk_admin_sanctions' => 'nullable|integer|min:1|max:10',
            'risk_data_leak' => 'nullable|integer|min:1|max:10',
            'risk_data_integrity' => 'nullable|integer|min:1|max:10',
            'risk_sovereign_access' => 'nullable|integer|min:1|max:10',

            // 2 security metrics
            'security_protocol_score' => 'nullable|integer|min:1|max:10',
            'security_encryption_score' => 'nullable|integer|min:1|max:10',

            'supplementary_doc_ids' => 'nullable|array',
            'supplementary_doc_ids.*' => 'uuid',

            'transfer_details' => 'nullable|array',
            'legal_framework' => 'nullable|array',
            'risk_assessment' => 'nullable|array',
            'supplementary_measures' => 'nullable|array',
            'wizard_data' => 'nullable|array',
        ]);
    }

    private function validateForSubmission(TiaAssessment $r): array
    {
        $issues = [];
        if (empty($r->tia_code)) {
            $issues[] = 'tia_code is required';
        }
        if (empty($r->destination_country)) {
            $issues[] = 'destination_country is required';
        }
        if (empty($r->transfer_basis)) {
            $issues[] = 'transfer_basis is required';
        }
        foreach (TiaAssessment::RISK_METRIC_KEYS as $k) {
            if ($r->$k === null) {
                $issues[] = "Risk metric '{$k}' must be scored 1-10";
            }
        }

        return $issues;
    }

    private function suggestCode(string $orgId, string $unit, string $activity): string
    {
        $unit = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $unit), 0, 4)) ?: 'GEN';
        $activity = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $activity), 0, 4)) ?: 'ACT';
        $count = TiaAssessment::query()
            ->where('org_id', $orgId)
            ->where('tia_code', 'like', "TIA-{$unit}-{$activity}-%")
            ->count();

        return sprintf('TIA-%s-%s-%02d', $unit, $activity, $count + 1);
    }

    private function presentRecord(TiaAssessment $r): array
    {
        $arr = $r->toArray();
        $arr['risk_level'] = $r->riskLevel();
        $arr['is_editable'] = $r->isEditableBy(request()->user());

        return $arr;
    }
}
