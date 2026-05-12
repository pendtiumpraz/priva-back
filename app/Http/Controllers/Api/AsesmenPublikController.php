<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorQuestionnaire;
use App\Services\AssessmentTokenService;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use App\Services\ThirdPartyAssessmentScorer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Endpoint publik (tanpa login) untuk pihak ketiga mengisi kuisoner vendor.
 *
 * Akses di-gate oleh middleware PublicAssessmentTokenMiddleware yang sudah:
 *   - resolve assessment dari token UUID di URL
 *   - validasi expiry + single-use guard
 *   - set CurrentOrgContext ke org_id assessment
 *   - inject assessment ke `$request->_assessment`
 *
 * Karena assessment sudah ter-resolve, controller fokus pada operasi domain:
 *   GET  /              → show assessment + question list (untuk render form)
 *   POST /jawaban       → save batch jawaban (autosave per section)
 *   POST /upload        → upload bukti per question (single file)
 *   POST /submit        → finalize: mark consumed, lock token, audit log
 *   GET  /result        → read-only result page setelah submit
 *
 * Catatan multi-tenancy:
 *   Walau request anonim, middleware sudah set tenant context dari
 *   assessment->org_id. Operasi anak (TenantStorageService, AuditLog) akan
 *   menulis ke tenant yang benar tanpa perlu kita pass org_id manual.
 */
class AsesmenPublikController extends Controller
{
    /**
     * GET — return data minimal yang dibutuhkan UI untuk render form:
     *   - vendor info (name only, sembunyikan field internal)
     *   - questionnaire (questions list, dalam kategori vendor)
     *   - jawaban yang sudah tersimpan (untuk resume autosave)
     *   - metadata status (sudah submit / belum)
     */
    public function show(Request $request, string $token)
    {
        /** @var VendorAssessment $assessment */
        $assessment = $request->get('_assessment');
        $vendor = Vendor::withTrashed()->find($assessment->vendor_id);

        // Question bank — effective per org (landlord defaults + tenant
        // overrides + tenant custom). Filter ke versi v2_2026 (versi publik)
        // + hanya yang aktif. Sort manual di-PHP karena effectiveForOrg
        // return collection sudah di-merge.
        $category = $assessment->category ?: ($vendor?->category ?? VendorQuestionnaire::CATEGORY_CLOUD);
        $questions = VendorQuestionnaire::effectiveForOrg($assessment->org_id)
            ->filter(fn ($q) => $q->is_active && $q->version === ThirdPartyAssessmentScorer::VERSION)
            ->sortBy([
                ['section', 'asc'],
                ['sort_order', 'asc'],
            ])
            ->values()
            ->map(fn ($q) => [
                'id' => $q->id,
                'question_code' => $q->question_code,
                'section' => $q->section,
                'question_text' => $q->question_text,
                'description' => $q->description,
                'regulation_ref' => $q->regulation_ref,
                'recommendation_if_no' => $q->recommendation_if_no,
                'requires_evidence_upload' => (bool) $q->requires_evidence_upload,
                'answer_type' => $q->answer_type,
                'sort_order' => $q->sort_order,
            ]);

        // Branding minimal — UI publik harus menampilkan logo + nama tenant,
        // bukan logo Privasimu. Ambil tenant identity tanpa expose konfigurasi internal.
        $org = Organization::find($assessment->org_id);

        return response()->json([
            'data' => [
                'assessment' => [
                    'id' => $assessment->id,
                    'status' => $assessment->status,
                    'submitted_at' => optional($assessment->submitted_at)->toIso8601String(),
                    'token_expires_at' => optional($assessment->token_expires_at)->toIso8601String(),
                    'is_locked' => $assessment->token_consumed_at !== null,
                ],
                'vendor' => $vendor ? [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'category' => $category,
                ] : null,
                'organization' => $org ? [
                    'name' => $org->name ?? null,
                    'logo_url' => $org->logo_url ?? null,
                ] : null,
                'questions' => $questions,
                'answers' => $assessment->answers ?? [],
            ],
        ]);
    }

