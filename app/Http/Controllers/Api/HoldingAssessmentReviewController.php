<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HoldingAssessmentEvidence;
use App\Models\HoldingAssessmentInstance;
use App\Models\Organization;
use App\Services\AiDocumentAnalyzer;
use App\Services\CreditService;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;

/**
 * Holding Compliance Assessment — Reviewer dashboard (holding-side).
 *
 * Reviewer holding meninjau assessment yang sudah di-submit anak perusahaan:
 * lihat jawaban + bukti per pertanyaan, jalankan AI document analysis atas bukti
 * (advisory — TIDAK mengubah skor), beri verdict/catatan per pertanyaan, lalu
 * approve / reject. Akses: root/superadmin atau holding/sub-holding admin.
 *
 * Pola AI analyze + credit gate + resolve path meniru TprmReviewController.
 */
class HoldingAssessmentReviewController extends Controller
{
    private function assertHoldingAccess(Request $request): void
    {
        $user = $request->user();
        $role = $user->role ?? null;
        if (in_array($role, ['root', 'superadmin'], true)) {
            return;
        }
        $org = $user->org_id ? Organization::find($user->org_id) : null;
        if (! $org || ! $org->isHolding()) {
            abort(403, 'Hanya holding / sub-holding admin yang dapat me-review Holding Assessment.');
        }
    }

    /**
     * Instance milik holding (BelongsToOrg sudah auto-scope ke org reviewer).
     */
    private function find(string $id): HoldingAssessmentInstance
    {
        return HoldingAssessmentInstance::findOrFail($id);
    }

    // ===================================================================
    // INBOX + DETAIL
    // ===================================================================

