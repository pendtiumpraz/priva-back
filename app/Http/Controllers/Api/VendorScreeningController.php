<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessVendorScreeningJob;
use App\Models\Vendor;
use App\Models\VendorScreening;
use App\Services\VendorScreening\AiContextPresets;
use App\Services\VendorScreening\VendorScreeningService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TPRM Phase 3 — AI Vendor Screening endpoints.
 *
 * POST  /api/vendor-risk/{id}/screen          run sync screening
 * GET   /api/vendor-risk/{id}/screenings      list history
 * GET   /api/vendor-risk/{id}/screenings/{sid}  detail
 *
 * Permission slug: vendor_risk (write untuk POST, read untuk GET).
 */
class VendorScreeningController extends Controller
{
    /**
     * POST /api/vendor-risk/{id}/screen
     *
     * Sources opsional (default semua yang available):
     *   { "sources": ["web_search", "privacy_policy", "documents", "sanctions"],
     *     "context_preset": "perbankan",
     *     "async": true }
     *
     * Mode default Phase 3.5: ASYNC via queue. Return 202 + screening_id
     * yang client polling sampai status=completed/failed. Client boleh
     * pass async=false untuk legacy sinkron mode.
     */
    public function run(Request $request, string $vendorId, VendorScreeningService $service)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($vendorId);

        $data = $request->validate([
            'sources' => 'nullable|array',
            'sources.*' => 'string|in:web_search,privacy_policy,documents,sanctions',
            'context_preset' => 'nullable|string|in:'.implode(',', AiContextPresets::ALL_KEYS),
            'async' => 'nullable|boolean',
        ]);

        $sources = $data['sources'] ?? ['web_search', 'privacy_policy', 'documents', 'sanctions'];
        $preset = $data['context_preset'] ?? null;
        $async = $data['async'] ?? true; // default async

        if (! $async) {
            // Sinkron legacy mode — untuk klien yang prefer wait
            $screening = $service->run($vendor, $sources, $request->user()->id, $preset);

            if ($screening->status === VendorScreening::STATUS_FAILED) {
                return response()->json([
                    'message' => 'Screening selesai dengan error.',
                    'error' => $screening->error_message,
                    'data' => $this->present($screening),
                ], 500);
            }
            return response()->json([
                'message' => 'Screening selesai.',
                'data' => $this->present($screening),
            ]);
        }

        // Async mode — bikin row pending, dispatch job, return 202
        $screening = VendorScreening::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'vendor_id' => $vendor->id,
            'triggered_by_user_id' => $request->user()->id,
            'sources_used' => $sources,
            'status' => VendorScreening::STATUS_PENDING,
        ]);

        ProcessVendorScreeningJob::dispatch(
            $screening->id,
            $vendor->id,
            $orgId,
            $sources,
            $request->user()->id,
            $preset,
        );

        return response()->json([
            'message' => 'Screening dijadwalkan, status akan diperbarui di background.',
            'data' => $this->presentBrief($screening),
        ], 202);
    }

    /**
     * POST /api/vendor-risk/bulk-screen
     * Bulk schedule screening untuk N vendor sekaligus. Selalu async.
     *
     * Body: { vendor_ids: [...], sources: [...], context_preset: '...' }
     */
    public function bulkScreen(Request $request)
    {
        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'vendor_ids' => 'required|array|min:1|max:50',
            'vendor_ids.*' => 'required|string',
            'sources' => 'nullable|array',
            'sources.*' => 'string|in:web_search,privacy_policy,documents,sanctions',
            'context_preset' => 'nullable|string|in:'.implode(',', AiContextPresets::ALL_KEYS),
        ]);

        $sources = $data['sources'] ?? ['web_search', 'privacy_policy', 'documents', 'sanctions'];
        $preset = $data['context_preset'] ?? null;

        // Filter vendor yang valid (milik org ini)
        $vendors = Vendor::query()
            ->where('org_id', $orgId)
            ->whereIn('id', $data['vendor_ids'])
            ->get(['id', 'name']);

        $dispatched = [];
        foreach ($vendors as $vendor) {
            $screening = VendorScreening::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'vendor_id' => $vendor->id,
                'triggered_by_user_id' => $request->user()->id,
                'sources_used' => $sources,
                'status' => VendorScreening::STATUS_PENDING,
            ]);

            ProcessVendorScreeningJob::dispatch(
                $screening->id,
                $vendor->id,
                $orgId,
                $sources,
                $request->user()->id,
                $preset,
            );

            $dispatched[] = [
                'vendor_id' => $vendor->id,
                'vendor_name' => $vendor->name,
                'screening_id' => $screening->id,
            ];
        }

        return response()->json([
            'message' => count($dispatched).' screening dijadwalkan.',
            'data' => [
                'requested' => count($data['vendor_ids']),
                'dispatched' => count($dispatched),
                'skipped' => count($data['vendor_ids']) - count($dispatched),
                'items' => $dispatched,
            ],
        ], 202);
    }

    /**
     * GET /api/tprm/context-presets
     * List preset konteks AI untuk dropdown FE.
     */
    public function listPresets()
    {
        $options = AiContextPresets::options();
        return response()->json([
            'data' => collect($options)->map(fn ($v, $k) => [
                'key' => $k,
                'label' => $v['label'],
                'has_paragraph' => ! empty($v['paragraph']),
            ])->values(),
        ]);
    }

    /**
     * GET /api/vendor-risk/{id}/screenings
     * List history screening untuk vendor ini (terbaru di atas).
     */
    public function index(Request $request, string $vendorId)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($vendorId);

        $rows = VendorScreening::query()
            ->where('vendor_id', $vendor->id)
            ->where('org_id', $orgId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($s) => $this->presentBrief($s));

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/vendor-risk/{id}/screenings/{sid}
     * Detail satu screening + raw inputs supaya UI bisa drill-down.
     */
    public function show(Request $request, string $vendorId, string $screeningId)
    {
        $orgId = $request->user()->org_id;

        $screening = VendorScreening::query()
            ->where('id', $screeningId)
            ->where('vendor_id', $vendorId)
            ->where('org_id', $orgId)
            ->firstOrFail();

        return response()->json(['data' => $this->present($screening)]);
    }

    /**
     * Field minimal untuk list page (tanpa raw inputs yang besar).
     */
    private function presentBrief(VendorScreening $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->status,
            'overall_risk' => $s->overall_risk,
            'risk_score' => $s->risk_score,
            'summary' => $s->summary,
            'sources_used' => $s->sources_used,
            'search_provider' => $s->search_provider,
            'started_at' => $s->started_at?->toIso8601String(),
            'completed_at' => $s->completed_at?->toIso8601String(),
            'error_message' => $s->error_message,
        ];
    }

    /**
     * Full detail termasuk raw inputs untuk drill-down + re-analysis.
     */
    private function present(VendorScreening $s): array
    {
        return array_merge($this->presentBrief($s), [
            'findings' => $s->findings,
            'red_flags' => $s->red_flags,
            'recommendation' => $s->recommendation,
            'search_results_raw' => $s->search_results_raw,
            'privacy_policy_excerpt' => $s->privacy_policy_excerpt,
            'documents_summary' => $s->documents_summary,
            'sanctions_hits' => $s->sanctions_hits,
            'ai_model' => $s->ai_model,
            'tokens_used' => $s->tokens_used,
        ]);
    }
}