    /**
     * POST /jawaban — save batch jawaban (autosave per section).
     *
     * Payload yang diterima:
     *   { "answers": { "<question_id>": { "value": "...", "note": "..." }, ... } }
     *
     * Kita MERGE dengan answers existing supaya autosave per section tidak
     * menghapus jawaban section lain. Status di-bump ke 'sent' kalau masih
     * 'draft' supaya admin tahu ada progress dari pihak ketiga.
     */
    public function saveDraft(Request $request, string $token)
    {
        /** @var VendorAssessment $assessment */
        $assessment = $request->get('_assessment');

        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'array',
            'answers.*.value' => 'nullable',
            'answers.*.note' => 'nullable|string|max:2000',
        ]);

        $existing = is_array($assessment->answers) ? $assessment->answers : [];
        $merged = array_merge($existing, $validated['answers']);

        $assessment->forceFill([
            'answers' => $merged,
            // Jangan timpa kalau sudah 'submitted' — middleware harusnya sudah block,
            // tapi extra defensive di sini.
            'status' => $assessment->status === 'submitted' ? 'submitted' : 'sent',
        ])->save();

        return response()->json([
            'message' => 'Jawaban tersimpan.',
            'data' => [
                'saved_count' => count($validated['answers']),
                'total_answered' => count($merged),
            ],
        ]);
    }

    /**
     * POST /upload — single file evidence per question.
     *
     * Payload (multipart):
     *   - question_id : string (UUID dari VendorQuestionnaire)
     *   - file        : UploadedFile (max 10MB, pdf/docx/jpg/png saja)
     *
     * Pakai FileUploadValidator::PRESET_DOCUMENT yang sudah block .php/.exe/dll
     * via magic-byte check (bukan hanya extension). Storage via TenantStorageService
     * tetap ter-scope ke org milik assessment.
     */
    public function uploadEvidence(Request $request, string $token, TenantStorageService $storage, FileUploadValidator $validator)
    {
        /** @var VendorAssessment $assessment */
        $assessment = $request->get('_assessment');

        $request->validate([
            'question_id' => 'required|string|max:64',
            'file' => 'required|file|max:10240', // 10MB
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        // Whitelist eksplisit selain MIME check — pihak ketiga lebih restricted
        // dibanding internal user, jadi hanya 4 format yang relevan untuk bukti.
        $allowed = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
        if (! in_array($ext, $allowed, true)) {
            return response()->json([
                'message' => 'Format file tidak diizinkan. Hanya PDF, DOCX, JPG, atau PNG.',
            ], 422);
        }

        try {
            // PRESET_DOCUMENT cover pdf/docx; image kita tambahkan via $additionalAllowed.
            $preset = in_array($ext, ['jpg', 'jpeg', 'png'], true)
                ? FileUploadValidator::PRESET_IMAGE
                : FileUploadValidator::PRESET_DOCUMENT;
            $validator->validate($file, $preset);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $org = Organization::findOrFail($assessment->org_id);

        try {
            $result = $storage->storeTenantPrivateFile(
                $org,
                $file,
                "tprm/public-assessments/{$assessment->id}/evidence"
            );
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => 'Gagal menyimpan file ke storage.',
            ], 500);
        }

        // Simpan referensi evidence di dalam `answers[<question_id>].evidence[]`
        // supaya konsisten dengan struktur jawaban — tidak perlu kolom baru.
        $answers = is_array($assessment->answers) ? $assessment->answers : [];
        $qId = $request->input('question_id');
        if (! isset($answers[$qId]) || ! is_array($answers[$qId])) {
            $answers[$qId] = [];
        }
        if (! isset($answers[$qId]['evidence']) || ! is_array($answers[$qId]['evidence'])) {
            $answers[$qId]['evidence'] = [];
        }

        $entry = [
            'id' => (string) Str::uuid(),
            'path' => $result['path'] ?? null,
            'driver' => $result['driver'] ?? null,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'uploaded_at' => now()->toIso8601String(),
        ];
        $answers[$qId]['evidence'][] = $entry;

        $assessment->forceFill(['answers' => $answers])->save();

        return response()->json([
            'message' => 'Bukti berhasil diunggah.',
            'data' => $entry,
        ], 201);
    }

    /**
     * POST /submit — finalisasi. Setelah ini token tidak bisa dipakai lagi
     * untuk menulis. Operation idempotent: kalau status sudah 'submitted'
     * (race dari middleware yang lolos), tetap return 410 supaya client
     * jelas bahwa ini adalah error state.
     *
     * Pakai DB transaction supaya update assessment + audit log atomic.
     */
    public function submit(
        Request $request,
        string $token,
        AssessmentTokenService $tokens,
        ThirdPartyAssessmentScorer $scorer,
    ) {
        /** @var VendorAssessment $assessment */
        $assessment = $request->get('_assessment');

        if ($assessment->token_consumed_at !== null) {
            return response()->json([
                'error' => 'Asesmen sudah dikirim sebelumnya.',
                'result_url' => url('/api/asesmen-publik/' . $token . '/result'),
            ], 410);
        }

        // Hitung skor dulu di luar transaction supaya kalau scorer error,
        // status assessment tidak terlanjur ke 'submitted'.
        $result = $scorer->compute($assessment);

        DB::transaction(function () use ($assessment, $request, $tokens, $result) {
            // Persist skor + risk_level + rekomendasi sebelum markConsumed,
            // supaya audit trail di markConsumed bisa reflect state final.
            $assessment->forceFill([
                'score' => (int) round($result['score']),
                'risk_level' => $result['risk_level'],
                'recommendations' => $result['recommendations'],
                'score_breakdown' => [
                    'total_aktif' => $result['total_aktif'],
                    'jawab_ya' => $result['jawab_ya'],
                    'jawab_tidak' => $result['jawab_tidak'],
                    'jawab_kosong' => $result['jawab_kosong'],
                    'score' => $result['score'],
                    'version' => $result['version'],
                ],
                'questionnaire_version' => $result['version'],
                'source' => VendorAssessment::SOURCE_DETERMINISTIC,
            ])->save();

            // Sync skor & risk_level ke vendor record supaya muncul di TPRM
            // list table. Frontend tampilkan vendor.risk_score (bukan
            // assessment.score) sebagai cache value. Tanpa sync ini, vendor
            // table tampil 0 padahal asesmen sudah dapat nilai final.
            $vendor = Vendor::find($assessment->vendor_id);
            if ($vendor) {
                $vendor->forceFill([
                    'risk_score' => (int) round($result['score']),
                    'risk_level' => $result['risk_level'],
                    'last_assessed_at' => now(),
                ])->save();
            }

            $tokens->markConsumed($assessment, $request);
        });

        return response()->json([
            'message' => 'Asesmen berhasil dikirim. Terima kasih atas partisipasinya.',
            'data' => [
                'assessment_id' => $assessment->id,
                'submitted_at' => optional($assessment->fresh()->submitted_at)->toIso8601String(),
                'result_url' => url('/api/asesmen-publik/' . $token . '/result'),
            ],
        ]);
    }

    /**
     * GET /result — read-only page yang ditampilkan setelah submit.
     * Hanya boleh diakses kalau token sudah consumed; sebelum itu return 404
     * supaya pihak ketiga tidak bisa "intip" hasil sebelum submit.
     */
    public function result(Request $request, string $token)
    {
        /** @var VendorAssessment $assessment */
        $assessment = $request->get('_assessment');

        if ($assessment->token_consumed_at === null) {
            return response()->json([
                'error' => 'Asesmen belum dikirim. Halaman hasil belum tersedia.',
            ], 404);
        }

        $vendor = Vendor::withTrashed()->find($assessment->vendor_id);
        $org = Organization::find($assessment->org_id);

        // Sprint G.6 — expose score + risk_level + rekomendasi ke pihak ketiga.
        // Rationale: spec Step 4 hasil mengharuskan tampil skor, badge level
        // risiko, dan daftar rekomendasi (collapsible) supaya pihak ketiga
        // dapat insight aksi perbaikan. Tetap tidak expose breakdown internal.
        $breakdown = is_array($assessment->score_breakdown) ? $assessment->score_breakdown : [];

        return response()->json([
            'data' => [
                'assessment_id' => $assessment->id,
                'status' => $assessment->status,
                'submitted_at' => optional($assessment->submitted_at)->toIso8601String(),
                'vendor_name' => $vendor?->name,
                'organization' => $org ? [
                    'name' => $org->name ?? null,
                    'logo_url' => $org->logo_url ?? null,
                ] : null,
                'answer_count' => is_array($assessment->answers) ? count($assessment->answers) : 0,
                'score' => $assessment->score,
                'risk_level' => $assessment->risk_level,
                'recommendations' => is_array($assessment->recommendations) ? $assessment->recommendations : [],
                'summary' => [
                    'total_aktif' => $breakdown['total_aktif'] ?? null,
                    'jawab_ya' => $breakdown['jawab_ya'] ?? null,
                    'jawab_tidak' => $breakdown['jawab_tidak'] ?? null,
                    'jawab_kosong' => $breakdown['jawab_kosong'] ?? null,
                ],
                'message' => 'Asesmen Anda telah diterima dan akan ditinjau oleh tim terkait.',
            ],
        ]);
    }
}
