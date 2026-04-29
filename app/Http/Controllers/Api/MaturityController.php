<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MaturityAssessment;
use App\Models\MaturityQuestion;
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

        $question = MaturityQuestion::query()
            ->withoutGlobalScope('org')   // platform-level
            ->where('question_code', $data['question_code'])
            ->where('version', $assessment->version)
            ->where('is_active', true)
            ->firstOrFail();

        DB::transaction(function () use ($assessment, $data, $question) {
            MaturityQuestionResponse::updateOrCreate(
                ['assessment_id' => $assessment->id, 'question_code' => $data['question_code']],
                [
                    'domain' => $question->domain,
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

        $codes = collect($data['responses'])->pluck('question_code');
        $questions = MaturityQuestion::query()->withoutGlobalScope('org')
            ->whereIn('question_code', $codes)
            ->where('version', $assessment->version)
            ->get()->keyBy('question_code');

        DB::transaction(function () use ($assessment, $data, $questions) {
            foreach ($data['responses'] as $r) {
                $q = $questions->get($r['question_code']);
                if (!$q) continue;
                MaturityQuestionResponse::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'question_code' => $r['question_code']],
                    [
                        'domain' => $q->domain,
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

        // Map question_code → domain (one query, in-memory after)
        $questions = MaturityQuestion::query()->withoutGlobalScope('org')
            ->where('version', $assessment->version)
            ->where('is_active', true)
            ->get()->keyBy('question_code');

        DB::transaction(function () use ($assessment, $derived, $questions) {
            foreach ($derived as $code => $r) {
                $q = $questions->get($code);
                if (!$q) continue;
                MaturityQuestionResponse::updateOrCreate(
                    ['assessment_id' => $assessment->id, 'question_code' => $code],
                    [
                        'domain' => $q->domain,
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
        if ($assessment->responses()->count() < 18) {
            return response()->json([
                'message' => 'All 18 questions must have a score before submitting.',
                'answered' => $assessment->responses()->count(),
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
     * Master question list (per active version). Frontend wizard uses
     * this to render the 18 ruler questions in the right order.
     */
    public function questions(Request $request)
    {
        $version = $request->get('version', 'v1');
        $questions = MaturityQuestion::query()->withoutGlobalScope('org')
            ->where('version', $version)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        return response()->json([
            'data' => $questions,
            'domains' => collect(MaturityQuestion::ALL_DOMAINS)->map(fn ($d) => [
                'key' => $d,
                'label' => MaturityQuestion::DOMAIN_LABELS[$d] ?? $d,
                'count' => $questions->where('domain', $d)->count(),
            ])->values(),
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
