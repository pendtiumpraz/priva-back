<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostureSnapshot;
use App\Services\PostureScoreService;
use Illuminate\Http\Request;

class PostureController extends Controller
{
    public function __construct(protected PostureScoreService $postureService) {}

    /**
     * Live posture — computed on-the-fly from current data.
     * Used by the Security page hero gauge.
     */
    public function getPosture(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (!$orgId) return response()->json(['message' => 'Organization context required'], 400);

        $posture = $this->postureService->calculatePosture($orgId);
        return response()->json(['posture' => $posture]);
    }

    /**
     * Historical trend — reads from posture_snapshots. Returns up to
     * `?days=30` (default) data points, one per day. If empty, FE shows
     * "trend builds from your first snapshot" hint.
     */
    public function getTrend(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (!$orgId) return response()->json(['message' => 'Organization context required'], 400);

        $days = (int) $request->get('days', 30);
        $days = max(7, min($days, 365));

        $trend = $this->postureService->getHistoricalTrend($orgId, $days);

        return response()->json([
            'trend' => $trend,
            'has_baseline' => count($trend) >= 7,
            'snapshot_count' => count($trend),
            'message' => count($trend) === 0
                ? 'Belum ada snapshot. Jalankan refresh untuk mulai membangun trend.'
                : (count($trend) < 7
                    ? 'Trend masih membangun. Snapshot harian otomatis tiap pagi.'
                    : null),
        ]);
    }

    /**
     * Manual snapshot trigger — for the "Refresh" button on the Security
     * page. Useful right after a big change (new scan, DPA signed, etc.)
     * to see the impact immediately instead of waiting for tomorrow's
     * scheduled run.
     */
    public function takeSnapshot(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (!$orgId) return response()->json(['message' => 'Organization context required'], 400);

        $snap = $this->postureService->takeSnapshot($orgId, PostureSnapshot::SOURCE_MANUAL);

        return response()->json([
            'message' => 'Snapshot diambil. Skor: ' . $snap->overall_score . '/100.',
            'snapshot' => $snap,
        ], 201);
    }
}
