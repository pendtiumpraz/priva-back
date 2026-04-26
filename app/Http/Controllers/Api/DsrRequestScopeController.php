<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DsrApp;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use App\Models\InformationSystem;
use Illuminate\Http\Request;

/**
 * DSR Scope picker — DPO assign Information Systems yang affected per DSR.
 *
 * Routes:
 *   GET    /api/dsr/{id}/scopes                  — list current scopes
 *   GET    /api/dsr/{id}/available-systems       — list IS available for scoping (with default suggestion)
 *   POST   /api/dsr/{id}/scopes                  — bulk assign scopes (idempotent upsert)
 *   PUT    /api/dsr/{id}/scopes/{scopeId}        — update single scope (shards/types)
 *   DELETE /api/dsr/{id}/scopes/{scopeId}        — remove scope
 */
class DsrRequestScopeController extends Controller
{
    /**
     * GET /api/dsr/{id}/scopes
     * List all scopes for a DSR with information system + execution status.
     */
    public function index(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        $scopes = $dsr->scopes()
            ->with(['informationSystem' => fn($q) => $q->select(['id', 'name', 'code', 'is_sharded', 'shards', 'org_id'])])
            ->get()
            ->map(function ($s) use ($dsr) {
                $exec = $dsr->executions()
                    ->where('information_system_id', $s->information_system_id)
                    ->get();
                $execByShard = [];
                foreach ($exec as $e) {
                    $execByShard[$e->shard_name ?? '_default'][$e->request_type] = [
                        'status' => $e->status,
                        'rows_affected' => $e->rows_affected,
                        'executed_at' => $e->executed_at,
                    ];
                }
                return [
                    'id' => $s->id,
                    'information_system_id' => $s->information_system_id,
                    'information_system' => $s->informationSystem,
                    'shards_affected' => $s->shards_affected ?? [],
                    'request_types' => $s->request_types ?? [],
                    'sql_pack_status' => $s->sql_pack_status,
                    'sql_pack_url' => $s->sql_pack_url,
                    'sql_pack_generated_at' => $s->sql_pack_generated_at,
                    'sql_pack_downloaded_at' => $s->sql_pack_downloaded_at,
                    'executions_by_shard' => $execByShard,
                ];
            });

        return response()->json([
            'data' => $scopes,
            'dsr' => [
                'id' => $dsr->id,
                'request_id' => $dsr->request_id,
                'request_type' => $dsr->request_type,
                'status' => $dsr->status,
                'app_id' => $dsr->app_id,
            ],
        ]);
    }

    /**
     * GET /api/dsr/{id}/available-systems
     * Return Information Systems yang bisa di-scope, plus default suggestion
     * dari DsrApp.default_information_system_ids (kalau DSR submitted via app).
     */
    public function availableSystems(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        $allSystems = InformationSystem::where('org_id', $user->org_id)
            ->select(['id', 'name', 'code', 'description', 'is_sharded', 'shards', 'connection_type'])
            ->orderBy('name')
            ->get();

        // Mark which already scoped
        $scopedIds = $dsr->scopes()->pluck('information_system_id')->all();

        // Get default scope from app (kalau DSR linked to app)
        $defaultIds = [];
        if ($dsr->app_id) {
            $app = DsrApp::find($dsr->app_id);
            if ($app) $defaultIds = $app->default_information_system_ids ?? [];
        }

        return response()->json([
            'data' => $allSystems->map(fn($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'code' => $s->code,
                'description' => $s->description,
                'is_sharded' => $s->is_sharded ?? false,
                'shards' => $s->shards ?? [],
                'connection_type' => $s->connection_type,
                '_already_scoped' => in_array($s->id, $scopedIds, true),
                '_is_default' => in_array($s->id, $defaultIds, true),
            ]),
            'defaults' => $defaultIds,
        ]);
    }

