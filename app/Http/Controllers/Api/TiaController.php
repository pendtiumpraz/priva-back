<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CrossBorderTransfer;
use App\Models\CustomTiaMetric;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\TiaAssessment;
use App\Models\TiaMetricOverride;
use App\Models\Vendor;
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
        $data = $this->applyCustomMetricScores($data, $request->user()->org_id);
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
        $data = $this->applyCustomMetricScores($data, $record->org_id, $record);

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

    // =============================================
    // Kelola Metrik — effective set (default + override + custom)
    // =============================================

    /**
     * GET /tia/metrics
     * Set metrik EFEKTIF untuk org pemanggil: katalog default
     * (TiaAssessment::DEFAULT_METRICS) + override per-org (default
     * nonaktif di-drop) + metrik custom aktif. include_inactive=1 dipakai
     * management UI supaya metrik yang dinonaktifkan tetap tampil (flag
     * is_active=false) dan bisa diaktifkan lagi.
     */
    public function metrics(Request $request)
    {
        $metrics = TiaAssessment::effectiveMetrics(
            $request->user()?->org_id,
            $request->boolean('include_inactive'),
        );

        return response()->json(['data' => array_values($metrics)]);
    }

    // =============================================
    // Default Metric Overrides (copy-on-write)
    // =============================================
    //
    // Metrik DEFAULT bisa di-EDIT (label/description/weight) dan
    // di-NONAKTIFKAN per org, tapi TIDAK bisa dihapus dan kind
    // (risk|security) TIDAK bisa diubah. Edit mem-fork baris override
    // (tia_metric_overrides); reset menghapus override sehingga kembali
    // ke nilai katalog default.

    /**
     * PUT /tia/default-metrics/{metricCode}
     * Upsert override untuk org pemanggil. Field yang nilainya sama dengan
     * default disimpan NULL (= tidak di-override) supaya flag is_overridden
     * akurat dan reset semantics bersih. Bobot default = 1.
     */
    public function updateDefaultMetric(Request $request, string $metricCode)
    {
        $request->validate([
            'label' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'weight' => 'nullable|numeric|min:1|max:10',
            'is_active' => 'nullable|boolean',
        ]);

        $default = collect(TiaAssessment::DEFAULT_METRICS)->firstWhere('metric_code', $metricCode);
        if (! $default) {
            return response()->json(['message' => 'Metrik default tidak ditemukan.'], 404);
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
        if ($request->has('weight')) {
            $w = $request->input('weight');
            $values['weight'] = ($w === null || abs((float) $w - 1.0) < 0.001) ? null : (float) $w;
        }
        if ($request->has('is_active')) {
            $values['is_active'] = $request->boolean('is_active');
        }

        // Upsert — restore dulu kalau row pernah soft-deleted (unique
        // constraint org+metric_code mencegah duplikat).
        $override = TiaMetricOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('metric_code', $metricCode)
            ->first();

        if ($override) {
            if ($override->trashed()) {
                $override->restore();
            }
            $override->fill($values)->save();
        } else {
            $override = TiaMetricOverride::create(array_merge([
                'org_id' => $orgId,
                'metric_code' => $metricCode,
                'is_active' => true,
            ], $values));
        }

        // No-op override (semua field null + masih aktif) → buang row
        // supaya metrik kembali murni default.
        if (! $override->hasEffect()) {
            $override->forceDelete();
        }

        $effective = collect(TiaAssessment::effectiveMetrics($orgId, true))
            ->firstWhere('metric_code', $metricCode);

        return response()->json([
            'message' => 'Metrik default diperbarui.',
            'data' => $effective,
        ]);
    }

    /**
     * POST /tia/default-metrics/{metricCode}/reset
     * Hapus override org → metrik kembali ke nilai katalog default.
     */
    public function resetDefaultMetric(Request $request, string $metricCode)
    {
        $orgId = $request->user()->org_id;

        TiaMetricOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('metric_code', $metricCode)
            ->forceDelete();

        $effective = collect(TiaAssessment::effectiveMetrics($orgId, true))
            ->firstWhere('metric_code', $metricCode);

        return response()->json([
            'message' => 'Metrik dikembalikan ke default.',
            'data' => $effective,
        ]);
    }

    // =============================================
    // Custom Metrics CRUD (Kelola Metrik)
    // =============================================

    public function customMetrics(Request $request)
    {
        $metrics = CustomTiaMetric::forOrg($request->user()->org_id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $metrics]);
    }

    public function storeCustomMetric(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(CustomTiaMetric::ALL_KINDS)],
            'label' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'weight' => 'nullable|numeric|min:1|max:10',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $orgId = $request->user()->org_id;

        // metric_code auto: CUST-1, CUST-2, ... — withTrashed supaya
        // tidak menabrak unique constraint dengan row yang soft-deleted.
        $lastNum = CustomTiaMetric::withTrashed()
            ->where('org_id', $orgId)
            ->pluck('metric_code')
            ->map(fn ($c) => (int) preg_replace('/\D+/', '', (string) $c))
            ->max() ?? 0;

        $metric = CustomTiaMetric::create([
            'org_id' => $orgId,
            'metric_code' => 'CUST-'.($lastNum + 1),
            'kind' => $data['kind'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'weight' => $data['weight'] ?? 1,
            'sort_order' => $data['sort_order']
                ?? ((int) CustomTiaMetric::forOrg($orgId)->max('sort_order') + 1),
        ]);

        return response()->json(['message' => 'Metrik custom ditambahkan.', 'data' => $metric], 201);
    }

    public function updateCustomMetric(Request $request, string $id)
    {
        $metric = CustomTiaMetric::forOrg($request->user()->org_id)->findOrFail($id);

        $request->validate([
            'kind' => ['sometimes', Rule::in(CustomTiaMetric::ALL_KINDS)],
            'label' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'weight' => 'nullable|numeric|min:1|max:10',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $payload = $request->only([
            'kind', 'label', 'description', 'weight', 'sort_order', 'is_active',
        ]);
        // Kolom weight NOT NULL (default 1) — null berarti "jangan ubah".
        if (array_key_exists('weight', $payload) && $payload['weight'] === null) {
            unset($payload['weight']);
        }
        $metric->update($payload);

        return response()->json(['message' => 'Metrik custom diperbarui.', 'data' => $metric->fresh()]);
    }

    public function destroyCustomMetric(Request $request, string $id)
    {
        $metric = CustomTiaMetric::forOrg($request->user()->org_id)->findOrFail($id);
        $metric->delete();

        return response()->json(['message' => 'Metrik custom dihapus.']);
    }

    // =============================================
    // Evidence Upload + AI Analysis (parity dgn GAP/Maturity)
    // =============================================
    //
    // "Pertanyaan" di TIA = metrik (effectiveMetrics: default + override +
    // custom CUST-N). Evidence di-key by metric_code. Param request tetap
    // bernama `question_id` supaya kompatibel dengan komponen FE
    // EvidenceUpload / AiAnalysisButton yang dipakai lintas modul.

    /**
     * POST /tia/{id}/upload-evidence — mirror
     * MaturityController::uploadEvidence. Multi-file per metrik;
     * attachments[metric_code][] = { path, url, name, driver, uploaded_at }.
     *
     * Lock semantics: mirror update() — upload ditolak 423 saat record
     * locked (is_locked=true sejak submit; status submitted/checked/
     * approved), kecuali root. Draft / hasil reject (unlock) boleh upload.
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

        $assessment = TiaAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        if (! $assessment->isEditableBy($request->user())) {
            return response()->json([
                'message' => 'TIA is locked (status='.$assessment->status.'). Use the reject flow or root unlock to edit.',
            ], 423);
        }

        // Validasi metric_code terhadap set metrik EFEKTIF org (default
        // aktif + override + custom aktif) — evidence hanya bisa di-attach
        // ke metrik yang benar-benar ada untuk org ini.
        $qCode = $request->input('question_id');
        $metric = collect(TiaAssessment::effectiveMetrics($assessment->org_id))
            ->firstWhere('metric_code', $qCode);
        if (! $metric) {
            return response()->json(['message' => 'Metrik tidak ditemukan di set metrik aktif organisasi.'], 422);
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
                "tia/{$assessment->id}/evidence"
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

        AuditLog::log('tia', $assessment->id, 'evidence_uploaded', [
            'metric_code' => $qCode,
            'name' => $file->getClientOriginalName(),
        ], 'manual');

        return response()->json([
            'message' => 'Bukti berhasil diunggah',
            'data' => end($attachments[$qCode]),
            'attachments' => $attachments,
        ]);
    }

    /**
     * POST /tia/{id}/analyze-evidence — mirror
     * MaturityController::analyzeEvidence.
     *
     * Body: question_id (= metric_code) + attachment_path. Teks "pertanyaan"
     * untuk analyzer di-compose dari label + description metrik EFEKTIF org
     * (hasil override per-org, bukan default asli):
     *   "Metrik TIA: {label}. {description}"
     *
     * Hasil disimpan ke ai_analyses[metric_code][] keyed by attachment_path.
     * 1 kredit per analisis (deduct di AiDocumentAnalyzer untuk panggilan
     * sukses non-cache); 402 kalau kredit habis.
     *
     * PENTING: verdict AI di TIA ADVISORY ONLY — TIDAK mengubah skor metrik
     * 1-10 user dan computeOverallRisk() TIDAK dipanggil di sini.
     */
    public function analyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $request->validate([
            'question_id' => 'required|string|max:128',
            'attachment_path' => 'required|string|max:1024',
        ]);

        $assessment = TiaAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        $qCode = $request->input('question_id');
        $metric = collect(TiaAssessment::effectiveMetrics($assessment->org_id))
            ->firstWhere('metric_code', $qCode);

        if (! $metric) {
            return response()->json(['message' => 'Metrik tidak ditemukan.'], 404);
        }

        // Verify the attachment_path actually belongs to this assessment
        // (per-metric attachments map) — defence against tampering.
        $attachments = $assessment->attachments ?? [];
        $metricAttachments = $attachments[$qCode] ?? [];
        $matched = collect($metricAttachments)->first(function ($att) use ($request) {
            $path = is_array($att) ? ($att['path'] ?? null) : $att;
            return $path === $request->attachment_path;
        });

        if (! $matched) {
            return response()->json([
                'message' => 'Lampiran tidak ditemukan pada metrik ini.',
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
            question: $this->metricQuestionText($metric),
            regulationRef: '',
            orgId: $orgId,
        );

        $newEntry = array_merge($result->toArray(), [
            'analyzed_at' => now()->toIso8601String(),
            'attachment_path' => $request->attachment_path,
        ]);

        // ai_analyses[qCode] = ARRAY (satu entri per attachment) supaya
        // banyak dokumen di 1 metrik bisa punya verdict masing-masing.
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

        // Advisory only: simpan hasil analisis TANPA menyentuh skor metrik /
        // computeOverallRisk() — skor TIA tetap murni dari ruler user.
        $assessment->update(['ai_analyses' => $analyses]);

        return response()->json($newEntry);
    }

    /**
     * POST /tia/{id}/analyze-evidence-bulk — mirror
     * MaturityController::bulkAnalyzeEvidence.
     *
     * Iterasi setiap (metric, attachment). Skip: metrik tanpa attachment,
     * pasangan yang sudah cached di ai_analyses (no re-charge), dan file
     * gambar (OCR belum didukung). Stop early kalau kredit habis di tengah
     * — return stats + flag `credits_exhausted`.
     */
    public function bulkAnalyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $assessment = TiaAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);
        $orgId = $request->user()->org_id;

        // Metric map: set metrik EFEKTIF org (default + override + custom
        // aktif) — keyed by metric_code, termasuk CUST-x.
        $metricMap = collect(TiaAssessment::effectiveMetrics($orgId))
            ->keyBy('metric_code');

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

            $metric = $metricMap->get($qCode);
            if (! $metric) {
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
                        question: $this->metricQuestionText($metric),
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

        // Advisory only — TANPA computeOverallRisk(); skor metrik user tidak diubah.
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
     * Compose teks "pertanyaan" untuk AiDocumentAnalyzer dari satu metrik
     * effective set: "Metrik TIA: {label}. {description}". Label/description
     * sudah hasil override per-org (Kelola Metrik) kalau ada.
     *
     * @param  array<string, mixed>  $metric
     */
    private function metricQuestionText(array $metric): string
    {
        $text = 'Metrik TIA (Transfer Impact Assessment): '.($metric['label'] ?? $metric['metric_code'] ?? '');
        if (! empty($metric['description'])) {
            $text .= '. '.$metric['description'];
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
    private function resolveAttachmentPath(TiaAssessment $assessment, string $relativePath): ?string
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
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.'tia_evidence_'.substr(hash('sha256', $rel), 0, 16).'.'.$ext;
            if (file_put_contents($tmpPath, $contents) === false) {
                return null;
            }
            return $tmpPath;
        } catch (\Throwable $e) {
            \Log::warning('[TIA resolveAttachmentPath] tenant disk fetch failed', [
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

            // Skor metrik CUSTOM per-org (Kelola Metrik), keyed by
            // metric_code (CUST-N). null = hapus skor. Disimpan ke JSON
            // risk_assessment.custom_metric_scores oleh
            // applyCustomMetricScores().
            'custom_metric_scores' => 'nullable|array',
            'custom_metric_scores.*' => 'nullable|integer|min:1|max:10',

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

        // Cek set metrik EFEKTIF (default − nonaktif + custom) — metrik
        // risk aktif wajib diskor; metrik security opsional (mitigasi).
        $customScores = $r->customMetricScores();
        foreach (TiaAssessment::effectiveMetrics($r->org_id) as $m) {
            if (($m['kind'] ?? 'risk') !== 'risk') {
                continue;
            }
            $value = ! empty($m['is_custom'])
                ? ($customScores[$m['metric_code']] ?? null)
                : $r->{$m['metric_code']};
            if ($value === null) {
                $issues[] = "Risk metric '{$m['metric_code']}' must be scored 1-10";
            }
        }

        return $issues;
    }

    /**
     * Pindahkan payload `custom_metric_scores` (keyed by metric_code
     * CUST-N) ke JSON kolom `risk_assessment.custom_metric_scores`.
     * Kode yang bukan milik org di-drop; nilai null menghapus skor.
     * Skor metrik DEFAULT tetap di kolom dedicated masing-masing.
     */
    private function applyCustomMetricScores(array $data, string $orgId, ?TiaAssessment $record = null): array
    {
        if (! array_key_exists('custom_metric_scores', $data)) {
            return $data;
        }

        $incoming = $data['custom_metric_scores'] ?? [];
        unset($data['custom_metric_scores']);
        if (! is_array($incoming)) {
            return $data;
        }

        $validCodes = CustomTiaMetric::forOrg($orgId)->pluck('metric_code')->all();

        $ra = $data['risk_assessment'] ?? $record?->risk_assessment ?? [];
        if (! is_array($ra)) {
            $ra = [];
        }
        $scores = $ra['custom_metric_scores'] ?? [];
        if (! is_array($scores)) {
            $scores = [];
        }

        foreach ($incoming as $code => $score) {
            if (! in_array($code, $validCodes, true)) {
                continue;
            }
            if ($score === null) {
                unset($scores[$code]);
            } else {
                $scores[$code] = (int) $score;
            }
        }

        $ra['custom_metric_scores'] = $scores;
        $data['risk_assessment'] = $ra;

        return $data;
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
