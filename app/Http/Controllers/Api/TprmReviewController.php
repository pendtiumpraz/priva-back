<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Models\VendorAssessmentAdjustment;
use App\Models\VendorAssessmentEvidence;
use App\Models\VendorQuestionnaire;
use App\Services\AiDocumentAnalyzer;
use App\Services\CreditService;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use App\Services\ThirdPartyAssessmentScorer;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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

        // Provenance display: resolve nama user pelaku adjustment supaya FE
        // bisa render badge "Manual — disesuaikan {nama} ({role})" (tabel
        // adjustment menyimpan user_id + role + timestamp + before/after).
        $adjusterNames = User::query()
            ->whereIn('id', $adjustments->pluck('adjusted_by_user_id')->filter()->unique())
            ->pluck('name', 'id');

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
                    // Hasil AI document analysis per pertanyaan — ADVISORY
                    // ONLY (tidak pernah masuk scorer). FE render badge +
                    // accordion per attachment dari sini.
                    'ai_analyses' => $assessment->ai_analyses ?? (object) [],
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
                    // file_path dibutuhkan FE sebagai attachment_path untuk
                    // POST /analyze-evidence (internal-only, authed).
                    'file_path' => $e->file_path,
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
                    'adjusted_by_name' => $adjusterNames[$a->adjusted_by_user_id] ?? null,
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

        // Recompute score setelah semua adjustment. compute() sudah dual-path:
        // honor library_id assessment kalau terisi, else effectiveForOrg legacy.
        // (Sebelumnya memanggil method score() yang tidak ada → recompute
        // selalu silently skip via catch.)
        try {
            $result = app(ThirdPartyAssessmentScorer::class)->compute($assessment);
            $scoreUpdate = [
                'score' => (int) round($result['score'] ?? $assessment->score),
                'risk_level' => $result['risk_level'] ?? $assessment->risk_level,
                'recommendations' => $result['recommendations'] ?? $assessment->recommendations,
                'score_breakdown' => [
                    'total_aktif' => $result['total_aktif'],
                    'jawab_ya' => $result['jawab_ya'],
                    'jawab_tidak' => $result['jawab_tidak'],
                    'jawab_kosong' => $result['jawab_kosong'],
                    'score' => $result['score'],
                    'version' => $result['version'],
                ],
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
            'reviewer_note' => 'Dikembalikan ke vendor: '.$data['reason'],
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

    // =============================================
    // Per-question Evidence Upload (internal) + AI Analysis
    // (parity dgn GAP/Maturity/TIA — blueprint TiaController)
    // =============================================
    //
    // Catatan keamanan (LOCKED DECISION): endpoint analisis AI HANYA hidup
    // di sini, di belakang auth:sanctum + permission:vendor_risk,write.
    // JANGAN pernah expose ke group public /asesmen-publik/* — public user
    // tidak boleh bisa membakar kredit AI org.

    /**
     * POST /tprm/review/{id}/upload-evidence — upload bukti per pertanyaan
     * oleh user INTERNAL (supplement terhadap bukti yang dikirim pihak
     * ketiga via public token flow).
     *
     * Storage shape mirror AsesmenPublikController::uploadEvidence PERSIS:
     *   answers[question_id].evidence[] = { id, path, driver, original_name,
     *   size, mime, uploaded_at } + row vendor_assessment_evidence
     *   (uploaded_by_user_id terisi, uploaded_by_token=false) — supaya
     *   reviewer UI & analyzer melihat kedua sumber secara seragam.
     *
     * Lock semantics: upload ditolak 423 saat workflow_locked=true atau
     * status final (approved/rejected). Status draft/sent/submitted/
     * review_in_progress/pending_approval boleh upload (mirror adjust()
     * yang juga masih boleh menulis sampai final).
     */
    public function uploadEvidence(Request $request, string $id, TenantStorageService $storage, FileUploadValidator $validator)
    {
        // Kalau body request melebihi `post_max_size` PHP, $_POST + $_FILES
        // dibuang dan request seolah kosong — tanpa pesan jelas. Cek
        // CONTENT_LENGTH manual supaya error message-nya actionable.
        $contentLength = (int) $request->server('CONTENT_LENGTH');
        $postMax = $this->bytesFromIni((string) ini_get('post_max_size'));
        if ($postMax > 0 && $contentLength > $postMax) {
            return response()->json([
                'message' => sprintf(
                    'Ukuran upload (%s) melebihi batas server PHP post_max_size (%s). Minta admin naikkan post_max_size & upload_max_filesize di php.ini hosting.',
                    $this->humanBytes($contentLength),
                    ini_get('post_max_size'),
                ),
            ], 413);
        }

        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if ($assessment->workflow_locked || $assessment->isFinal()) {
            return response()->json([
                'message' => "Assessment sudah final / terkunci (status={$assessment->status}). Bukti tidak dapat ditambahkan lagi.",
            ], 423);
        }

        $request->validate([
            'question_id' => 'required|string|max:64',
            'file' => 'required|file|max:10240', // 10MB
        ]);

        // Validasi question_id terhadap set pertanyaan EFEKTIF assessment ini
        // (library kalau library_id terisi, else effectiveForOrg legacy).
        $qId = $request->input('question_id');
        $question = $this->questionsForAssessment($assessment)->get($qId);
        if (! $question) {
            return response()->json([
                'message' => 'Pertanyaan tidak ditemukan di kuesioner assessment ini.',
            ], 422);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        // Mirror whitelist + preset public uploadEvidence supaya format bukti
        // konsisten antara jalur public token dan jalur internal.
        $allowed = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
        if (! in_array($ext, $allowed, true)) {
            return response()->json([
                'message' => 'Format file tidak diizinkan. Hanya PDF, DOCX, JPG, atau PNG.',
            ], 422);
        }

        try {
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
                "tprm/assessments/{$assessment->id}/evidence"
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Gagal menyimpan file ke storage: '.$e->getMessage(),
            ], 500);
        }

        if (empty($result['path'])) {
            return response()->json([
                'message' => 'Gagal menyimpan file (path kosong). Periksa konfigurasi storage.',
            ], 500);
        }

        // JSON-embed — shape PERSIS sama dengan public flow.
        $answers = is_array($assessment->answers) ? $assessment->answers : [];
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

        // Mirror ke tabel vendor_assessment_evidence (source of truth untuk
        // Reviewer/Approver UI) — uploaded_by_user_id terisi (internal).
        try {
            VendorAssessmentEvidence::create([
                'id' => $entry['id'],
                'org_id' => $assessment->org_id,
                'assessment_id' => $assessment->id,
                'question_id' => $qId,
                'file_path' => $entry['path'],
                'original_name' => $entry['original_name'],
                'mime_type' => $entry['mime'],
                'file_size' => $entry['size'] ?? 0,
                'uploaded_by_user_id' => $request->user()->id,
                'uploaded_by_token' => false,
                'uploaded_ip' => $request->ip(),
                'is_active' => true,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('TPRM internal evidence mirror to table failed: '.$e->getMessage(), [
                'assessment_id' => $assessment->id,
                'question_id' => $qId,
            ]);
        }

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reviewer',
            'module' => 'tprm.review',
            'action' => 'evidence_uploaded',
            'record_id' => $assessment->id,
            'changes' => [
                'question_id' => $qId,
                'name' => $entry['original_name'],
            ],
        ]);

        return response()->json([
            'message' => 'Bukti berhasil diunggah.',
            // `name` + `url` extra untuk komponen FE EvidenceUpload (file
            // private — tidak ada public URL, FE render nama saja).
            'data' => array_merge($entry, [
                'name' => $entry['original_name'],
                'url' => null,
            ]),
            'evidence' => $answers[$qId]['evidence'],
        ], 201);
    }

    /**
     * GET /tprm/review/{id}/evidence/{evidenceId} — stream/unduh satu berkas
     * bukti asesmen TPRM.
     *
     * Berkas bukti disimpan di storage PRIVAT per-tenant
     * (TenantStorageService::storeTenantPrivateFile → tenants/{org}/...),
     * jadi tidak ada URL publik. Pola otorisasi mengikuti
     * DsrExecutionController::streamEvidence: resolve parent record dalam org
     * pemanggil dulu, baru resolve berkas anaknya dalam parent + org yang sama.
     *
     * Tiga lapis pengaman lintas-tenant:
     *   1. assessment harus milik org pemanggil (findInOrg → 404 kalau bukan);
     *   2. row evidence harus milik assessment ITU dan org yang sama
     *      (global scope org di-bypass, filter eksplisit — id yang ditebak
     *      dari org lain jatuh ke 404 yang sama dengan id tak dikenal);
     *   3. file_path wajib berada di bawah prefix `tenants/{org_id}/` supaya
     *      row yang datanya korup/ter-tamper tidak bisa membaca berkas tenant
     *      lain di disk yang sama.
     */
    public function downloadEvidence(Request $request, string $id, string $evidenceId, TenantStorageService $storage)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        $evidence = VendorAssessmentEvidence::query()
            ->withoutGlobalScope('org')
            ->where('id', $evidenceId)
            ->where('assessment_id', $assessment->id)
            ->where('org_id', $assessment->org_id)
            ->firstOrFail();

        $path = (string) $evidence->file_path;
        if ($path === '' || ! str_starts_with($path, "tenants/{$assessment->org_id}/")) {
            abort(404, 'Berkas bukti tidak ditemukan.');
        }

        $org = Organization::findOrFail($assessment->org_id);
        $disk = $storage->getDisk($org);
        if (! $disk->exists($path)) {
            abort(404, 'Berkas bukti tidak ditemukan pada penyimpanan.');
        }

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reviewer',
            'module' => 'tprm.review',
            'action' => 'evidence_downloaded',
            'record_id' => $assessment->id,
            'changes' => [
                'evidence_id' => $evidence->id,
                'question_id' => $evidence->question_id,
                'name' => $evidence->original_name,
            ],
            'ip_address' => $request->ip(),
        ]);

        return $disk->download($path, $evidence->original_name ?: basename($path));
    }

    /**
     * POST /tprm/review/{id}/analyze-evidence — AI document analysis untuk
     * satu (question_id, attachment_path). INTERNAL ONLY (auth:sanctum).
     *
     * Bekerja untuk evidence dari KEDUA jalur (public token flow + upload
     * internal) karena storage shape-nya sama: attachment_path dicocokkan
     * ke answers[qid].evidence[] dengan fallback tabel
     * vendor_assessment_evidence.
     *
     * Teks "pertanyaan" untuk analyzer di-compose dari pertanyaan efektif:
     *   "Pertanyaan TPRM ({section}): {question_text}. {description}"
     * regulation_ref pertanyaan diteruskan sebagai regulationRef.
     *
     * Hasil disimpan ke ai_analyses[question_id][] keyed attachment_path.
     * 1 kredit per analisis (deduct di AiDocumentAnalyzer untuk panggilan
     * sukses non-cache); 402 kalau kredit habis. Verdict ADVISORY ONLY —
     * TIDAK menyentuh ThirdPartyAssessmentScorer / skor assessment.
     */
    public function analyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $request->validate([
            'question_id' => 'required|string|max:64',
            'attachment_path' => 'required|string|max:1024',
        ]);

        $assessment = $this->findInOrg($id, $request->user()->org_id);

        $qId = $request->input('question_id');
        $question = $this->questionsForAssessment($assessment)->get($qId);
        if (! $question) {
            return response()->json(['message' => 'Pertanyaan tidak ditemukan.'], 404);
        }

        // Verifikasi attachment_path memang milik pertanyaan ini — defence
        // against tampering. Layer 1: JSON embed; layer 2: evidence table.
        if (! $this->attachmentBelongsToQuestion($assessment, $qId, $request->attachment_path)) {
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
            question: $this->questionTextFor($question),
            regulationRef: (string) ($question->regulation_ref ?? ''),
            orgId: $orgId,
        );

        $newEntry = array_merge($result->toArray(), [
            'analyzed_at' => now()->toIso8601String(),
            'attachment_path' => $request->attachment_path,
        ]);

        // ai_analyses[qId] = ARRAY (satu entri per attachment).
        $analyses = $assessment->ai_analyses ?? [];
        $listForQ = $this->normalizeAnalysesForQuestion($analyses[$qId] ?? null);
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
        $analyses[$qId] = $listForQ;

        // Advisory only — TANPA recompute scorer; skor assessment tidak berubah.
        $assessment->forceFill(['ai_analyses' => $analyses])->save();

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reviewer',
            'module' => 'tprm.review',
            'action' => 'evidence_analyzed',
            'record_id' => $assessment->id,
            'changes' => [
                'question_id' => $qId,
                'attachment_path' => $request->attachment_path,
                'status' => $newEntry['status'] ?? null,
            ],
        ]);

        return response()->json($newEntry);
    }

    /**
     * POST /tprm/review/{id}/analyze-evidence-bulk — mirror
     * TiaController::bulkAnalyzeEvidence.
     *
     * Iterasi setiap (question, attachment) dari answers[qid].evidence[].
     * Skip: pertanyaan tak dikenal, pasangan yang sudah cached di
     * ai_analyses (no re-charge), dan file gambar (OCR belum didukung).
     * Stop early kalau kredit habis — return stats + flag credits_exhausted.
     */
    public function bulkAnalyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);
        $orgId = $request->user()->org_id;

        $questionMap = $this->questionsForAssessment($assessment);

        $answers = is_array($assessment->answers) ? $assessment->answers : [];
        $existing = $assessment->ai_analyses ?? [];
        $newAnalyses = $existing;

        $stats = ['analyzed' => 0, 'cached' => 0, 'skipped' => 0, 'failed' => 0];
        $creditsExhausted = false;
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

        foreach ($answers as $qId => $answer) {
            $files = is_array($answer) ? ($answer['evidence'] ?? []) : [];
            if (empty($files) || ! is_array($files)) {
                continue;
            }

            $question = $questionMap->get($qId);
            if (! $question) {
                $stats['skipped'] += count($files);

                continue;
            }

            $prevList = $this->normalizeAnalysesForQuestion($existing[$qId] ?? null);
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

                // Cache hit: skip kalau hasil analysis sudah ada untuk attachment sama.
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
                        question: $this->questionTextFor($question),
                        regulationRef: (string) ($question->regulation_ref ?? ''),
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
                $newAnalyses[$qId] = $newListForQ;
            }
        }

        // Advisory only — TANPA recompute scorer.
        $assessment->forceFill(['ai_analyses' => $newAnalyses])->save();

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
     * Set pertanyaan EFEKTIF untuk satu assessment, keyed by question id.
     * Dual path mirror doShow(): library_id (TPRM Phase 1+) atau legacy
     * effectiveForOrg + filter versi scorer.
     */
    private function questionsForAssessment(VendorAssessment $assessment): Collection
    {
        if (! empty($assessment->library_id)) {
            return VendorQuestionnaire::query()
                ->withoutGlobalScope('org')
                ->where('library_id', $assessment->library_id)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');
        }

        return VendorQuestionnaire::effectiveForOrg($assessment->org_id)
            ->filter(fn ($q) => $q->is_active && $q->version === ThirdPartyAssessmentScorer::VERSION)
            ->keyBy('id');
    }

    /**
     * Compose teks "pertanyaan" untuk AiDocumentAnalyzer:
     * "Pertanyaan TPRM ({section}): {question_text}. {description}".
     */
    private function questionTextFor(VendorQuestionnaire $q): string
    {
        $section = trim((string) ($q->section ?? ''));
        $text = 'Pertanyaan TPRM'.($section !== '' ? " ({$section})" : '').': '.$q->question_text;
        if (! empty($q->description)) {
            $text .= '. '.$q->description;
        }

        return $text;
    }

    /**
     * Cek attachment_path memang ter-attach ke pertanyaan ini. Layer 1:
     * answers[qid].evidence[] (JSON embed — ditulis baik oleh public token
     * flow maupun upload internal). Layer 2: tabel vendor_assessment_evidence
     * (fallback untuk row lama yang JSON embed-nya hilang).
     */
    private function attachmentBelongsToQuestion(VendorAssessment $assessment, string $qId, string $attachmentPath): bool
    {
        $answers = is_array($assessment->answers) ? $assessment->answers : [];
        $files = is_array($answers[$qId] ?? null) ? ($answers[$qId]['evidence'] ?? []) : [];
        foreach ((array) $files as $att) {
            $path = is_array($att) ? ($att['path'] ?? null) : $att;
            if ($path === $attachmentPath) {
                return true;
            }
        }

        return VendorAssessmentEvidence::query()
            ->withoutGlobalScope('org')
            ->where('assessment_id', $assessment->id)
            ->where('question_id', $qId)
            ->where('file_path', $attachmentPath)
            ->exists();
    }

    /**
     * Normalisasi nilai ai_analyses[qId] ke array entries.
     * Format lama: object tunggal { status, analysis, attachment_path, ... }
     * Format baru: array [ { ... }, { ... } ]
     */
    private function normalizeAnalysesForQuestion(mixed $value): array
    {
        if (empty($value) || ! is_array($value)) {
            return [];
        }
        if (isset($value['status'])) {
            return [$value];
        }

        return array_values($value);
    }

    /** Convert PHP ini shorthand (50M, 8K, 2G) ke byte integer. */
    private function bytesFromIni(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
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
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).'MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).'KB';
        }

        return $bytes.'B';
    }

    /**
     * Resolve attachment relative path ke absolute filesystem path (mirror
     * TiaController::resolveAttachmentPath). Layer 1: local public/app
     * disk; Layer 2: tenant disk (S3/GCS/local default) → download ke temp
     * file supaya analyzer (PdfParser/PhpWord/PhpSpreadsheet) bisa baca.
     * Evidence TPRM disimpan via storeTenantPrivateFile sehingga layer 2
     * adalah jalur utama.
     */
    private function resolveAttachmentPath(VendorAssessment $assessment, string $relativePath): ?string
    {
        $rel = ltrim($relativePath, '/');

        $localCandidates = [
            storage_path('app/public/'.$rel),
            storage_path('app/private/'.$rel),
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
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.'tprm_evidence_'.substr(hash('sha256', $rel), 0, 16).'.'.$ext;
            if (file_put_contents($tmpPath, $contents) === false) {
                return null;
            }

            return $tmpPath;
        } catch (\Throwable $e) {
            \Log::warning('[TPRM resolveAttachmentPath] tenant disk fetch failed', [
                'org_id' => $assessment->org_id,
                'path' => $rel,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