    /**
     * POST /api/dsr/{id}/scopes
     * Bulk assign scopes (idempotent upsert). Replaces existing scopes if mode=replace.
     *
     * Body: {
     *   mode: "replace" | "merge",  // default merge
     *   scopes: [
     *     {
     *       information_system_id,
     *       shards_affected: ["shard_01", "shard_02"]?,
     *       request_types: ["deletion", "withdraw_consent"]
     *     }
     *   ]
     * }
     */
    public function store(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        $data = $request->validate([
            'mode' => 'nullable|in:replace,merge',
            'scopes' => 'required|array|min:1',
            'scopes.*.information_system_id' => 'required|uuid',
            'scopes.*.shards_affected' => 'nullable|array',
            'scopes.*.shards_affected.*' => 'string|max:100',
            'scopes.*.request_types' => 'required|array|min:1',
            'scopes.*.request_types.*' => 'string|in:' . implode(',', DsrRequest::REQUEST_TYPES),
        ]);

        $mode = $data['mode'] ?? 'merge';

        // Verify all IS belong to tenant
        $isIds = collect($data['scopes'])->pluck('information_system_id')->unique();
        $validCount = InformationSystem::whereIn('id', $isIds)->where('org_id', $user->org_id)->count();
        if ($validCount !== $isIds->count()) {
            return response()->json([
                'error' => 'Some Information Systems tidak ditemukan atau bukan milik tenant Anda.',
            ], 422);
        }

        if ($mode === 'replace') {
            // Hapus semua scopes existing dulu
            $dsr->scopes()->delete();
        }

        $created = [];
        $updated = [];
        foreach ($data['scopes'] as $scope) {
            $existing = DsrRequestScope::where('dsr_request_id', $dsr->id)
                ->where('information_system_id', $scope['information_system_id'])
                ->first();

            if ($existing) {
                $existing->update([
                    'shards_affected' => $scope['shards_affected'] ?? [],
                    'request_types' => $scope['request_types'],
                ]);
                $updated[] = $existing->id;
            } else {
                $new = DsrRequestScope::create([
                    'dsr_request_id' => $dsr->id,
                    'information_system_id' => $scope['information_system_id'],
                    'shards_affected' => $scope['shards_affected'] ?? [],
                    'request_types' => $scope['request_types'],
                    'sql_pack_status' => 'pending',
                ]);
                $created[] = $new->id;
            }
        }

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.scope_assign',
            'details' => [
                'mode' => $mode,
                'created_count' => count($created),
                'updated_count' => count($updated),
                'systems' => $isIds->all(),
            ],
        ]);

        // Auto-update DSR status: kalau masih pending_review + scope assigned → in_progress
        if ($dsr->status === 'pending_review' && !empty($created)) {
            $dsr->update(['status' => 'in_progress']);
        }

        return response()->json([
            'message' => count($created) . ' scopes added, ' . count($updated) . ' updated.',
            'created' => $created,
            'updated' => $updated,
            'data' => $this->index($request, $id)->original['data'],
        ], 201);
    }

    /**
     * PUT /api/dsr/{id}/scopes/{scopeId}
     * Update single scope (shards / request_types).
     */
    public function update(Request $request, string $id, string $scopeId)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);
        $scope = DsrRequestScope::where('dsr_request_id', $dsr->id)->findOrFail($scopeId);

        $data = $request->validate([
            'shards_affected' => 'sometimes|nullable|array',
            'shards_affected.*' => 'string|max:100',
            'request_types' => 'sometimes|array|min:1',
            'request_types.*' => 'string|in:' . implode(',', DsrRequest::REQUEST_TYPES),
        ]);

        $scope->update($data);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.scope_update',
            'details' => [
                'scope_id' => $scope->id,
                'fields_changed' => array_keys($data),
            ],
        ]);

        return response()->json(['message' => 'Scope updated', 'data' => $scope->fresh()]);
    }

    /**
     * DELETE /api/dsr/{id}/scopes/{scopeId}
     * Remove scope (along with its executions).
     */
    public function destroy(Request $request, string $id, string $scopeId)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);
        $scope = DsrRequestScope::where('dsr_request_id', $dsr->id)->findOrFail($scopeId);

        // Block kalau sudah ada execution evidence — DPO harus hapus execution dulu
        $execCount = $dsr->executions()
            ->where('information_system_id', $scope->information_system_id)
            ->whereNotIn('status', ['pending'])
            ->count();
        if ($execCount > 0) {
            return response()->json([
                'error' => 'Tidak bisa hapus scope — sudah ada ' . $execCount . ' execution evidence. Hapus execution dulu kalau memang mau remove scope.',
            ], 422);
        }

        $isId = $scope->information_system_id;
        $scope->delete();

        // Hapus juga pending executions untuk IS ini (kalau ada)
        $dsr->executions()
            ->where('information_system_id', $isId)
            ->where('status', 'pending')
            ->delete();

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.scope_delete',
            'details' => ['scope_id' => $scopeId, 'information_system_id' => $isId],
        ]);

        return response()->json(['message' => 'Scope removed']);
    }
}
