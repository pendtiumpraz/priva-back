<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Services\AiService;
use App\Services\DocumentParserService;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VendorRiskController extends Controller
{
    /**
     * List all vendors for the organization
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $vendors = Vendor::where('org_id', $orgId)
            ->with(['assessments' => function ($q) {
                $q->orderBy('created_at', 'desc')->limit(1);
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($vendor) {
                $assessment = $vendor->assessments->first();

                return [
                    'id' => $vendor->id,
                    'name' => $vendor->name,
                    'service' => empty($vendor->services_provided) ? 'Unknown' : implode(', ', $vendor->services_provided),
                    'risk_level' => $vendor->risk_level,
                    'score' => $vendor->risk_score,
                    'dpa_status' => $vendor->dpa_status,
                    'dpa_expires_at' => $vendor->dpa_expires_at,
                    'last_assessed' => $vendor->last_assessed_at ? $vendor->last_assessed_at->format('Y-m-d') : '-',
                ];
            });

        return response()->json(['data' => $vendors]);
    }

    private const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    private const DPA_STATUSES = ['none', 'draft', 'signed', 'expired'];

    public function store(Request $request)
    {
        $data = $request->validate($this->writeRules(false));

        $vendor = Vendor::create(array_merge($data, [
            'org_id' => $request->user()->org_id,
        ]));

        return response()->json(['message' => 'Vendor berhasil ditambahkan', 'data' => $vendor], 201);
    }

    public function show(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->with('assessments')->findOrFail($id);

        return response()->json(['data' => $vendor]);
    }

    public function update(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $data = $request->validate($this->writeRules(true));
        $vendor->update($data);

        return response()->json(['message' => 'Vendor berhasil diupdate', 'data' => $vendor->fresh()]);
    }

    /**
     * Validation rules shared by store + update.
     * Critical: risk_level + risk_score validated against enum/range — sebelumnya
     * frontend bisa kirim risk_level apa saja dan backend menerima begitu saja.
     */
    private function writeRules(bool $forUpdate): array
    {
        $req = $forUpdate ? 'sometimes' : 'required';
        $opt = $forUpdate ? 'sometimes|nullable' : 'nullable';

        return [
            'name' => "{$req}|string|max:255",
            'description' => "{$opt}|string|max:2000",
            'website' => "{$opt}|url|max:500",
            'country' => "{$opt}|string|max:100",
            'services_provided' => "{$opt}|array",
            'services_provided.*' => 'string|max:200',
            'data_shared' => "{$opt}|array",
            'data_shared.*' => 'string|max:200',
            'risk_level' => "{$opt}|in:".implode(',', self::RISK_LEVELS),
            'risk_score' => "{$opt}|integer|min:0|max:100",
            'dpa_status' => "{$opt}|in:".implode(',', self::DPA_STATUSES),
            'dpa_signed_at' => "{$opt}|date",
            'dpa_expires_at' => "{$opt}|date|after_or_equal:dpa_signed_at",
            'last_assessed_at' => "{$opt}|date",
            'contact_name' => "{$opt}|string|max:200",
            'contact_email' => "{$opt}|email|max:200",
        ];
    }

    public function destroy(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->delete();

        return response()->json(['message' => 'Vendor dipindahkan ke tempat sampah']);
    }

    public function trashed(Request $request)
    {
        $vendors = Vendor::onlyTrashed()->where('org_id', $request->user()->org_id)
            ->orderBy('deleted_at', 'desc')->get();

        return response()->json(['data' => $vendors]);
    }

    public function restore(Request $request, $id)
    {
        $vendor = Vendor::onlyTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->restore();

        return response()->json(['message' => 'Vendor berhasil dipulihkan']);
    }

    public function forceDelete(Request $request, $id)
    {
        $vendor = Vendor::onlyTrashed()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->forceDelete();

        return response()->json(['message' => 'Vendor dihapus permanen']);
    }

    // =========================================================
    //  Sprint D3: TPRM document management
    // =========================================================

    public function uploadDocument(Request $request, string $id, TenantStorageService $storage)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf,docx,doc,xlsx|max:15360', // 15MB
        ]);

        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = Organization::findOrFail($request->user()->org_id);

        $file = $request->file('file');
        $stored = $storage->storeTenantPrivateFile($org, $file, "vendors/{$vendor->id}");
        $path = $stored['path'];

        $documents = $vendor->documents ?? [];
        $documents[] = [
            'id' => (string) Str::uuid(),
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'type' => strtolower($file->getClientOriginalExtension()),
            'size' => $file->getSize(),
            'driver' => $stored['driver'],
            'uploaded_at' => now()->toIso8601String(),
            'uploaded_by' => $request->user()->id,
        ];
        $vendor->update(['documents' => $documents]);

        return response()->json(['message' => 'Dokumen terupload', 'data' => $documents]);
    }

    public function deleteDocument(Request $request, string $id, string $docId, TenantStorageService $storage)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = Organization::findOrFail($request->user()->org_id);
        $docs = $vendor->documents ?? [];
        $doc = collect($docs)->firstWhere('id', $docId);
        if ($doc && ! empty($doc['path'])) {
            try {
                $storage->getDisk($org)->delete($doc['path']);
            } catch (\Throwable $e) { /* best-effort */
            }
        }
        $vendor->update(['documents' => array_values(array_filter($docs, fn ($d) => ($d['id'] ?? null) !== $docId))]);

        return response()->json(['message' => 'Dokumen dihapus', 'data' => $vendor->documents]);
    }

    /**
     * Screen vendor documents — dua mode:
     *  - mode=manual (default kalau AI gak available / user pilih): parse semua
     *    dokumen, return raw text supaya user bisa baca manual & kasih skor
     *    sendiri lewat update endpoint. TIDAK ada AI dependency.
     *  - mode=ai: parse + kirim ke AI vendorRiskAssessor untuk auto-score.
     *
     * Per-doc parse errors di-surface (bukan di-swallow) — sebelumnya user lihat
     * "Tidak ada dokumen yang bisa di-parse" walau ada 10 dokumen yang gagal.
     */
    public function screenDocuments(Request $request, string $id, TenantStorageService $storage)
    {
        $request->validate(['mode' => 'nullable|in:manual,ai,auto']);
        $mode = $request->input('mode', 'auto');

        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = Organization::findOrFail($request->user()->org_id);
        $docs = $vendor->documents ?? [];
        if (count($docs) === 0) {
            return response()->json(['message' => 'Vendor tidak punya dokumen untuk di-screen'], 422);
        }

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        $aiAvailable = $ai->isAvailable() && ($org->ai_credits_remaining ?? 0) > 0;
        $useAi = $mode === 'ai' || ($mode === 'auto' && $aiAvailable);

        if ($mode === 'ai' && ! $aiAvailable) {
            return response()->json([
                'message' => 'AI tidak tersedia (API key kosong atau credits habis). Gunakan mode=manual untuk parse-only.',
            ], 503);
        }

        $parser = new DocumentParserService;
        $summaries = [];
        $parseErrors = [];
        foreach ($docs as $d) {
            if (empty($d['path'])) {
                $parseErrors[] = ['doc' => $d['name'] ?? '?', 'error' => 'path kosong'];

                continue;
            }
            try {
                [$fullPath, $cleanup] = $storage->getLocalPathForProcessing($org, $d['path']);
            } catch (\Throwable $e) {
                $parseErrors[] = ['doc' => $d['name'] ?? '?', 'error' => 'storage tidak terjangkau: '.$e->getMessage()];
                Log::warning("screenDocuments storage resolve failed for {$d['name']}: ".$e->getMessage());

                continue;
            }
            try {
                $parsed = $parser->parse($fullPath, $d['type'] ?? 'pdf');
                $summaries[] = [
                    'doc' => $d['name'] ?? '',
                    'text' => mb_substr($parsed['raw_text'] ?? '', 0, 5000),
                ];
            } catch (\Throwable $e) {
                $parseErrors[] = ['doc' => $d['name'] ?? '?', 'error' => $e->getMessage()];
                Log::warning("screenDocuments parse failed for {$d['name']}: ".$e->getMessage());
            } finally {
                $cleanup();
            }
        }

        if (count($summaries) === 0) {
            return response()->json([
                'message' => 'Tidak ada dokumen yang berhasil di-parse',
                'parse_errors' => $parseErrors,
            ], 422);
        }

        // Manual mode: return parsed text, user kasih skor sendiri via update endpoint
        if (! $useAi) {
            return response()->json([
                'message' => 'Parse selesai (manual mode) — review teks lalu update risk_score/level secara manual.',
                'mode' => 'manual',
                'documents' => $summaries,
                'parse_errors' => $parseErrors,
                'vendor' => $vendor,
            ]);
        }

        // AI mode
        $combinedText = '';
        foreach ($summaries as $s) {
            $combinedText .= "=== {$s['doc']} ===\n{$s['text']}\n\n";
        }

        try {
            $response = $ai->vendorRiskAssessor([
                'vendor' => ['name' => $vendor->name, 'services' => $vendor->services_provided ?? []],
                'documents_text' => mb_substr($combinedText, 0, 12000),
            ]);
        } catch (\Throwable $e) {
            Log::error('screenDocuments AI call failed: '.$e->getMessage());

            return response()->json([
                'message' => 'AI gagal — gunakan mode=manual untuk lihat hasil parse.',
                'mode' => 'ai',
                'ai_error' => $e->getMessage(),
                'documents' => $summaries,
                'parse_errors' => $parseErrors,
            ], 502);
        }

        if ($response && isset($response['score'])) {
            $vendor->update([
                'risk_score' => (int) $response['score'],
                'risk_level' => $response['risk_level'] ?? $vendor->risk_level,
                'last_assessed_at' => now(),
            ]);
            $org->decrement('ai_credits_remaining', 1);
        }

        return response()->json([
            'message' => 'Screening selesai (AI-assisted)',
            'mode' => 'ai',
            'data' => $response,
            'documents' => $summaries,
            'parse_errors' => $parseErrors,
            'vendor' => $vendor->fresh(),
        ]);
    }

    /**
     * Re-assess existing vendor — entry point untuk "Jalankan Ulang Audit"
     * di detail modal. Manual mode: caller kirim score+level langsung.
     * AI mode: kirim updated answers untuk re-scoring.
     *
     * Ini menggantikan placeholder "belum diimplementasi" toast di frontend.
     */
    public function reassess(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);

        $validated = $request->validate([
            'mode' => 'required|in:manual,ai',
            'answers' => 'nullable|array',
            'manual_score' => 'nullable|integer|min:0|max:100',
            'manual_risk_level' => 'nullable|in:'.implode(',', self::RISK_LEVELS),
            'notes' => 'nullable|string|max:5000',
            'recommendations' => 'nullable|array',
            'recommendations.*' => 'string|max:500',
        ]);

        if ($validated['mode'] === 'manual') {
            if (! isset($validated['manual_score'], $validated['manual_risk_level'])) {
                return response()->json(['message' => 'manual_score + manual_risk_level wajib untuk mode manual'], 422);
            }

            $assessment = VendorAssessment::create([
                'vendor_id' => $vendor->id,
                'org_id' => $vendor->org_id,
                'assessed_by' => $request->user()->id,
                'answers' => $validated['answers'] ?? [],
                'score' => $validated['manual_score'],
                'risk_level' => $validated['manual_risk_level'],
                'recommendations' => $validated['recommendations'] ?? [],
                'notes' => $validated['notes'] ?? null,
            ]);
            $vendor->update([
                'risk_score' => $validated['manual_score'],
                'risk_level' => $validated['manual_risk_level'],
                'last_assessed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Re-assessment manual tersimpan.',
                'mode' => 'manual',
                'assessment' => $assessment,
                'vendor' => $vendor->fresh(),
            ]);
        }

        // AI mode
        $org = $request->user()->organization;
        if (($org->ai_credits_remaining ?? 0) < 1) {
            return response()->json(['message' => 'AI credits habis. Gunakan mode=manual.'], 402);
        }
        if (empty($validated['answers'])) {
            return response()->json(['message' => 'answers wajib untuk mode AI'], 422);
        }

        try {
            $aiService = (new AiService($vendor->org_id))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorRiskAssessor($validated['answers']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'AI gagal: '.$e->getMessage(), 'fallback' => 'gunakan mode=manual'], 502);
        }

        if (! $response || ! isset($response['score'])) {
            return response()->json(['message' => 'AI tidak return skor — gunakan mode=manual'], 502);
        }

        $assessment = VendorAssessment::create([
            'vendor_id' => $vendor->id,
            'org_id' => $vendor->org_id,
            'assessed_by' => $request->user()->id,
            'answers' => $validated['answers'],
            'score' => (int) $response['score'],
            'risk_level' => $response['risk_level'] ?? 'medium',
            'recommendations' => $response['recommendations'] ?? [],
            'notes' => json_encode($response['red_flags'] ?? []),
        ]);
        $vendor->update([
            'risk_score' => (int) $response['score'],
            'risk_level' => $response['risk_level'] ?? $vendor->risk_level,
            'last_assessed_at' => now(),
        ]);
        $org->decrement('ai_credits_remaining', 1);

        return response()->json([
            'message' => 'Re-assessment AI selesai.',
            'mode' => 'ai',
            'assessment' => $assessment,
            'vendor' => $vendor->fresh(),
            'data' => $response,
        ]);
    }

    /**
     * 1. AI Auto-Form (Extractor)
     */
    public function extract(Request $request)
    {
        $request->validate(['url' => 'required|string']);

        $org = $request->user()->organization;
        if ($org->ai_credits_remaining < 1) {
            return response()->json(['message' => 'AI Credits tidak mencukupi'], 402);
        }

        try {
            $aiService = (new AiService($org->id))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorExtractor($request->url);

            if (! $response) {
                throw new \Exception('AI gagal merespons dengan JSON valid');
            }

            // Deduct exactly after success
            $org->decrement('ai_credits_remaining', 1);

            return response()->json([
                'status' => 'success',
                'data' => $response,
            ]);

        } catch (\Exception $e) {
            Log::error('VendorRisk Extract Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 2. AI Questionnaire Generator
     */
    public function generateQuestions(Request $request)
    {
        $request->validate(['extracted_data' => 'required|array']);

        $org = $request->user()->organization;
        if ($org->ai_credits_remaining < 1) {
            return response()->json(['message' => 'AI Credits tidak mencukupi'], 402);
        }

        try {
            $aiService = (new AiService($org->id))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorQuestionnaire($request->extracted_data);

            if (! $response || ! isset($response['questions'])) {
                throw new \Exception('AI gagal generate pertanyaan');
            }

            $org->decrement('ai_credits_remaining', 1);

            return response()->json([
                'status' => 'success',
                'data' => $response['questions'],
            ]);

        } catch (\Exception $e) {
            Log::error('VendorRisk QGen Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * 3. AI Vendor Risk Assessor
     */
    public function assess(Request $request)
    {
        $request->validate([
            'extracted_data' => 'required|array',
            'answers' => 'required|array',
        ]);

        $orgId = $request->user()->org_id;
        $org = $request->user()->organization;
        if ($org->ai_credits_remaining < 1) {
            return response()->json(['message' => 'AI Credits tidak mencukupi'], 402);
        }

        try {
            DB::beginTransaction();

            $aiService = (new AiService($orgId))->setLocale($request->user()->locale ?? 'id');
            $response = $aiService->vendorRiskAssessor($request->answers);

            if (! $response || ! isset($response['score'])) {
                throw new \Exception('AI gagal men-scoring risiko');
            }

            $extracted = $request->extracted_data;

            // Save to DB
            $vendor = Vendor::create([
                'org_id' => $orgId,
                'name' => $extracted['name'] ?? 'Unknown Vendor',
                'services_provided' => $extracted['services_provided'] ?? [],
                'data_shared' => $extracted['data_shared'] ?? [],
                'description' => $extracted['summary'] ?? null,
                'risk_score' => $response['score'],
                'risk_level' => $response['risk_level'],
                'last_assessed_at' => now(),
            ]);

            $assessment = VendorAssessment::create([
                'vendor_id' => $vendor->id,
                'org_id' => $orgId,
                'assessed_by' => $request->user()->id,
                'answers' => $request->answers,
                'score' => $response['score'],
                'risk_level' => $response['risk_level'],
                'recommendations' => $response['recommendations'] ?? [],
                'notes' => json_encode($response['red_flags'] ?? []),
            ]);

            $org->decrement('ai_credits_remaining', 1);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'data' => $response,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('VendorRisk Assess Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
