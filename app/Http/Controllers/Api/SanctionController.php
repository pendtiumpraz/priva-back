<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SanctionExposureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paparan sanksi administratif (PP 33/2026 Pasal 184-186).
 *
 * Menampilkan daftar kewajiban ber-sanksi + status paparan tenant dari state
 * platform (RoPA, retensi, breach, DPIA, PPDP).
 */
class SanctionController extends Controller
{
    public function __construct(private SanctionExposureService $exposure) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->exposure->summaryFor($request->user()->org_id),
        ]);
    }
}
