<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CustomMaturityQuestion;
use App\Models\MaturityAssessment;
use App\Models\MaturityQuestion;
use App\Models\MaturityQuestionOverride;
use App\Models\MaturityQuestionResponse;
use App\Services\AssessmentPdfService;
use App\Services\MaturityAutoDeriveService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

        DB::transaction(function () use ($assessment, $data, $question) {
            MaturityQuestionResponse::updateOrCreate(
                ['assessment_id' => $assessment->id, 'question_code' => $data['question_code']],
                [
                    'domain' => $question['domain'],
                    'score' => $data['score'],
                    'notes' => $data['notes'] ?? null,
                    'source' => $data['source'] ?? MaturityQuestionResponse::SOURCE_MANUAL,
                ],
            );
            $assessment->recompute();
            $assessment->save();
        });

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

        DB::transaction(function () use ($assessment, $data, $questions) {
            foreach ($data['responses'] as $r) {
                $q = $questions->get($r['question_code']);
                if (!$q) continue;
                MaturityQuestionResponse::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'question_code' => $r['question_code']],
                    [
                        'domain' => $q['domain'],
                        'score' => $r['score'],
                        'notes' => $r['notes'] ?? null,
                        'source' => $r['source'] ?? MaturityQuestionResponse::SOURCE_MANUAL,
                    ],
                );
            }
            $assessment->recompute();
            $assessment->save();
        });

        return response()->json([
            'message' => 'Responses saved.',
            'data' => $assessment->fresh()->load('responses'),
        ]);
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

        return response()->json([
            'data' => $questions->values(),
            'domains' => collect(MaturityQuestion::ALL_DOMAINS)->map(fn ($d) => [
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

    public function storeCustomQuestion(Request $request)
    {
        $data = $request->validate([
            'domain' => ['required', Rule::in(MaturityQuestion::ALL_DOMAINS)],
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
            'domain' => $data['domain'],
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
            'domain' => ['sometimes', Rule::in(MaturityQuestion::ALL_DOMAINS)],
            'question_text' => 'sometimes|string',
            'description' => 'nullable|string',
            'regulation_ref' => 'nullable|string|max:100',
            'scoring_guide' => 'nullable|array',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $question->update($request->only([
            'domain', 'question_text', 'description', 'regulation_ref',
            'scoring_guide', 'sort_order', 'is_active',
        ]));

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
