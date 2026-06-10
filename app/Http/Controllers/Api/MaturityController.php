<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomMaturityQuestion;
use App\Models\MaturityAssessment;
use App\Models\MaturityQuestion;
use App\Models\MaturityQuestionOverride;
use App\Models\MaturityQuestionResponse;
use App\Models\Organization;
use App\Services\AiDocumentAnalyzer;
use App\Services\AssessmentPdfService;
use App\Services\CreditService;
use App\Services\FileUploadValidator;
use App\Services\MaturityAutoDeriveService;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Maturity Assessment — UU PDP compliance maturity self-evaluation.
 *
 * Lifecycle (no Approver gate — informational, not approval):
 *   draft → submitted → published
 *
 * Three input methods (PDF "Bentuk Modul Maturity Assessment"):
 *   1. questionnaire — DPO clicks 1-10 ruler for each of 18 questions
 *   2. document      — upload SOP/Kebijakan, AI scores from content (X4)
 *   3. auto_derive   — service queries existing Nexus data for scoring
 *
 * Per-question scores live in maturity_question_responses; the
 * assessment row carries the computed averages (overall_score,
 * domain_scores) and overall_level. Recomputation runs on every
 * response update via MaturityAssessment::recompute().
 */
class MaturityController extends Controller
{
    public function __construct(protected MaturityAutoDeriveService $deriver) {}

    public function index(Request $request)
    {
        $query = MaturityAssessment::query();
        if ($request->boolean('trash')) $query->onlyTrashed();
        if ($s = $request->get('status')) $query->where('status', $s);
        if ($i = $request->get('input_method')) $query->where('input_method', $i);
        if ($l = $request->get('level')) $query->where('overall_level', $l);
        $records = $query->orderByDesc('created_at')->paginate($request->integer('per_page', 25));
        return response()->json(['data' => $records]);
    }

