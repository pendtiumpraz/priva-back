<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\PostureScoreService;

class PostureController extends Controller
{
    protected $postureService;

    public function __construct(PostureScoreService $postureService)
    {
        $this->postureService = $postureService;
    }

    /**
     * Get the DSPM overall posture score and breakdown.
     */
    public function getPosture(Request $request)
    {
        $orgId = $request->user()->org_id;

        if (!$orgId) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        $posture = $this->postureService->calculatePosture($orgId);

        return response()->json([
            'posture' => $posture
        ]);
    }

    /**
     * Get the DSPM historical trend.
     */
    public function getTrend(Request $request)
    {
        $orgId = $request->user()->org_id;

        if (!$orgId) {
            return response()->json(['message' => 'Organization context required'], 400);
        }

        $trend = $this->postureService->getHistoricalTrend($orgId);

        return response()->json([
            'trend' => $trend
        ]);
    }
}
