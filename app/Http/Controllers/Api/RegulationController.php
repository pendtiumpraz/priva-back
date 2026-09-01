<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\RegulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kelola regulasi add-on per tenant.
 *
 * UU PDP + PP 33 = core (wajib, terkunci ON). Add-on lain (POJK, UU ITE,
 * GDPR, dll) bisa di-enable/disable tenant. Menggerakkan gating konten KB.
 */
class RegulationController extends Controller
{
    public function __construct(private RegulationService $regulations) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->regulations->listFor($request->user()->org_id),
        ]);
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Hanya admin tenant yang dapat mengatur regulasi.'], 422);
        }

        $this->regulations->setEnabled($orgId, $code, $data['enabled'], $request->user()->id);

        AuditLog::log('regulations', $code, $data['enabled'] ? 'regulation_enabled' : 'regulation_disabled', [
            'code' => $code,
        ], 'regulations');

        return response()->json([
            'message' => $data['enabled'] ? 'Regulasi diaktifkan.' : 'Regulasi dinonaktifkan.',
            'data' => $this->regulations->listFor($orgId),
        ]);
    }
}