    public function inbox(Request $request)
    {
        $this->assertHoldingAccess($request);

        $rows = HoldingAssessmentInstance::query()
            ->whereIn('status', ['submitted', 'review_in_progress'])
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'title' => $i->title,
                'target_org_name' => $i->target_org_name,
                'regulation_code' => $i->regulation_code,
                'status' => $i->status,
                'overall_score' => $i->overall_score !== null ? (float) $i->overall_score : null,
                'compliance_level' => $i->compliance_level,
                'submitted_at' => $i->submitted_at,
                'reviewer_id' => $i->reviewer_id,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $id)
    {
        $this->assertHoldingAccess($request);
        $instance = $this->find($id);

        // Evidence aktif dikelompokkan per pertanyaan (source of truth = tabel).
        $evidenceByQuestion = HoldingAssessmentEvidence::query()
            ->where('instance_id', $instance->id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->get()
            ->groupBy('question_id')
            ->map(fn ($items) => $items->map(fn ($e) => [
                'id' => $e->id,
                'file_path' => $e->file_path,
                'original_name' => $e->original_name,
                'mime_type' => $e->mime_type,
                'file_size' => $e->file_size,
                'uploaded_at' => optional($e->created_at)->toIso8601String(),
                'uploaded_by_token' => (bool) $e->uploaded_by_token,
            ])->values());

        return response()->json([
            'data' => [
                'id' => $instance->id,
                'title' => $instance->title,
                'target_org_name' => $instance->target_org_name,
                'regulation_code' => $instance->regulation_code,
                'regulation_name' => $instance->regulation_name,
                'type' => $instance->type ?? 'normal',
                'maturity_level' => $instance->maturity_level,
                'status' => $instance->status,
                'overall_score' => $instance->overall_score !== null ? (float) $instance->overall_score : null,
                'compliance_level' => $instance->compliance_level,
                'submitted_at' => optional($instance->submitted_at)->toIso8601String(),
                'reviewer_id' => $instance->reviewer_id,
                'review_status' => $instance->review_status,
                'review_notes' => $instance->review_notes,
                'review_data' => $instance->review_data ?? [],
                'questions' => $instance->effectiveQuestions(),
                'answers' => $instance->answers ?? [],
                'ai_analyses' => $instance->ai_analyses ?? [],
                'evidence_by_question' => $evidenceByQuestion,
            ],
        ]);
    }

    public function start(Request $request, string $id)
    {
        $this->assertHoldingAccess($request);
        $instance = $this->find($id);

        if ($instance->status === 'submitted') {
            $instance->forceFill([
                'status' => 'review_in_progress',
                'reviewer_id' => $request->user()->id,
            ])->save();
            $this->audit($request, $instance, 'review_start', []);
        }

        return response()->json(['data' => ['status' => $instance->status, 'reviewer_id' => $instance->reviewer_id]]);
    }

    /**
     * Verdict/catatan reviewer per pertanyaan → review_data[qid].
     * Body: { question_id, verdict?: comply|partial|non_comply, note? }
     */
    public function reviewQuestion(Request $request, string $id)
    {
        $this->assertHoldingAccess($request);
        $instance = $this->find($id);

        $data = $request->validate([
            'question_id' => ['required', 'string', 'max:64'],
            'verdict' => ['nullable', 'in:comply,partial,non_comply,unsure'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        $review = is_array($instance->review_data) ? $instance->review_data : [];
        $review[$data['question_id']] = [
            'verdict' => $data['verdict'] ?? ($review[$data['question_id']]['verdict'] ?? null),
            'note' => $data['note'] ?? ($review[$data['question_id']]['note'] ?? null),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now()->toIso8601String(),
        ];
        $instance->forceFill(['review_data' => $review])->save();

        return response()->json(['data' => $review[$data['question_id']]]);
    }

    // ===================================================================
    // AI EVIDENCE ANALYSIS (advisory — tidak mengubah skor)
    // ===================================================================

    public function analyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $this->assertHoldingAccess($request);

        $request->validate([
            'question_id' => 'required|string|max:64',
            'attachment_path' => 'required|string|max:1024',
        ]);

        $instance = $this->find($id);
        $qId = $request->input('question_id');
        $path = $request->input('attachment_path');

        $question = collect($instance->effectiveQuestions())->firstWhere('id', $qId);
        if (! $question) {
            return response()->json(['message' => 'Pertanyaan tidak ditemukan.'], 404);
        }

        // Pastikan bukti memang milik pertanyaan ini (anti-tamper).
        $belongs = HoldingAssessmentEvidence::query()
            ->where('instance_id', $instance->id)
            ->where('question_id', $qId)
            ->where('file_path', $path)
            ->where('is_active', true)
            ->exists();
        if (! $belongs) {
            return response()->json(['message' => 'Lampiran tidak ditemukan pada pertanyaan ini.'], 404);
        }

        $localPath = $this->resolveAttachmentPath($instance, $path);
        if (! $localPath || ! is_file($localPath)) {
            return response()->json(['message' => 'File tidak ditemukan pada penyimpanan.'], 404);
        }

        $orgId = $instance->org_id;
        if ($orgId) {
            CreditService::resetIfNeeded($orgId);
            if (! CreditService::hasCredit($orgId, 'ai_doc_analyze')) {
                $cost = CreditService::getCost('ai_doc_analyze');

                return response()->json([
                    'message' => "Kredit AI Anda habis. Dibutuhkan {$cost} kredit untuk analisis ini.",
                    'credits_exhausted' => true,
                ], 402);
            }
        }

        $result = $analyzer->analyze(
            documentPath: $localPath,
            question: $this->questionTextFor($question),
            regulationRef: (string) ($question['regulation_ref'] ?? ''),
            orgId: $orgId,
        );

        $newEntry = array_merge($result->toArray(), [
            'analyzed_at' => now()->toIso8601String(),
            'attachment_path' => $path,
        ]);

        // ai_analyses[qId] = array (satu entri per attachment); replace kalau path sama.
        $analyses = is_array($instance->ai_analyses) ? $instance->ai_analyses : [];
        $list = $this->normalizeList($analyses[$qId] ?? null);
        $found = false;
        foreach ($list as $i => $item) {
            if (($item['attachment_path'] ?? null) === $path) {
                $list[$i] = $newEntry;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $list[] = $newEntry;
        }
        $analyses[$qId] = $list;
        $instance->forceFill(['ai_analyses' => $analyses])->save();

        $this->audit($request, $instance, 'evidence_analyzed', [
            'question_id' => $qId,
            'status' => $newEntry['status'] ?? null,
        ]);

        return response()->json($newEntry);
    }

    /**
     * Bulk: analisis semua bukti aktif yang belum dianalisis. Skip image
     * (OCR belum didukung) dan pasangan yang sudah ada di ai_analyses.
     */
    public function bulkAnalyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $this->assertHoldingAccess($request);
        $instance = $this->find($id);
        $orgId = $instance->org_id;

        $questionMap = collect($instance->effectiveQuestions())->keyBy('id');
        $analyses = is_array($instance->ai_analyses) ? $instance->ai_analyses : [];
        $stats = ['analyzed' => 0, 'cached' => 0, 'skipped' => 0, 'failed' => 0];
        $creditsExhausted = false;
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

        $evidence = HoldingAssessmentEvidence::query()
            ->where('instance_id', $instance->id)
            ->where('is_active', true)
            ->get();

        foreach ($evidence as $ev) {
            $qId = $ev->question_id;
            $question = $questionMap->get($qId);
            if (! $question) {
                $stats['skipped']++;
                continue;
            }
            $existingList = $this->normalizeList($analyses[$qId] ?? null);
            $already = collect($existingList)->firstWhere('attachment_path', $ev->file_path);
            if ($already) {
                $stats['cached']++;
                continue;
            }
            $ext = strtolower(pathinfo($ev->file_path, PATHINFO_EXTENSION));
            if (in_array($ext, $imageExts, true)) {
                $stats['skipped']++;
                continue;
            }
            if ($orgId) {
                CreditService::resetIfNeeded($orgId);
                if (! CreditService::hasCredit($orgId, 'ai_doc_analyze')) {
                    $creditsExhausted = true;
                    break;
                }
            }
            $localPath = $this->resolveAttachmentPath($instance, $ev->file_path);
            if (! $localPath || ! is_file($localPath)) {
                $stats['failed']++;
                continue;
            }
            try {
                $result = $analyzer->analyze(
                    documentPath: $localPath,
                    question: $this->questionTextFor($question),
                    regulationRef: (string) ($question['regulation_ref'] ?? ''),
                    orgId: $orgId,
                );
                $existingList[] = array_merge($result->toArray(), [
                    'analyzed_at' => now()->toIso8601String(),
                    'attachment_path' => $ev->file_path,
                ]);
                $analyses[$qId] = $existingList;
                $stats['analyzed']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
            }
        }

        $instance->forceFill(['ai_analyses' => $analyses])->save();
        $this->audit($request, $instance, 'evidence_analyzed_bulk', $stats);

        return response()->json(['data' => $stats, 'credits_exhausted' => $creditsExhausted]);
    }

    // ===================================================================
    // FINALIZE
    // ===================================================================

    /**
     * Body: { decision: approved|rejected|needs_revision, notes? }
     */
    public function finalize(Request $request, string $id)
    {
        $this->assertHoldingAccess($request);
        $instance = $this->find($id);

        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected,needs_revision'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        $statusMap = [
            'approved' => 'approved',
            'rejected' => 'rejected',
            'needs_revision' => 'review_in_progress',
        ];

        $instance->forceFill([
            'review_status' => $data['decision'],
            'review_notes' => $data['notes'] ?? null,
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
            'status' => $statusMap[$data['decision']],
        ])->save();

        $this->audit($request, $instance, 'review_finalize', ['decision' => $data['decision']]);

        return response()->json([
            'message' => 'Review disimpan.',
            'data' => ['status' => $instance->status, 'review_status' => $instance->review_status],
        ]);
    }

    // ===================================================================
    // Helpers
    // ===================================================================

    private function questionTextFor(array $question): string
    {
        $parts = [$question['question'] ?? ''];
        if (! empty($question['explanation'])) {
            $parts[] = 'Konteks: '.$question['explanation'];
        }

        return trim(implode("\n", array_filter($parts)));
    }

    private function normalizeList(mixed $value): array
    {
        if (empty($value) || ! is_array($value)) {
            return [];
        }

        return isset($value['status']) ? [$value] : array_values($value);
    }

    /**
     * Resolve relative path → absolute filesystem path. Mirror TPRM:
     * coba local disk dulu, lalu tenant disk (download ke temp file supaya
     * PdfParser/PhpWord/PhpSpreadsheet bisa baca).
     */
    private function resolveAttachmentPath(HoldingAssessmentInstance $instance, string $relativePath): ?string
    {
        $rel = ltrim($relativePath, '/');

        foreach ([
            storage_path('app/public/'.$rel),
            storage_path('app/private/'.$rel),
            storage_path('app/'.$rel),
        ] as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        try {
            $org = Organization::find($instance->org_id);
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
            $tmpPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'holding_evidence_'.substr(hash('sha256', $rel), 0, 16).'.'.$ext;
            if (file_put_contents($tmpPath, $contents) === false) {
                return null;
            }

            return $tmpPath;
        } catch (\Throwable $e) {
            \Log::warning('[Holding resolveAttachmentPath] tenant disk fetch failed', [
                'org_id' => $instance->org_id,
                'path' => $rel,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function audit(Request $request, HoldingAssessmentInstance $instance, string $action, array $changes): void
    {
        AuditLog::create([
            'org_id' => $instance->org_id,
            'module' => 'holding_assessment.review',
            'record_id' => $instance->id,
            'action' => $action,
            'user_id' => $request->user()->id ?? null,
            'user_name' => $request->user()->name ?? null,
            'user_role' => 'reviewer',
            'section' => 'holding_assessment',
            'changes' => $changes,
            'ip_address' => $request->ip(),
        ]);
    }
}
