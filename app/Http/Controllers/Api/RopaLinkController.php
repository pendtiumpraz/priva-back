<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\DsrRequest;
use App\Models\InformationSystem;
use App\Models\Ropa;
use Illuminate\Http\Request;

/**
 * Cross-module RoPA linkage manager.
 *
 *   GET   /api/data-discovery/{id}/ropas              — list linked RoPAs for an IS
 *   PUT   /api/data-discovery/{id}/ropas              — sync (replace) {ropa_ids: [...]}
 *   POST  /api/data-discovery/{id}/ropas              — attach single
 *   DELETE /api/data-discovery/{id}/ropas/{ropa_id}   — detach
 *
 *   Same shape for /consent-collections/{id}/ropas
 *
 *   GET   /api/dsr/{id}/affected-ropas
 *     Derived: walks DSR.scopes → information_system → ropas
 *     Returns dedup'd RoPA list with which IS triggered the link.
 */
class RopaLinkController extends Controller
{
    // =====================================================================
    // INFORMATION SYSTEM ↔ RoPA
    // =====================================================================
    public function indexForInformationSystem(Request $request, string $id)
    {
        $user = $request->user();
        $is = InformationSystem::where('org_id', $user->org_id)->findOrFail($id);

        return response()->json(['data' => $is->ropas()->select('ropas.id', 'ropas.registration_number', 'ropas.processing_activity', 'ropas.risk_level')->get()]);
    }

    public function syncForInformationSystem(Request $request, string $id)
    {
        $user = $request->user();
        $is = InformationSystem::where('org_id', $user->org_id)->findOrFail($id);

        $data = $request->validate([
            'ropa_ids' => 'required|array',
            'ropa_ids.*' => 'uuid',
        ]);

        // Verify all RoPAs belong to same org
        $valid = Ropa::whereIn('id', $data['ropa_ids'])->where('org_id', $user->org_id)->pluck('id')->all();

        // Sync with org_id pivot data
        $syncData = [];
        foreach ($valid as $rid) {
            $syncData[$rid] = ['org_id' => $user->org_id];
        }
        $is->ropas()->sync($syncData);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'data_discovery', 'record_id' => $is->id,
            'action' => 'is.ropas_sync',
            'details' => ['ropa_ids' => $valid, 'count' => count($valid)],
        ]);

        return response()->json([
            'message' => count($valid).' RoPA tersinkron.',
            'data' => $is->ropas()->select('ropas.id', 'ropas.registration_number', 'ropas.processing_activity')->get(),
        ]);
    }

    public function attachToInformationSystem(Request $request, string $id)
    {
        $user = $request->user();
        $is = InformationSystem::where('org_id', $user->org_id)->findOrFail($id);
        $data = $request->validate(['ropa_id' => 'required|uuid', 'notes' => 'nullable|string|max:500']);
        Ropa::where('id', $data['ropa_id'])->where('org_id', $user->org_id)->firstOrFail();

        $is->ropas()->syncWithoutDetaching([
            $data['ropa_id'] => ['org_id' => $user->org_id, 'notes' => $data['notes'] ?? null],
        ]);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'data_discovery', 'record_id' => $is->id,
            'action' => 'is.ropa_attach',
            'details' => ['ropa_id' => $data['ropa_id']],
        ]);

        return response()->json(['message' => 'RoPA ter-link.']);
    }

    public function detachFromInformationSystem(Request $request, string $id, string $ropaId)
    {
        $user = $request->user();
        $is = InformationSystem::where('org_id', $user->org_id)->findOrFail($id);
        $is->ropas()->detach($ropaId);
        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'data_discovery', 'record_id' => $is->id,
            'action' => 'is.ropa_detach',
            'details' => ['ropa_id' => $ropaId],
        ]);

        return response()->json(['message' => 'Link dilepas.']);
    }

    // =====================================================================
    // CONSENT COLLECTION ↔ RoPA
    // =====================================================================
    public function indexForConsent(Request $request, string $id)
    {
        $user = $request->user();
        $cp = ConsentCollectionPoint::where('org_id', $user->org_id)->findOrFail($id);

        return response()->json(['data' => $cp->ropas()->select('ropas.id', 'ropas.registration_number', 'ropas.processing_activity', 'ropas.risk_level')->get()]);
    }

    public function syncForConsent(Request $request, string $id)
    {
        $user = $request->user();
        $cp = ConsentCollectionPoint::where('org_id', $user->org_id)->findOrFail($id);

        $data = $request->validate([
            'ropa_ids' => 'required|array',
            'ropa_ids.*' => 'uuid',
        ]);

        $valid = Ropa::whereIn('id', $data['ropa_ids'])->where('org_id', $user->org_id)->pluck('id')->all();
        $syncData = [];
        foreach ($valid as $rid) {
            $syncData[$rid] = ['org_id' => $user->org_id];
        }
        $cp->ropas()->sync($syncData);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'consent', 'record_id' => $cp->id,
            'action' => 'consent.ropas_sync',
            'details' => ['ropa_ids' => $valid, 'count' => count($valid)],
        ]);

        return response()->json([
            'message' => count($valid).' RoPA tersinkron.',
            'data' => $cp->ropas()->select('ropas.id', 'ropas.registration_number', 'ropas.processing_activity')->get(),
        ]);
    }

    // =====================================================================
    // DSR DERIVED RoPAs (via scope → information_system → ropas)
    // =====================================================================
    public function affectedRopasForDsr(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->with(['scopes.informationSystem.ropas'])->findOrFail($id);

        $byRopa = [];
        foreach ($dsr->scopes as $scope) {
            $is = $scope->informationSystem;
            if (! $is) {
                continue;
            }
            foreach ($is->ropas as $ropa) {
                if (! isset($byRopa[$ropa->id])) {
                    $byRopa[$ropa->id] = [
                        'ropa_id' => $ropa->id,
                        'registration_number' => $ropa->registration_number,
                        'processing_activity' => $ropa->processing_activity,
                        'risk_level' => $ropa->risk_level,
                        'triggered_by_systems' => [],
                    ];
                }
                $byRopa[$ropa->id]['triggered_by_systems'][] = [
                    'id' => $is->id,
                    'name' => $is->name,
                    'shards' => $scope->shards_affected ?? [],
                ];
            }
        }

        return response()->json([
            'dsr_id' => $dsr->id,
            'request_id' => $dsr->request_id,
            'data' => array_values($byRopa),
            'count' => count($byRopa),
            'note' => count($byRopa) === 0
                ? 'Belum ada RoPA yang ter-link ke Information Systems di scope ini. Link IS ke RoPA via Data Discovery untuk auto-discover.'
                : null,
        ]);
    }
}
