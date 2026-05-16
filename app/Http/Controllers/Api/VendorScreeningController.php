<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorScreening;
use App\Services\VendorScreening\VendorScreeningService;
use Illuminate\Http\Request;

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
     *   { "sources": ["web_search", "privacy_policy", "documents", "sanctions"] }
     *
     * Sinkron — user blocking sampai selesai (10-30 detik biasanya).
     */
    public function run(Request $request, string $vendorId, VendorScreeningService $service)
    {
        $orgId = $request->user()->org_id;
        $vendor = Vendor::where('org_id', $orgId)->findOrFail($vendorId);

        $data = $request->validate([
            'sources' => 'nullable|array',
            'sources.*' => 'string|in:web_search,privacy_policy,documents,sanctions',
        ]);

        $sources = $data['sources'] ?? ['web_search', 'privacy_policy', 'documents', 'sanctions'];

        $screening = $service->run($vendor, $sources, $request->user()->id);

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