    public function show(string $id)
    {
        $record = MaturityAssessment::query()
            ->with(['responses', 'submitter:id,name,email'])
            ->withTrashed()
            ->findOrFail($id);
        return response()->json([
            'data' => array_merge($record->toArray(), [
                'level_label' => $record->levelLabel(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'input_method' => ['required', Rule::in([
                MaturityAssessment::INPUT_QUESTIONNAIRE,
                MaturityAssessment::INPUT_DOCUMENT,
                MaturityAssessment::INPUT_AUTO_DERIVE,
            ])],
            'version' => 'nullable|string|max:32',
        ]);

        $record = MaturityAssessment::create([
            'org_id' => $request->user()->org_id,
            'title' => $data['title'],
            'input_method' => $data['input_method'],
            'version' => $data['version'] ?? 'v1',
            'status' => MaturityAssessment::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        AuditLog::log('maturity', $record->id, 'created', [
            'input_method' => $record->input_method,
        ], 'manual');

        return response()->json(['message' => 'Maturity Assessment draft created.', 'data' => $record], 201);
    }

    /**
     * Update or insert a per-question response. Used by the wizard's
     * Save Draft path while the operator fills the questionnaire.
     */
    public function upsertResponse(Request $request, string $id)
    {
        $data = $request->validate([
            'question_code' => 'required|string|max:16',
            'score' => 'required|integer|min:1|max:10',
            'notes' => 'nullable|string|max:2000',
            'source' => ['nullable', Rule::in([
                MaturityQuestionResponse::SOURCE_MANUAL,
                MaturityQuestionResponse::SOURCE_AUTO_DERIVE,
                MaturityQuestionResponse::SOURCE_DOCUMENT_AI,
            ])],
            // Keterangan penyesuaian (provenance) — dipakai saat reviewer
            // mengubah skor pada assessment yang SUDAH submitted.
            'reason' => 'nullable|string|max:1000',
        ]);

        $assessment = MaturityAssessment::query()->findOrFail($id);
        if ($assessment->status === MaturityAssessment::STATUS_PUBLISHED) {
            return response()->json(['message' => 'Published assessments are read-only.'], 423);
        }

        // Validasi terhadap set pertanyaan EFEKTIF org (default aktif +
        // custom aktif) — bukan hanya bank platform, supaya pertanyaan
        // custom bisa dijawab dan default yang dinonaktifkan ditolak.
        $question = collect(MaturityQuestion::effectiveQuestions($assessment->org_id, $assessment->version ?? 'v1'))
            ->firstWhere('question_code', $data['question_code']);
        if (! $question) {
            return response()->json(['message' => 'Pertanyaan tidak ditemukan di set pertanyaan aktif organisasi.'], 422);
        }

        $adjusted = null;
        DB::transaction(function () use ($assessment, $data, $question, $request, &$adjusted) {
            $attrs = [
                'domain' => $question['domain'],
                'score' => $data['score'],
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'] ?? MaturityQuestionResponse::SOURCE_MANUAL,
            ];

            // Review window: assessment sudah submitted (belum published) +
            // response sudah ada dengan skor BERBEDA → ini penyesuaian
            // reviewer. Catat provenance di source_metadata (draft edits
            // tetap seperti semula, tanpa metadata).
            $existing = MaturityQuestionResponse::query()
                ->where('assessment_id', $assessment->id)
                ->where('question_code', $data['question_code'])
                ->first();
            if ($assessment->status === MaturityAssessment::STATUS_SUBMITTED
                && $existing
                && (int) $existing->score !== (int) $data['score']) {
                $adjusted = $this->buildResponseAdjustmentMeta(
                    $request,
                    (int) $existing->score,
                    (int) $data['score'],
                    $data['reason'] ?? null,
                );
                $attrs['source'] = MaturityQuestionResponse::SOURCE_MANUAL;
                $attrs['source_metadata'] = $adjusted;
            }

            MaturityQuestionResponse::updateOrCreate(
                ['assessment_id' => $assessment->id, 'question_code' => $data['question_code']],
                $attrs,
            );
            $assessment->recompute();
            $assessment->save();
        });

        if ($adjusted) {
            AuditLog::log('maturity', $assessment->id, 'response_adjusted', [
                'question_code' => $data['question_code'],
                'old_score' => $adjusted['old_score'],
                'new_score' => $adjusted['new_score'],
                'reason' => $adjusted['reason'],
            ], 'manual');
        }

        return response()->json(['message' => 'Response saved.', 'data' => $assessment->fresh()->load('responses')]);
    }

    /**
     * Bulk replace all responses — useful for the Review step where
     * the operator adjusted multiple scores after auto-derive.
     */
    public function bulkUpsertResponses(Request $request, string $id)
    {
        $data = $request->validate([
            'responses' => 'required|array|min:1',
            'responses.*.question_code' => 'required|string|max:16',
            'responses.*.score' => 'required|integer|min:1|max:10',
            'responses.*.notes' => 'nullable|string|max:2000',
            'responses.*.source' => ['nullable', Rule::in([
                MaturityQuestionResponse::SOURCE_MANUAL,
                MaturityQuestionResponse::SOURCE_AUTO_DERIVE,
                MaturityQuestionResponse::SOURCE_DOCUMENT_AI,
            ])],
            // Keterangan penyesuaian (provenance) — satu reason untuk
            // semua item yang skornya BERUBAH pada assessment submitted.
            'reason' => 'nullable|string|max:1000',
        ]);

        $assessment = MaturityAssessment::query()->findOrFail($id);
        if ($assessment->status === MaturityAssessment::STATUS_PUBLISHED) {
            return response()->json(['message' => 'Published assessments are read-only.'], 423);
        }

        // Set pertanyaan EFEKTIF org — default aktif + custom aktif.
        // Response untuk code di luar set ini di-skip diam-diam (sama
        // seperti behavior lama untuk code yang tidak dikenal).
        $questions = collect(MaturityQuestion::effectiveQuestions($assessment->org_id, $assessment->version ?? 'v1'))
            ->keyBy('question_code');

        // Review window: skor existing yang berubah saat status submitted
        // dicatat sebagai penyesuaian reviewer (source_metadata.adjusted).
        $isReviewWindow = $assessment->status === MaturityAssessment::STATUS_SUBMITTED;
        $existingByCode = $isReviewWindow
            ? MaturityQuestionResponse::query()
                ->where('assessment_id', $assessment->id)
                ->get()
                ->keyBy('question_code')
            : collect();
        $adjustedItems = [];

        DB::transaction(function () use ($assessment, $data, $questions, $request, $isReviewWindow, $existingByCode, &$adjustedItems) {
            foreach ($data['responses'] as $r) {
                $q = $questions->get($r['question_code']);
                if (!$q) continue;

                $attrs = [
                    'domain' => $q['domain'],
                    'score' => $r['score'],
                    'notes' => $r['notes'] ?? null,
                    'source' => $r['source'] ?? MaturityQuestionResponse::SOURCE_MANUAL,
                ];

                $existing = $existingByCode->get($r['question_code']);
                if ($isReviewWindow && $existing && (int) $existing->score !== (int) $r['score']) {
                    $meta = $this->buildResponseAdjustmentMeta(
                        $request,
                        (int) $existing->score,
                        (int) $r['score'],
                        $data['reason'] ?? null,
                    );
                    $attrs['source'] = MaturityQuestionResponse::SOURCE_MANUAL;
                    $attrs['source_metadata'] = $meta;
                    $adjustedItems[] = [
                        'question_code' => $r['question_code'],
                        'old_score' => $meta['old_score'],
                        'new_score' => $meta['new_score'],
                    ];
                }

                MaturityQuestionResponse::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'question_code' => $r['question_code']],
                    $attrs,
                );
            }
            $assessment->recompute();
            $assessment->save();
        });

        if (! empty($adjustedItems)) {
            AuditLog::log('maturity', $assessment->id, 'response_adjusted', [
                'adjustments' => $adjustedItems,
                'reason' => $data['reason'] ?? null,
            ], 'manual');
        }

        return response()->json([
            'message' => 'Responses saved.',
            'data' => $assessment->fresh()->load('responses'),
        ]);
    }

    /**
     * Build source_metadata provenance entry untuk penyesuaian skor oleh
     * reviewer pada assessment submitted: siapa (nama + role), kapan,
     * alasan, dan skor lama → baru. Ditampilkan FE sebagai badge
     * "Manual — disesuaikan {nama} ({role})".
     *
     * @return array<string, mixed>
     */
    private function buildResponseAdjustmentMeta(Request $request, int $oldScore, int $newScore, ?string $reason): array
    {
        $user = $request->user();

        return [
            'adjusted' => true,
            'old_score' => $oldScore,
            'new_score' => $newScore,
            'reason' => $reason,
            'adjusted_by' => $user?->id,
            'adjusted_by_name' => $user?->name,
            'adjusted_by_role' => $user?->tenantRole->name ?? $user?->role,
            'adjusted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Run auto-derivation against existing Nexus data and persist 18
     * responses with source='auto_derive'. The operator can adjust each
     * score afterwards via upsertResponse before submitting.
     */
    public function autoDerive(Request $request, string $id)
    {
        $assessment = MaturityAssessment::query()->findOrFail($id);
        if ($assessment->status === MaturityAssessment::STATUS_PUBLISHED) {
            return response()->json(['message' => 'Published assessments are read-only.'], 423);
        }

        $orgId = $request->user()->org_id;
        if (!$orgId) abort(403, 'No tenant context.');

        $derived = $this->deriver->deriveAll($orgId);

        // Map question_code → effective question. Default yang
        // dinonaktifkan org tidak ada di map → skor derived-nya di-skip.
        $questions = collect(MaturityQuestion::effectiveQuestions($orgId, $assessment->version ?? 'v1'))
            ->keyBy('question_code');

        DB::transaction(function () use ($assessment, $derived, $questions) {
            foreach ($derived as $code => $r) {
                $q = $questions->get($code);
                if (!$q) continue;
                MaturityQuestionResponse::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'question_code' => $code],
                    [
                        'domain' => $q['domain'],
                        'score' => $r['score'],
                        'source' => MaturityQuestionResponse::SOURCE_AUTO_DERIVE,
                        'source_metadata' => $r['metadata'] ?? null,
                    ],
                );
            }
            $assessment->auto_derived_at = now();
            $assessment->auto_derive_metadata = ['question_count' => count($derived), 'at' => now()->toIso8601String()];
            $assessment->recompute();
            $assessment->save();
        });

        AuditLog::log('maturity', $assessment->id, 'auto_derived', [
            'questions_scored' => count($derived),
            'overall_score' => $assessment->overall_score,
            'overall_level' => $assessment->overall_level,
        ], 'manual');

        return response()->json([
            'message' => 'Auto-derive complete. Review scores before submitting.',
            'data' => $assessment->fresh()->load('responses'),
        ]);
    }

    public function submit(Request $request, string $id)
    {
        $assessment = MaturityAssessment::query()->findOrFail($id);
        if ($assessment->status === MaturityAssessment::STATUS_PUBLISHED) {
            return response()->json(['message' => 'Already published.'], 409);
        }
        // Wajib menjawab SEMUA pertanyaan efektif org (default aktif +
        // custom aktif) — bukan angka 18 hard-coded, karena org bisa
        // menonaktifkan default dan menambah pertanyaan custom.
        $effectiveCodes = collect(MaturityQuestion::effectiveQuestions($assessment->org_id, $assessment->version ?? 'v1'))
            ->pluck('question_code');
        $answered = $assessment->responses()->whereIn('question_code', $effectiveCodes)->count();
        if ($answered < $effectiveCodes->count()) {
            return response()->json([
                'message' => 'All ' . $effectiveCodes->count() . ' questions must have a score before submitting.',
                'answered' => $answered,
                'required' => $effectiveCodes->count(),
            ], 422);
        }

        $assessment->status = MaturityAssessment::STATUS_SUBMITTED;
        $assessment->submitted_at = now();
        $assessment->submitted_by = $request->user()->id;
        $assessment->recompute();
        $assessment->save();

        AuditLog::log('maturity', $assessment->id, 'submitted', [
            'overall_score' => $assessment->overall_score,
            'overall_level' => $assessment->overall_level,
        ], 'manual');

        try {
            \App\Services\NotificationService::dispatch(
                kind: 'info', severity: 'medium', module: 'maturity',
                type: 'maturity.submitted', recipient: 'role:dpo,admin', orgId: $assessment->org_id,
                title: 'Maturity Assessment disubmit: '.($assessment->title ?? ''),
                body: 'Skor '.$assessment->overall_score.'/10 — Level '.$assessment->levelLabel().'.',
                actionUrl: "/maturity", metadata: ['record_id' => $assessment->id],
            );
        } catch (\Throwable $e) { \Log::warning('maturity.submitted notif failed: '.$e->getMessage()); }

        return response()->json([
            'message' => 'Submitted. Score: ' . $assessment->overall_score . ' / 10 — Level ' . $assessment->levelLabel(),
            'data' => $assessment->fresh(),
        ]);
    }

    public function publish(Request $request, string $id)
    {
        $assessment = MaturityAssessment::query()->findOrFail($id);
        if ($assessment->status !== MaturityAssessment::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Only submitted assessments can be published.'], 409);
        }
        $assessment->status = MaturityAssessment::STATUS_PUBLISHED;
        $assessment->save();

        AuditLog::log('maturity', $assessment->id, 'published', [], 'manual');

        return response()->json(['message' => 'Published.', 'data' => $assessment->fresh()]);
    }

    /**
     * Effective question list (per active version) untuk org pemanggil:
     * default platform + override per-org (default nonaktif di-drop) +
     * custom questions aktif. include_inactive=1 dipakai management UI
     * supaya default yang dinonaktifkan tetap tampil (flag
     * is_active=false) dan bisa diaktifkan lagi.
     */
    public function questions(Request $request)
    {
        $version = $request->get('version', 'v1');
        $includeInactive = $request->boolean('include_inactive');
        $questions = collect(MaturityQuestion::effectiveQuestions(
            $request->user()?->org_id,
            $version,
            $includeInactive,
        ));

        // Domain meta dibangun dari union ALL_DOMAINS + domain yang benar-benar
        // ada di set efektif — pertanyaan custom boleh memakai domain BARU
        // (di luar 4 default), dan FE (wizard + Kelola Pertanyaan) iterate
        // list ini, bukan hardcode 4 domain.
        $domainKeys = collect(MaturityQuestion::ALL_DOMAINS)
            ->concat($questions->pluck('domain'))
            ->filter()
            ->unique()
            ->values();

        return response()->json([
            'data' => $questions->values(),
            'domains' => $domainKeys->map(fn ($d) => [
                'key' => $d,
                'label' => MaturityQuestion::DOMAIN_LABELS[$d] ?? $d,
                'count' => $questions->where('domain', $d)->count(),
            ])->values(),
        ]);
    }

    // =============================================
    // Custom Questions CRUD (Kelola Pertanyaan)
    // =============================================

    public function customQuestions(Request $request)
    {
        $questions = CustomMaturityQuestion::forOrg($request->user()->org_id)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $questions]);
    }

    /**
     * Normalisasi ringan domain custom: trim + lowercase + spasi → underscore
     * supaya konsisten sebagai key di domain_scores. Domain BARU (di luar 4
     * default) diperbolehkan — recompute() membangun peta domain dari set
     * efektif sehingga domain baru otomatis jadi key baru di domain_scores.
     */
    private function normalizeDomain(string $domain): string
    {
        return preg_replace('/\s+/', '_', strtolower(trim($domain)));
    }

    public function storeCustomQuestion(Request $request)
    {
        $data = $request->validate([
            'domain' => 'required|string|max:100',
            'question_text' => 'required|string',
            'description' => 'nullable|string',
            'regulation_ref' => 'nullable|string|max:100',
            'scoring_guide' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $orgId = $request->user()->org_id;

        // question_code auto: CUST-1, CUST-2, ... — withTrashed supaya
        // tidak menabrak unique constraint dengan row yang soft-deleted.
        $lastNum = CustomMaturityQuestion::withTrashed()
            ->where('org_id', $orgId)
            ->pluck('question_code')
            ->map(fn ($c) => (int) preg_replace('/\D+/', '', (string) $c))
            ->max() ?? 0;

        $question = CustomMaturityQuestion::create([
            'org_id' => $orgId,
            'question_code' => 'CUST-' . ($lastNum + 1),
            'domain' => $this->normalizeDomain($data['domain']),
            'question_text' => $data['question_text'],
            'description' => $data['description'] ?? null,
            'regulation_ref' => $data['regulation_ref'] ?? null,
            'scoring_guide' => $data['scoring_guide'] ?? null,
            'sort_order' => $data['sort_order']
                ?? ((int) CustomMaturityQuestion::forOrg($orgId)->max('sort_order') + 1),
        ]);

        return response()->json(['message' => 'Pertanyaan custom ditambahkan.', 'data' => $question], 201);
    }

    public function updateCustomQuestion(Request $request, string $id)
    {
        $question = CustomMaturityQuestion::forOrg($request->user()->org_id)->findOrFail($id);

        $request->validate([
            'domain' => 'sometimes|string|max:100',
            'question_text' => 'sometimes|string',
            'description' => 'nullable|string',
            'regulation_ref' => 'nullable|string|max:100',
            'scoring_guide' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $payload = $request->only([
            'domain', 'question_text', 'description', 'regulation_ref',
            'scoring_guide', 'sort_order', 'is_active',
        ]);
        if (isset($payload['domain']) && is_string($payload['domain'])) {
            $payload['domain'] = $this->normalizeDomain($payload['domain']);
        }
        $question->update($payload);

        return response()->json(['message' => 'Pertanyaan custom diperbarui.', 'data' => $question->fresh()]);
    }

    public function destroyCustomQuestion(Request $request, string $id)
    {
        $question = CustomMaturityQuestion::forOrg($request->user()->org_id)->findOrFail($id);
        $question->delete();

        return response()->json(['message' => 'Pertanyaan custom dihapus.']);
    }

    // =============================================
    // Default Question Overrides (copy-on-write)
    // =============================================
    //
    // Pertanyaan DEFAULT bisa di-EDIT (teks/deskripsi/referensi regulasi/
    // panduan skor) dan di-NONAKTIFKAN per org, tapi TIDAK bisa dihapus
    // dan domain TIDAK bisa diubah. Edit mem-fork baris override
    // (maturity_question_overrides); reset menghapus override sehingga
    // kembali ke nilai default platform.

    /**
     * PUT /maturity/default-questions/{questionCode}
     * Upsert override untuk org pemanggil. Field yang nilainya sama dengan
     * default disimpan NULL (= tidak di-override) supaya flag is_overridden
     * akurat dan reset semantics bersih.
     */
    public function updateDefaultQuestion(Request $request, string $questionCode)
    {
        $request->validate([
            'version' => 'nullable|string|max:16',
            'question_text' => 'nullable|string',
            'description' => 'nullable|string',
            'regulation_ref' => 'nullable|string|max:100',
            'scoring_guide' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $orgId = $request->user()->org_id;
        $version = $request->input('version', 'v1');

        $default = MaturityQuestion::query()->withoutGlobalScope('org')
            ->where('question_code', $questionCode)
            ->where('version', $version)
            ->where('is_active', true)
            ->first();
        if (! $default) {
            return response()->json(['message' => 'Pertanyaan default tidak ditemukan.'], 404);
        }

        // Hanya simpan field yang BERBEDA dari nilai default — yang sama
        // (atau kosong) disimpan NULL = "pakai default".
        $values = [];
        foreach (MaturityQuestionOverride::OVERRIDABLE_TEXT_FIELDS as $field) {
            if ($request->has($field)) {
                $val = $request->input($field);
                $val = is_string($val) ? trim($val) : $val;
                $values[$field] = ($val === null || $val === '' || $val === ($default->{$field} ?? null)) ? null : $val;
            }
        }
        if ($request->has('scoring_guide')) {
            $guide = $request->input('scoring_guide');
            $values['scoring_guide'] = (empty($guide) || $guide == $default->scoring_guide) ? null : $guide;
        }
        if ($request->has('is_active')) {
            $values['is_active'] = $request->boolean('is_active');
        }

        // Upsert — restore dulu kalau row pernah soft-deleted (unique
        // constraint org+question_code mencegah duplikat).
        $override = MaturityQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('question_code', $questionCode)
            ->first();

        if ($override) {
            if ($override->trashed()) {
                $override->restore();
            }
            $override->fill($values)->save();
        } else {
            $override = MaturityQuestionOverride::create(array_merge([
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

        $effective = collect(MaturityQuestion::effectiveQuestions($orgId, $version, true))
            ->firstWhere('question_code', $questionCode);

        return response()->json([
            'message' => 'Pertanyaan default diperbarui.',
            'data' => $effective,
        ]);
    }

    /**
     * POST /maturity/default-questions/{questionCode}/reset
     * Hapus override org → pertanyaan kembali ke nilai default platform.
     */
    public function resetDefaultQuestion(Request $request, string $questionCode)
    {
        $request->validate([
            'version' => 'nullable|string|max:16',
        ]);

        $orgId = $request->user()->org_id;
        $version = $request->input('version', 'v1');

        MaturityQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->where('question_code', $questionCode)
            ->forceDelete();

        $effective = collect(MaturityQuestion::effectiveQuestions($orgId, $version, true))
            ->firstWhere('question_code', $questionCode);

        return response()->json([
            'message' => 'Pertanyaan dikembalikan ke default.',
            'data' => $effective,
        ]);
    }

    /**
     * POST /maturity/questions/factory-reset
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

        $overridesRemoved = MaturityQuestionOverride::withTrashed()
            ->where('org_id', $orgId)
            ->forceDelete();

        $customsRemoved = CustomMaturityQuestion::forOrg($orgId)->delete();

        AuditLog::log('maturity', $orgId, 'questions_factory_reset', [
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
        ]);

        return response()->json([
            'message' => 'Semua pertanyaan dikembalikan ke default pabrikan.',
            'overrides_removed' => (int) $overridesRemoved,
            'customs_removed' => (int) $customsRemoved,
        ]);
    }

    /**
     * Trend over the last N submitted/published assessments.
     * Frontend renders as a line chart.
     */
    public function trend(Request $request)
    {
        $orgId = $request->user()->org_id;
        $months = $request->integer('months', 12);
        $since = now()->subMonths($months);

        $records = MaturityAssessment::query()
            ->where('org_id', $orgId)
            ->whereIn('status', [MaturityAssessment::STATUS_SUBMITTED, MaturityAssessment::STATUS_PUBLISHED])
            ->where('submitted_at', '>=', $since)
            ->orderBy('submitted_at')
            ->get(['id', 'title', 'submitted_at', 'overall_score', 'overall_level', 'domain_scores', 'input_method']);

        return response()->json(['data' => $records]);
    }

    /**
     * Generate recommendations from the current assessment's level.
     * Static templates from PDF §3 — Sprint X4 will add AI-generated
     * specifics based on the lowest-scoring questions.
     */
    public function recommendations(string $id)
    {
        $assessment = MaturityAssessment::query()->findOrFail($id);
        $level = $assessment->overall_level ?? 1;

        $templates = [
            1 => [
                'focus' => 'Fondasi dan Kesadaran',
                'actions' => [
                    'Lakukan Data Mapping (inventarisasi data) sebagai langkah awal.',
                    'Susun Kebijakan Privasi (Privacy Policy) dasar yang berlaku organisasi-wide.',
                    'Berikan pelatihan kesadaran PDP bagi seluruh karyawan.',
                ],
            ],
            2 => [
                'focus' => 'Standardisasi',
                'actions' => [
                    'Resmikan penunjukan DPO secara tertulis (SK + tugas terdokumentasi).',
                    'Buat template persetujuan (consent form) yang seragam organisasi-wide.',
                    'Susun prosedur penanganan permintaan hak subjek data secara formal.',
                ],
            ],
            3 => [
                'focus' => 'Pengawasan dan Audit',
                'actions' => [
                    'Lakukan audit kepatuhan internal secara berkala (minimum tahunan).',
                    'Pastikan klausul perlindungan data masuk ke setiap kontrak vendor.',
                    'Gunakan alat bantu pemantauan keamanan data (SIEM, DLP).',
                ],
            ],
            4 => [
                'focus' => 'Efisiensi dan Ketahanan',
                'actions' => [
                    'Implementasikan prinsip Privacy by Design dalam SDLC.',
                    'Otomatisasi sistem penghapusan data sesuai masa retensi.',
                    'Jadikan kepatuhan data sebagai nilai tambah kompetitif perusahaan.',
                ],
            ],
        ];

        // Compute lowest-scoring questions for "focus areas"
        $lowest = $assessment->responses()
            ->orderBy('score')
            ->limit(5)
            ->get(['question_code', 'domain', 'score']);

        return response()->json([
            'data' => [
                'level' => $level,
                'level_label' => $assessment->levelLabel(),
                'overall_score' => $assessment->overall_score,
                'domain_scores' => $assessment->domain_scores,
                'template' => $templates[$level] ?? $templates[1],
                'focus_areas' => $lowest->map(fn ($r) => [
                    'question_code' => $r->question_code,
                    'domain' => $r->domain,
                    'score' => $r->score,
                ]),
            ],
        ]);
    }

    // =============================================
    // Evidence Upload + AI Analysis (parity dgn GAP)
    // =============================================

    /**
     * POST /maturity/{id}/upload-evidence — mirror
     * GapAssessmentController::uploadEvidence. Multi-file per pertanyaan;
     * attachments[question_code][] = { path, url, name, driver, uploaded_at }.
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

        $assessment = MaturityAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        if ($assessment->status === MaturityAssessment::STATUS_PUBLISHED) {
            return response()->json(['message' => 'Published assessments are read-only.'], 423);
        }

        // Validasi question_code terhadap set pertanyaan EFEKTIF org
        // (default aktif + override + custom aktif) — evidence hanya bisa
        // di-attach ke pertanyaan yang benar-benar ada untuk org ini.
        $qCode = $request->input('question_id');
        $question = collect(MaturityQuestion::effectiveQuestions($assessment->org_id, $assessment->version ?? 'v1'))
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
                "maturity/{$assessment->id}/evidence"
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

        AuditLog::log('maturity', $assessment->id, 'evidence_uploaded', [
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
     * POST /maturity/{id}/analyze-evidence — mirror
     * GapAssessmentController::analyzeEvidence.
     *
     * Body: question_id (question_code) + attachment_path. Teks pertanyaan
     * + regulation_ref di-resolve dari set pertanyaan EFEKTIF org
     * (default + override per-org + custom) sehingga teks yang dianalisis
     * AI adalah teks hasil override (bukan default asli).
     *
     * Hasil disimpan ke ai_analyses[question_code][] keyed by
     * attachment_path. 1 kredit per analisis (deduct di AiDocumentAnalyzer
     * untuk panggilan sukses non-cache); 402 kalau kredit habis.
     *
     * PENTING: verdict AI di Maturity ADVISORY ONLY — TIDAK mengubah skor
     * slider 1-10 user dan recompute() TIDAK dipanggil di sini.
     */
    public function analyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $request->validate([
            'question_id' => 'required|string|max:128',
            'attachment_path' => 'required|string|max:1024',
        ]);

        $assessment = MaturityAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        $qCode = $request->input('question_id');
        $question = collect(MaturityQuestion::effectiveQuestions($assessment->org_id, $assessment->version ?? 'v1'))
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
            question: $question['question_text'] ?? '',
            regulationRef: $question['regulation_ref'] ?? '',
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

        // Advisory only: simpan hasil analisis TANPA menyentuh skor /
        // recompute() — skor Maturity tetap murni dari slider user.
        $assessment->update(['ai_analyses' => $analyses]);

        return response()->json($newEntry);
    }

    /**
     * POST /maturity/{id}/analyze-evidence-bulk — mirror
     * GapAssessmentController::bulkAnalyzeEvidence.
     *
     * Iterasi setiap (question, attachment). Skip: pertanyaan tanpa
     * attachment, pasangan yang sudah cached di ai_analyses (no
     * re-charge), dan file gambar (OCR belum didukung). Stop early kalau
     * kredit habis di tengah — return stats + flag `credits_exhausted`.
     */
    public function bulkAnalyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $assessment = MaturityAssessment::query()
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($id);
        $orgId = $request->user()->org_id;

        // Question map: set pertanyaan EFEKTIF org (default + override +
        // custom aktif) — keyed by question_code, termasuk CUST-x.
        $questionMap = collect(MaturityQuestion::effectiveQuestions($orgId, $assessment->version ?? 'v1'))
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
                        question: $question['question_text'] ?? '',
                        regulationRef: $question['regulation_ref'] ?? '',
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

        // Advisory only — TANPA recompute(); skor slider user tidak diubah.
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
     * GapAssessmentController::resolveAttachmentPath). Layer 1: local
     * public/app disk; Layer 2: tenant disk (S3/GCS) → download ke temp
     * file supaya analyzer (PdfParser/PhpWord/PhpSpreadsheet) bisa baca.
     */
    private function resolveAttachmentPath(MaturityAssessment $assessment, string $relativePath): ?string
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
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.'maturity_evidence_'.substr(hash('sha256', $rel), 0, 16).'.'.$ext;
            if (file_put_contents($tmpPath, $contents) === false) {
                return null;
            }
            return $tmpPath;
        } catch (\Throwable $e) {
            \Log::warning('[Maturity resolveAttachmentPath] tenant disk fetch failed', [
                'org_id' => $assessment->org_id,
                'path' => $rel,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function destroy(string $id)
    {
        $r = MaturityAssessment::query()->findOrFail($id);
        $r->delete();
        AuditLog::log('maturity', $r->id, 'soft_deleted', [], 'manual');
        return response()->json(['message' => 'Moved to trash.']);
    }

    public function restore(string $id)
    {
        $r = MaturityAssessment::onlyTrashed()->findOrFail($id);
        $r->restore();
        AuditLog::log('maturity', $r->id, 'restored', [], 'manual');
        return response()->json(['message' => 'Restored.']);
    }

    public function forceDelete(string $id)
    {
        $r = MaturityAssessment::withTrashed()->findOrFail($id);
        $r->forceDelete();
        AuditLog::log('maturity', $id, 'hard_deleted', [], 'manual');
        return response()->json(['message' => 'Permanently deleted.']);
    }

    /**
     * Stream the maturity assessment as a branded PDF including the
     * level gauge result, per-domain scores, and the full 18-question
     * answer table.
     */
    public function exportPdf(Request $request, AssessmentPdfService $pdf, string $id)
    {
        $record = MaturityAssessment::query()->with('responses')->findOrFail($id);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $record->title ?: 'maturity');
        $filename = "Maturity_{$slug}_{$record->version}.pdf";

        AuditLog::log('maturity', $record->id, 'pdf_exported', [
            'filename' => $filename,
            'status' => $record->status,
            'overall_score' => $record->overall_score,
            'overall_level' => $record->overall_level,
        ], 'manual');

        return $pdf->maturity($record, $request->user())->download($filename);
    }
}
