<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HoldingAssessmentEvidence;
use App\Models\HoldingAssessmentInstance;
use App\Models\Organization;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Endpoint publik (tanpa login) untuk anak perusahaan / sub-holding mengisi
 * Holding Compliance Assessment. Akses di-gate PublicHoldingAssessmentTokenMiddleware
 * (resolve instance, expiry, single-use, set tenant context, inject `_instance`).
 *
 *   GET  /              → instance + pertanyaan (snapshot) + jawaban tersimpan
 *   POST /jawaban       → save batch jawaban (autosave per kategori)
 *   POST /upload        → upload bukti per pertanyaan (MULTI evidence)
 *   POST /submit        → finalize: skor, lock token, audit
 *   GET  /result        → read-only setelah submit
 *
 * Analisis AI atas bukti TIDAK dilakukan di sini — itu di reviewer dashboard (F4).
 */
class AsesmenHoldingController extends Controller
{
    private const ALLOWED_EXT = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];

    public function show(Request $request, string $token)
    {
        /** @var HoldingAssessmentInstance $instance */
        $instance = $request->get('_instance');
        $org = Organization::find($instance->org_id);

        return response()->json([
            'data' => [
                'assessment' => [
                    'id' => $instance->id,
                    'title' => $instance->title,
                    'regulation_code' => $instance->regulation_code,
                    'regulation_name' => $instance->regulation_name,
                    'type' => $instance->type ?? 'normal',
                    'target_org_name' => $instance->target_org_name,
                    'status' => $instance->status,
                    'submitted_at' => optional($instance->submitted_at)->toIso8601String(),
                    'token_expires_at' => optional($instance->token_expires_at)->toIso8601String(),
                    'is_locked' => $instance->isLocked(),
                ],
                // Branding tenant HOLDING (pengirim), bukan logo Privasimu.
                'organization' => $org ? [
                    'name' => $org->name ?? null,
                    'logo_url' => $org->logo_url ?? null,
                ] : null,
                'questions' => $instance->effectiveQuestions(),
                'answers' => $instance->answers ?? [],
            ],
        ]);
    }

    public function saveDraft(Request $request, string $token)
    {
        /** @var HoldingAssessmentInstance $instance */
        $instance = $request->get('_instance');

        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*' => 'array',
            'answers.*.value' => 'nullable',
            'answers.*.note' => 'nullable|string|max:2000',
        ]);

        // Merge supaya autosave per kategori tidak menghapus jawaban kategori
        // lain. Pertahankan sub-key `evidence` yang sudah ada per pertanyaan.
        $existing = is_array($instance->answers) ? $instance->answers : [];
        foreach ($validated['answers'] as $qid => $payload) {
            $prevEvidence = $existing[$qid]['evidence'] ?? null;
            $existing[$qid] = array_merge($existing[$qid] ?? [], $payload);
            if ($prevEvidence !== null) {
                $existing[$qid]['evidence'] = $prevEvidence;
            }
        }

        $instance->forceFill([
            'answers' => $existing,
            'status' => $instance->status === 'submitted' ? 'submitted' : 'in_progress',
            'progress' => $this->computeProgress($instance, $existing),
        ])->save();

        return response()->json([
            'message' => 'Jawaban tersimpan.',
            'data' => ['total_answered' => $this->countAnswered($existing)],
        ]);
    }

    /**
     * POST /upload — MULTI evidence per pertanyaan. Setiap upload = 1 row di
     * holding_assessment_evidence (is_active=true) + di-embed ke answers JSON.
     * Tidak overwrite — file bisa lebih dari satu per pertanyaan.
     */
    public function uploadEvidence(Request $request, string $token, TenantStorageService $storage, FileUploadValidator $validator)
    {
        /** @var HoldingAssessmentInstance $instance */
        $instance = $request->get('_instance');

        $request->validate([
            'question_id' => 'required|string|max:64',
            'file' => 'required|file|max:10240', // 10MB
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXT, true)) {
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

        $org = Organization::findOrFail($instance->org_id);

        try {
            $result = $storage->storeTenantPrivateFile(
                $org,
                $file,
                "holding-assessments/{$instance->id}/evidence"
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal menyimpan file ke storage.'], 500);
        }

        $qId = $request->input('question_id');
        $entry = [
            'id' => (string) Str::uuid(),
            'path' => $result['path'] ?? null,
            'driver' => $result['driver'] ?? null,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'uploaded_at' => now()->toIso8601String(),
        ];

        // Embed ke answers[qId].evidence[] (append → multi-evidence).
        $answers = is_array($instance->answers) ? $instance->answers : [];
        if (! isset($answers[$qId]) || ! is_array($answers[$qId])) {
            $answers[$qId] = [];
        }
        if (! isset($answers[$qId]['evidence']) || ! is_array($answers[$qId]['evidence'])) {
            $answers[$qId]['evidence'] = [];
        }
        $answers[$qId]['evidence'][] = $entry;
        $instance->forceFill(['answers' => $answers])->save();

        // Source of truth untuk reviewer + AI analysis (query terpisah, soft-delete).
        try {
            HoldingAssessmentEvidence::create([
                'id' => $entry['id'],
                'org_id' => $instance->org_id,
                'instance_id' => $instance->id,
                'question_id' => $qId,
                'file_path' => $entry['path'],
                'original_name' => $entry['original_name'],
                'mime_type' => $entry['mime'],
                'file_size' => $entry['size'] ?? 0,
                'uploaded_by_user_id' => null,
                'uploaded_by_token' => true,
                'uploaded_ip' => $request->ip(),
                'is_active' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Holding assessment evidence mirror to table failed: '.$e->getMessage(), [
                'instance_id' => $instance->id,
                'question_id' => $qId,
            ]);
        }

        return response()->json(['message' => 'Bukti berhasil diunggah.', 'data' => $entry], 201);
    }

    public function submit(Request $request, string $token)
    {
        /** @var HoldingAssessmentInstance $instance */
        $instance = $request->get('_instance');

        if ($instance->token_consumed_at !== null) {
            return response()->json([
                'error' => 'Asesmen sudah dikirim sebelumnya.',
                'result_url' => url('/api/asesmen-holding/'.$token.'/result'),
            ], 410);
        }

        $score = $this->computeScore($instance);
        $now = now();

        DB::transaction(function () use ($instance, $request, $score, $now) {
            $instance->forceFill([
                'overall_score' => $score['overall_score'],
                'compliance_level' => $score['compliance_level'],
                'maturity_level' => $score['maturity_level'] ?? null,
                'progress' => $score['progress'],
                'status' => 'submitted',
                'token_consumed_at' => $now,
                'submitted_at' => $now,
                'submitted_ip' => substr((string) $request->ip(), 0, 45),
                'submitted_user_agent' => $request->userAgent(),
            ])->save();

            AuditLog::create([
                'module' => 'holding_assessment.public_submit',
                'record_id' => $instance->id,
                'action' => 'public_submit',
                'user_id' => null,
                'user_name' => 'Public Token',
                'user_role' => 'public_token',
                'section' => 'holding_assessment',
                'changes' => [
                    'token_prefix' => substr((string) $instance->assessment_token, 0, 8),
                    'overall_score' => $score['overall_score'],
                    'compliance_level' => $score['compliance_level'],
                    'submitted_at' => $now->toIso8601String(),
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json([
            'message' => 'Asesmen berhasil dikirim. Terima kasih atas partisipasinya.',
            'data' => [
                'assessment_id' => $instance->id,
                'submitted_at' => optional($instance->fresh()->submitted_at)->toIso8601String(),
                'result_url' => url('/api/asesmen-holding/'.$token.'/result'),
            ],
        ]);
    }

    public function result(Request $request, string $token)
    {
        /** @var HoldingAssessmentInstance $instance */
        $instance = $request->get('_instance');

        if ($instance->token_consumed_at === null) {
            return response()->json(['error' => 'Asesmen belum dikirim. Halaman hasil belum tersedia.'], 404);
        }

        $org = Organization::find($instance->org_id);

        return response()->json([
            'data' => [
                'assessment_id' => $instance->id,
                'title' => $instance->title,
                'status' => $instance->status,
                'submitted_at' => optional($instance->submitted_at)->toIso8601String(),
                'target_org_name' => $instance->target_org_name,
                'organization' => $org ? [
                    'name' => $org->name ?? null,
                    'logo_url' => $org->logo_url ?? null,
                ] : null,
                'overall_score' => $instance->overall_score !== null ? (float) $instance->overall_score : null,
                'compliance_level' => $instance->compliance_level,
                'answer_count' => $this->countAnswered($instance->answers ?? []),
                'message' => 'Asesmen Anda telah diterima dan akan ditinjau oleh tim holding.',
            ],
        ]);
    }

    // ===================================================================
    // Scoring — weighted yes/partial/no (mirror GAP). 'na' dikecualikan.
    // ===================================================================

    private function answerValue(array $answers, string $qid): ?string
    {
        $v = $answers[$qid]['value'] ?? null;
        if ($v === null) {
            return null;
        }
        $v = strtolower((string) $v);
        // Normalisasi sinonim ke skala internal.
        return match ($v) {
            'yes', 'ya', 'comply' => 'yes',
            'partial', 'sebagian' => 'partial',
            'no', 'tidak', 'non_comply' => 'no',
            'na', 'n/a', 'tidak_relevan' => 'na',
            default => $v,
        };
    }

    private function computeScore(HoldingAssessmentInstance $instance): array
    {
        if (($instance->type ?? 'normal') === 'maturity') {
            return $this->computeMaturityScore($instance);
        }

        $questions = $instance->effectiveQuestions();
        $answers = is_array($instance->answers) ? $instance->answers : [];

        $totalWeight = 0.0;
        $earned = 0.0;
        $answered = 0;
        $counted = 0;

        foreach ($questions as $q) {
            $qid = $q['id'] ?? null;
            if (! $qid) {
                continue;
            }
            $w = (float) ($q['weight'] ?? 1.0);
            $val = $this->answerValue($answers, $qid);
            if ($val !== null && $val !== '') {
                $answered++;
            }
            if ($val === 'na' || $val === null || $val === '') {
                continue; // 'na' & belum dijawab dikecualikan dari total
            }
            $counted++;
            $totalWeight += $w;
            $earned += match ($val) {
                'yes' => $w,
                'partial' => $w * 0.5,
                default => 0.0, // 'no'
            };
        }

        $overall = $totalWeight > 0 ? round(($earned / $totalWeight) * 100, 2) : 0.0;
        $level = $overall >= 70 ? 'high' : ($overall >= 40 ? 'medium' : 'low');
        $totalQ = max(count($questions), 1);

        return [
            'overall_score' => $overall,
            'compliance_level' => $level,
            'progress' => round(($answered / $totalQ) * 100, 2),
        ];
    }

    /**
     * Maturity scoring — jawaban skala level 1..5. overall_score dinormalkan ke
     * persen (avg/5*100) supaya bisa dibandingkan dgn assessment normal di grafik;
     * maturity_level = pembulatan rata-rata level.
     */
    private function computeMaturityScore(HoldingAssessmentInstance $instance): array
    {
        $questions = $instance->effectiveQuestions();
        $answers = is_array($instance->answers) ? $instance->answers : [];

        $sum = 0.0;
        $counted = 0;
        $answered = 0;
        foreach ($questions as $q) {
            $qid = $q['id'] ?? null;
            if (! $qid) {
                continue;
            }
            $raw = $answers[$qid]['value'] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $answered++;
            $level = (int) $raw;
            if ($level < 1) {
                continue; // 0 / tidak relevan dikecualikan dari rata-rata
            }
            $level = min(5, max(1, $level));
            $sum += $level;
            $counted++;
        }

        $avg = $counted > 0 ? $sum / $counted : 0.0;
        $overall = round(($avg / 5) * 100, 2);
        $level = $counted > 0 ? (int) round($avg) : null;
        $complianceLevel = $overall >= 70 ? 'high' : ($overall >= 40 ? 'medium' : 'low');
        $totalQ = max(count($questions), 1);

        return [
            'overall_score' => $overall,
            'compliance_level' => $complianceLevel,
            'maturity_level' => $level,
            'progress' => round(($answered / $totalQ) * 100, 2),
        ];
    }

    private function computeProgress(HoldingAssessmentInstance $instance, array $answers): float
    {
        $questions = $instance->effectiveQuestions();
        $totalQ = max(count($questions), 1);

        return round(($this->countAnswered($answers) / $totalQ) * 100, 2);
    }

    private function countAnswered(array $answers): int
    {
        $n = 0;
        foreach ($answers as $a) {
            if (is_array($a) && isset($a['value']) && $a['value'] !== null && $a['value'] !== '') {
                $n++;
            }
        }

        return $n;
    }
}
