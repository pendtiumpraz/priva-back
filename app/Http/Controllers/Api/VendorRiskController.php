<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Services\AiService;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VendorRiskController extends Controller
{
    /**
     * List all vendors for the organization
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $vendors = Vendor::where('org_id', $orgId)
            ->with(['assessments' => function($q) {
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

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'services_provided' => 'nullable|array',
            'data_shared' => 'nullable|array',
            'risk_level' => 'nullable|string',
            'risk_score' => 'nullable|integer',
            'dpa_status' => 'nullable|string|in:none,draft,signed,expired',
            'dpa_signed_at' => 'nullable|date',
            'dpa_expires_at' => 'nullable|date'
        ]);

        $vendor = Vendor::create(array_merge(
            $request->all(),
            ['org_id' => $request->user()->org_id]
        ));

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
        
        $request->validate([
            'name' => 'string|max:255',
            'services_provided' => 'nullable|array',
            'data_shared' => 'nullable|array',
            'dpa_status' => 'nullable|string|in:none,draft,signed,expired',
            'dpa_signed_at' => 'nullable|date',
            'dpa_expires_at' => 'nullable|date'
        ]);

        $vendor->update($request->all());

        return response()->json(['message' => 'Vendor berhasil diupdate', 'data' => $vendor]);
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
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
        if ($doc && !empty($doc['path'])) {
            try { $storage->getDisk($org)->delete($doc['path']); } catch (\Throwable $e) { /* best-effort */ }
        }
        $vendor->update(['documents' => array_values(array_filter($docs, fn($d) => ($d['id'] ?? null) !== $docId))]);

        return response()->json(['message' => 'Dokumen dihapus', 'data' => $vendor->documents]);
    }

    public function screenDocuments(Request $request, string $id, TenantStorageService $storage)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $org = Organization::findOrFail($request->user()->org_id);
        $docs = $vendor->documents ?? [];
        if (count($docs) === 0) {
            return response()->json(['message' => 'Vendor tidak punya dokumen untuk di-screen'], 422);
        }

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $parser = new \App\Services\DocumentParserService();
        $summaries = [];
        foreach ($docs as $d) {
            if (empty($d['path'])) continue;
            try {
                [$fullPath, $cleanup] = $storage->getLocalPathForProcessing($org, $d['path']);
            } catch (\Throwable $e) {
                \Log::warning("screenDocuments local path resolve failed for {$d['name']}: " . $e->getMessage());
                continue;
            }
            try {
                $parsed = $parser->parse($fullPath, $d['type'] ?? 'pdf');
                $summaries[] = [
                    'doc' => $d['name'] ?? '',
                    'text' => mb_substr($parsed['raw_text'] ?? '', 0, 5000),
                ];
            } catch (\Exception $e) {
                \Log::warning("screenDocuments parse failed for {$d['name']}: " . $e->getMessage());
            } finally {
                $cleanup();
            }
        }

        if (count($summaries) === 0) {
            return response()->json(['message' => 'Tidak ada dokumen yang bisa di-parse'], 422);
        }

        $combinedText = '';
        foreach ($summaries as $s) {
            $combinedText .= "=== {$s['doc']} ===\n{$s['text']}\n\n";
        }

        $response = $ai->vendorRiskAssessor([
            'vendor' => ['name' => $vendor->name, 'services' => $vendor->services_provided ?? []],
            'documents_text' => mb_substr($combinedText, 0, 12000),
        ]);

        if ($response && isset($response['score'])) {
            $vendor->update([
                'risk_score' => (int) ($response['score'] ?? $vendor->risk_score),
                'risk_level' => $response['risk_level'] ?? $vendor->risk_level,
                'last_assessed_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Screening selesai', 'data' => $response, 'vendor' => $vendor->fresh()]);
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

            if (!$response) {
                throw new \Exception("AI gagal merespons dengan JSON valid");
            }

            // Deduct exactly after success
            $org->decrement('ai_credits_remaining', 1);

            return response()->json([
                'status' => 'success',
                'data' => $response
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

            if (!$response || !isset($response['questions'])) {
                throw new \Exception("AI gagal generate pertanyaan");
            }

            $org->decrement('ai_credits_remaining', 1);

            return response()->json([
                'status' => 'success',
                'data' => $response['questions']
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
            'answers' => 'required|array'
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

            if (!$response || !isset($response['score'])) {
                throw new \Exception("AI gagal men-scoring risiko");
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
                'data' => $response
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('VendorRisk Assess Error: '.$e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
