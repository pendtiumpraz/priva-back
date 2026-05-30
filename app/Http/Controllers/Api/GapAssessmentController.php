<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GapAssessment;
use App\Models\CustomGapQuestion;
use App\Models\Organization;
use App\Services\AiDocumentAnalyzer;
use App\Services\CreditService;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use RuntimeException;

class GapAssessmentController extends Controller
{
    /**
     * List all assessments (with soft-deleted in trash)
     */
    public function index(Request $request)
    {
        $query = GapAssessment::where('org_id', $request->user()->org_id);

        if ($request->get('trash')) {
            $query->onlyTrashed();
        }
        if ($request->get('regulation')) {
            $reg = $request->get('regulation');
            if ($reg === 'uupdp') {
                // Include legacy records with NULL regulation_code
                $query->where(function($q) use ($reg) {
                    $q->where('regulation_code', $reg)->orWhereNull('regulation_code');
                });
            } else {
                $query->where('regulation_code', $reg);
            }
        }

        $assessments = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $assessments,
            'regulations' => \App\Models\RegulationFramework::where('is_active', true)->get()
        ]);
    }

    /**
     * Get list of active regulations
     */
    public function getRegulations()
    {
        return response()->json([
            'data' => \App\Models\RegulationFramework::where('is_active', true)->get()
        ]);
    }

    /**
     * Compare historical assessments for a specific regulation side-by-side
     */
    public function compare(Request $request)
    {
        $regCode = $request->query('regulation', 'uupdp');
        $ids = $request->query('ids');

        if (!$ids) {
            return response()->json(['error' => 'ids required'], 400);
        }

        $idList = explode(',', $ids);
        $assessments = GapAssessment::where('org_id', $request->user()->org_id)
            ->whereIn('id', $idList)
            ->orderBy('created_at')
            ->get();

        $questions = GapAssessment::getQuestionBank($regCode);
        $categories = array_values(array_unique(array_column($questions, 'category')));

        $results = [];
        foreach ($categories as $cat) {
            $row = ['category' => $cat];
            foreach ($assessments as $assessment) {
                $calc = GapAssessment::calculateScore($assessment->answers ?: [], $regCode);
                $breakdown = $calc['category_breakdown'];
                $row[$assessment->version] = $breakdown[$cat] ?? 0;
            }
            $results[] = $row;
        }

        return response()->json([
            'versions' => $assessments->pluck('version'),
            'data' => $results
        ]);
    }

    /**
     * Get the question bank
     */
    public function questions(Request $request)
    {
        $code = $request->query('regulation', 'uupdp');
        $questions = GapAssessment::getQuestionBank($code);

        // Merge custom questions from this org
        $orgId = $request->user()->org_id;
        if ($orgId) {
            $customQuestions = CustomGapQuestion::forOrg($orgId)
                ->forRegulation($code)
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->map(fn($q) => $q->toQuestionFormat())
                ->toArray();
            $questions = array_merge($questions, $customQuestions);
        }

        // Group by category
        $grouped = [];
        foreach ($questions as $q) {
            $grouped[$q['category']][] = $q;
        }

        return response()->json([
            'questions' => $questions,
            'grouped' => $grouped,
            'total' => count($questions),
            'categories' => array_keys($grouped),
        ]);
    }

    /**
     * Start new assessment
     */
    public function store(Request $request)
    {
        $code = $request->query('regulation', 'uupdp');

        // GUARD: if there is an UNFINALIZED assessment (any regulation),
        // user must finalize / hapus dulu sebelum mulai baru. Sekarang gate
        // pakai finalized_at — sebelumnya pakai progress < 100, tapi itu
        // bocor: assessment saved (progress 100 tapi belum diklik Selesai)
        // dianggap "selesai" oleh guard, padahal user masih edit-mode.
        $unfinished = GapAssessment::where('org_id', $request->user()->org_id)
            ->whereNull('finalized_at')
            ->orderBy('created_at', 'desc')
            ->first();
        if ($unfinished) {
            return response()->json([
                'message' => 'Assessment "' . ($unfinished->version ?? 'sebelumnya') . '" belum selesai (progress ' . (int) ($unfinished->progress ?? 0) . '%). Selesaikan atau hapus sebelum membuat assessment baru.',
                'unfinished' => [
                    'id' => $unfinished->id,
                    'version' => $unfinished->version,
                    'progress' => (int) ($unfinished->progress ?? 0),
                    'created_at' => $unfinished->created_at,
                ],
            ], 409);
        }

        // Check cooldown (90 days)
        $lastAssessment = GapAssessment::where('org_id', $request->user()->org_id)
            ->where('regulation_code', $code)
            ->orderBy('created_at', 'desc')
            ->first();

        $cooldownWarning = null;
        if ($lastAssessment) {
            $daysSince = (int) $lastAssessment->created_at->diffInDays(now());
            if ($daysSince < 90) {
                $timeString = $daysSince === 0 ? 'hari ini' : "{$daysSince} hari yang lalu";
                $nextDue = $lastAssessment->created_at->addDays(90)->format('d M Y');
                $cooldownWarning = "Assessment terakhir baru dilakukan {$timeString}. Assessment berikutnya disarankan pada {$nextDue}.";
            }
        }

        $lastVersion = GapAssessment::where('org_id', $request->user()->org_id)
            ->where('regulation_code', $code)
            ->withTrashed()
            ->count();

        $assessment = GapAssessment::create([
            'org_id' => $request->user()->org_id,
            'regulation_code' => $code,
            'version' => 'GAP_v3.0_' . strtoupper($code) . '_#' . ($lastVersion + 1),
            'overall_score' => 0,
            'compliance_level' => 'low',
            'progress' => 0,
            'answers' => [],
            'attachments' => [],
            'recommendations' => [],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Assessment created',
            'data' => $assessment,
            'cooldown_warning' => $cooldownWarning,
        ], 201);
    }

    /**
     * Duplicate completed assessment — bikin assessment baru editable
     * dengan answers dari source. Source HARUS sudah complete (progress
     * 100) dan tidak deleted. Sama seperti store(), kalau ada assessment
     * unfinished yang lain → 409 (user harus selesaikan dulu).
     */
    public function duplicate(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;

        $unfinished = GapAssessment::where('org_id', $orgId)
            ->whereNull('finalized_at')
            ->orderBy('created_at', 'desc')
            ->first();
        if ($unfinished) {
            return response()->json([
                'message' => 'Assessment "' . ($unfinished->version ?? 'sebelumnya') . '" belum selesai (progress ' . (int) ($unfinished->progress ?? 0) . '%). Selesaikan atau hapus dulu sebelum duplikasi.',
                'unfinished' => [
                    'id' => $unfinished->id,
                    'version' => $unfinished->version,
                    'progress' => (int) ($unfinished->progress ?? 0),
                ],
            ], 409);
        }

        $source = GapAssessment::where('org_id', $orgId)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();
        if (! $source) {
            return response()->json(['message' => 'Assessment sumber tidak ditemukan atau sudah dihapus.'], 404);
        }
        // Source harus sudah FINALIZED (bukan sekadar progress 100%).
        if ($source->finalized_at === null) {
            return response()->json([
                'message' => 'Hanya assessment yang sudah diklik "Selesaikan" yang bisa diduplikasi.',
            ], 422);
        }

        $code = $source->regulation_code ?? 'uupdp';
        $lastVersion = GapAssessment::where('org_id', $orgId)
            ->where('regulation_code', $code)
            ->withTrashed()
            ->count();

        $assessment = GapAssessment::create([
            'org_id' => $orgId,
            'regulation_code' => $code,
            'version' => 'GAP_v3.0_' . strtoupper($code) . '_#' . ($lastVersion + 1) . ' (dup)',
            'overall_score' => 0,
            'compliance_level' => 'low',
            // Force progress=0 supaya frontend treat sebagai editable
            // walaupun semua answers ada — wizard akan terbuka.
            'progress' => 0,
            'answers' => $source->answers ?? [],
            // Attachments tidak ikut di-copy karena tied ke file fisik
            // tertentu di storage source; user bisa upload ulang per question.
            'attachments' => [],
            'recommendations' => [],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Assessment berhasil diduplikasi dari ' . $source->version,
            'data' => $assessment,
            'source_id' => $source->id,
            'source_version' => $source->version,
        ], 201);
    }

    /**
     * Get assessment detail
     */
    public function show(Request $request, string $id)
    {
        $assessment = GapAssessment::withTrashed()->findOrFail($id);
        $code = $assessment->regulation_code ?? 'uupdp';
        $questions = GapAssessment::getQuestionBank($code);

        // Merge custom questions
        $orgId = $request->user()->org_id;
        if ($orgId) {
            $custom = CustomGapQuestion::forOrg($orgId)->forRegulation($code)->active()->orderBy('sort_order')->get();
            $questions = array_merge($questions, $custom->map(fn($q) => $q->toQuestionFormat())->toArray());
        }

        return response()->json([
            'data' => $assessment,
            'questions' => $questions,
        ]);
    }

    /**
     * Submit/update answers — auto-calculate score
     */
    public function submitAnswers(Request $request, string $id)
    {
        $request->validate([
            'answers' => 'required|array',
            'attachments' => 'nullable|array',
            // `finalize`: hanya true dari tombol "Selesaikan" (Finish). Save & Exit
            // mengirim false (atau tidak set), sehingga assessment tetap editable
            // walau semua jawaban sudah terisi (progress 100%).
            'finalize' => 'nullable|boolean',
        ]);

        $assessment = GapAssessment::findOrFail($id);
        $answers = $request->input('answers');
        $finalize = (bool) $request->input('finalize', false);

        // Pass ai_analyses supaya verdict AI (kalau ada) ikut menentukan skor
        // sebagai override jawaban user. Lihat aggregateAiVerdict() di model.
        $result = GapAssessment::calculateScore(
            $answers,
            $assessment->regulation_code ?? 'uupdp',
            $assessment->ai_analyses ?? []
        );

        $customCount = CustomGapQuestion::forOrg($assessment->org_id)->forRegulation($assessment->regulation_code ?? 'uupdp')->active()->count();
        $totalQuestions = count(GapAssessment::getQuestionBank($assessment->regulation_code ?? 'uupdp')) + $customCount;
        $answeredCount = count(array_filter($answers, fn($a) => $a !== null && $a !== ''));
        $progress = round(($answeredCount / $totalQuestions) * 100);

        $update = [
            'answers' => $answers,
            'overall_score' => $result['overall_score'],
            'compliance_level' => $result['compliance_level'],
            'progress' => $progress,
            'recommendations' => $result['recommendations'],
        ];

        // Attachments di-update HANYA kalau client kirim eksplisit. Sebelumnya
        // request tanpa key `attachments` default ke [] dan overwrite kolom
        // attachments di DB jadi kosong — bikin evidence yang sudah di-upload
        // hilang setelah Save. uploadEvidence sudah append attachment langsung
        // ke kolom, jadi submitAnswers default-nya tidak boleh sentuh.
        if ($request->has('attachments') && $request->input('attachments') !== null) {
            $update['attachments'] = $request->input('attachments', []);
        }

        // Hanya tombol "Selesaikan" yang mark assessment sebagai final
        // (set finalized_at). Tidak pernah un-finalize via endpoint ini —
        // user harus duplicate kalau mau edit lagi sesudah finalized.
        if ($finalize && $assessment->finalized_at === null) {
            $update['finalized_at'] = now();
        }

        $assessment->update($update);

        return response()->json([
            'message' => $finalize ? 'Assessment finalized.' : 'Answers saved.',
            'data' => $assessment->fresh(),
            'result' => $result,
        ]);
    }

    /**
     * Soft delete
     */
    public function destroy(string $id)
    {
        $assessment = GapAssessment::findOrFail($id);
        $assessment->delete();

        return response()->json(['message' => 'Assessment moved to trash']);
    }

    /**
     * Restore from trash
     */
    public function restore(string $id)
    {
        $assessment = GapAssessment::onlyTrashed()->findOrFail($id);
        $assessment->restore();

        return response()->json(['message' => 'Assessment restored', 'data' => $assessment]);
    }

    /**
     * Permanent delete
     */
    public function forceDelete(string $id, TenantStorageService $storage)
    {
        $assessment = GapAssessment::onlyTrashed()->findOrFail($id);
        $org = Organization::find($assessment->org_id);

        if ($assessment->attachments && $org) {
            $disk = $storage->getPublicDisk($org);
            foreach ($assessment->attachments as $questionPaths) {
                if (!is_array($questionPaths)) continue;
                foreach ($questionPaths as $att) {
                    $path = is_array($att) ? ($att['path'] ?? null) : $att;
                    if ($path) {
                        try { $disk->delete($path); } catch (\Throwable $e) { /* best-effort */ }
                    }
                }
            }
        }

        $assessment->forceDelete();

        return response()->json(['message' => 'Assessment permanently deleted']);
    }

    // =============================================
    // Evidence Upload (Sprint B3)
    // =============================================

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
            'question_id' => 'required|string',
            'file' => 'required|file|max:10240',
        ]);

        $assessment = GapAssessment::where('org_id', $request->user()->org_id)->findOrFail($id);
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
                "gap/{$assessment->id}/evidence"
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
        $qId = $request->question_id;

        if (!isset($attachments[$qId]) || !is_array($attachments[$qId])) {
            $attachments[$qId] = [];
        }

        $attachments[$qId][] = [
            'path' => $result['path'],
            'url' => $result['url'],
            'name' => $file->getClientOriginalName(),
            'driver' => $result['driver'],
            'uploaded_at' => now()->toIso8601String(),
        ];

        $assessment->update(['attachments' => $attachments]);

        return response()->json([
            'message' => 'Bukti berhasil diunggah',
            'data' => end($attachments[$qId]),
            'attachments' => $attachments,
        ]);
    }

    // =============================================
    // AI Evidence Analysis (Sprint G.9)
    // =============================================

    /**
     * Analyze an uploaded evidence file against the compliance question
     * using AiDocumentAnalyzer. Result is cached on the assessment row
     * (ai_analyses JSON, keyed by question_id) so it's returned together
     * with the assessment detail on next load.
     *
     * Charges 1 credit (ai_doc_analyze) via CreditService, deducted inside
     * AiDocumentAnalyzer on a successful, non-cached call. We gate the
     * endpoint with hasCredit() up-front so the operator sees the formal
     * "kredit habis" message before the worker starts text extraction.
     */
    public function analyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $request->validate([
            'question_id' => 'required|string|max:128',
            'attachment_path' => 'required|string|max:1024',
        ]);

        $assessment = GapAssessment::where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        // Resolve the question text + regulation_ref. Search both the
        // platform question bank and the tenant's custom questions —
        // operators may attach evidence to either.
        $regCode = $assessment->regulation_code ?? 'uupdp';
        $question = collect(GapAssessment::getQuestionBank($regCode))
            ->firstWhere('id', $request->question_id);

        if (! $question) {
            // Custom question IDs are prefixed with "custom_" by
            // CustomGapQuestion::toQuestionFormat().
            $customId = str_starts_with($request->question_id, 'custom_')
                ? substr($request->question_id, 7)
                : $request->question_id;
            $custom = CustomGapQuestion::forOrg($assessment->org_id)
                ->forRegulation($regCode)
                ->active()
                ->where('id', $customId)
                ->first();
            if ($custom) {
                $question = $custom->toQuestionFormat();
            }
        }

        if (! $question) {
            return response()->json(['message' => 'Pertanyaan tidak ditemukan.'], 404);
        }

        // Verify the attachment_path actually belongs to this assessment
        // (per-question attachments map) — defence against tampering.
        $attachments = $assessment->attachments ?? [];
        $questionAttachments = $attachments[$request->question_id] ?? [];
        $matched = collect($questionAttachments)->first(function ($att) use ($request) {
            $path = is_array($att) ? ($att['path'] ?? null) : $att;
            return $path === $request->attachment_path;
        });

        if (! $matched) {
            return response()->json([
                'message' => 'Lampiran tidak ditemukan pada pertanyaan ini.',
            ], 404);
        }

        // Resolve to a local readable path. TenantStorageService stores files
        // on either the local public disk or the tenant's own disk; we feed
        // AiDocumentAnalyzer an absolute filesystem path because the analyzer
        // reads via fopen/PdfParser, not via Storage::get.
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
            question: $question['question'] ?? '',
            regulationRef: $question['article'] ?? ($question['regulation_ref'] ?? ''),
            orgId: $orgId,
        );

        $newEntry = array_merge($result->toArray(), [
            'analyzed_at' => now()->toIso8601String(),
            'attachment_path' => $request->attachment_path,
        ]);

        // ai_analyses[qId] sekarang ARRAY (satu entri per attachment) supaya
        // banyak dokumen di 1 pertanyaan bisa punya verdict masing-masing.
        // Format lama (object tunggal) di-wrap normalize jadi array dulu.
        $analyses = $assessment->ai_analyses ?? [];
        $listForQ = $this->normalizeAnalysesForQuestion($analyses[$request->question_id] ?? null);
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
        $analyses[$request->question_id] = $listForQ;

        // Recompute skor — AI verdict baru bisa override jawaban user.
        $result = GapAssessment::calculateScore(
            $assessment->answers ?? [],
            $assessment->regulation_code ?? 'uupdp',
            $analyses
        );
        $assessment->update([
            'ai_analyses' => $analyses,
            'overall_score' => $result['overall_score'],
            'compliance_level' => $result['compliance_level'],
            'recommendations' => $result['recommendations'],
        ]);

        return response()->json($newEntry);
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
        // Object tunggal punya field 'status' di root → bungkus jadi array.
        if (isset($value['status'])) {
            return [$value];
        }
        // Sudah berupa list (numeric indexed).
        return array_values($value);
    }

    /**
     * Bulk analyze ALL evidence attachments di assessment ini.
     *
     * Iterasi setiap (question, attachment terbaru). Skip:
     *   - Question yang tidak punya attachment.
     *   - Pasangan (attachment_path, question) yang sudah ada di
     *     ai_analyses cache (no re-charge).
     *   - File gambar (OCR belum didukung).
     *
     * Stop early kalau credit habis di tengah — return apa yang sudah
     * dianalisis + flag `credits_exhausted`. Caller bisa retry setelah
     * top-up dan sisanya akan diproses (yang sudah cached di-skip).
     */
    public function bulkAnalyzeEvidence(Request $request, string $id, AiDocumentAnalyzer $analyzer)
    {
        $assessment = GapAssessment::where('org_id', $request->user()->org_id)->findOrFail($id);
        $orgId = $request->user()->org_id;
        $regCode = $assessment->regulation_code ?? 'uupdp';

        // Question map: platform bank + custom org.
        $questionMap = collect(GapAssessment::getQuestionBank($regCode))->keyBy('id');
        $customQs = CustomGapQuestion::forOrg($orgId)->forRegulation($regCode)->active()->get();
        foreach ($customQs as $cq) {
            $questionMap['custom_'.$cq->id] = $cq->toQuestionFormat();
        }

        $attachments = $assessment->attachments ?? [];
        $existing = $assessment->ai_analyses ?? [];
        $newAnalyses = $existing;

        $stats = ['analyzed' => 0, 'cached' => 0, 'skipped' => 0, 'failed' => 0];
        $creditsExhausted = false;
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

        foreach ($attachments as $qId => $files) {
            if (empty($files) || ! is_array($files)) {
                continue;
            }

            $question = $questionMap->get($qId);
            if (! $question) {
                $stats['skipped'] += is_array($files) ? count($files) : 1;
                continue;
            }

            // ai_analyses[qId] sekarang ARRAY. Bangun array baru untuk
            // pertanyaan ini, satu entri per attachment, supaya banyak
            // dokumen di 1 pertanyaan masing-masing punya verdict.
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
                        question: $question['question'] ?? '',
                        regulationRef: $question['article'] ?? ($question['regulation_ref'] ?? ''),
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

        // Recompute skor pakai AI verdicts baru — verdict AI bisa override
        // jawaban user di calculateScore. Jadi user lihat skor terupdate
        // tepat setelah bulk analisis selesai.
        $result = GapAssessment::calculateScore(
            $assessment->answers ?? [],
            $assessment->regulation_code ?? 'uupdp',
            $newAnalyses
        );
        $assessment->update([
            'ai_analyses' => $newAnalyses,
            'overall_score' => $result['overall_score'],
            'compliance_level' => $result['compliance_level'],
            'recommendations' => $result['recommendations'],
        ]);

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
     * Resolve the attachment relative path to an absolute filesystem path.
     *
     * TenantStorageService stores via either the local public disk
     * (storage/app/public/...) or the tenant's own disk. Both surface as
     * storage_path('app/public/...') OR storage_path('app/...') depending on
     * the driver. We try the most common locations in order.
     */
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

    private function resolveAttachmentPath(GapAssessment $assessment, string $relativePath): ?string
    {
        $rel = ltrim($relativePath, '/');

        // Layer 1: local public / app disk — fast path saat tenant pakai
        // storage lokal di-server yang sama dengan PHP-FPM.
        $localCandidates = [
            storage_path('app/public/'.$rel),
            storage_path('app/'.$rel),
        ];
        foreach ($localCandidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        // Layer 2: tenant disk (S3 / GCS / dll). File diunduh ke temp file
        // supaya analyzer (smalot/pdfparser/PhpWord/PhpSpreadsheet) yang
        // butuh local filesystem path bisa baca. Temp file dibiarkan ke
        // OS untuk cleanup — pakai sys_get_temp_dir.
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
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.'gap_evidence_'.substr(hash('sha256', $rel), 0, 16).'.'.$ext;
            if (file_put_contents($tmpPath, $contents) === false) {
                return null;
            }
            return $tmpPath;
        } catch (\Throwable $e) {
            \Log::warning('[GAP resolveAttachmentPath] tenant disk fetch failed', [
                'org_id' => $assessment->org_id,
                'path' => $rel,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =============================================
    // Custom Questions CRUD (Sprint B2)
    // =============================================

    public function customQuestions(Request $request)
    {
        $code = $request->query('regulation', 'uupdp');
        $questions = CustomGapQuestion::forOrg($request->user()->org_id)
            ->forRegulation($code)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $questions]);
    }

    public function storeCustomQuestion(Request $request)
    {
        $request->validate([
            'regulation_code' => 'required|string|max:20',
            'category' => 'required|string|max:255',
            'question' => 'required|string',
            'recommendation' => 'required|string',
            'weight' => 'nullable|numeric|min:0.1|max:10',
            'article' => 'nullable|string|max:100',
            'explanation' => 'nullable|string',
        ]);

        $question = CustomGapQuestion::create([
            'org_id' => $request->user()->org_id,
            'regulation_code' => $request->regulation_code,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'question' => $request->question,
            'explanation' => $request->explanation,
            'recommendation' => $request->recommendation,
            'weight' => $request->weight ?? 1.0,
            'article' => $request->article,
            'sort_order' => CustomGapQuestion::forOrg($request->user()->org_id)->max('sort_order') + 1,
        ]);

        return response()->json(['message' => 'Custom question created', 'data' => $question], 201);
    }

    public function updateCustomQuestion(Request $request, string $id)
    {
        $question = CustomGapQuestion::forOrg($request->user()->org_id)->findOrFail($id);

        $request->validate([
            'category' => 'sometimes|string|max:255',
            'question' => 'sometimes|string',
            'recommendation' => 'sometimes|string',
            'weight' => 'nullable|numeric|min:0.1|max:10',
        ]);

        $question->update($request->only([
            'category', 'subcategory', 'question', 'explanation',
            'recommendation', 'weight', 'article', 'sort_order', 'is_active',
        ]));

        return response()->json(['message' => 'Custom question updated', 'data' => $question->fresh()]);
    }

    public function destroyCustomQuestion(Request $request, string $id)
    {
        $question = CustomGapQuestion::forOrg($request->user()->org_id)->findOrFail($id);
        $question->delete();

        return response()->json(['message' => 'Custom question deleted']);
    }
}
