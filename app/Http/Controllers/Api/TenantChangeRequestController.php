<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionTenantDatabaseJob;
use App\Models\AuditLog;
use App\Models\DatabasePool;
use App\Models\Organization;
use App\Models\StoragePool;
use App\Models\TenantChangeRequest;
use App\Services\TenantDb\TenantDatabaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Workflow approval for tenant-initiated infrastructure changes.
 *
 * Surface split:
 *   - Tenant admin endpoints (under auth:sanctum + tenant.context):
 *       POST /api/tenant/change-requests
 *       GET  /api/tenant/change-requests
 *
 *   - Root/superadmin endpoints (under role.root):
 *       GET  /api/platform-admin/change-requests           — approval queue
 *       GET  /api/platform-admin/change-requests/{id}
 *       POST /api/platform-admin/change-requests/{id}/approve
 *       POST /api/platform-admin/change-requests/{id}/deny
 *
 * The approval action dispatches the appropriate job (currently only
 * ProvisionTenantDatabaseJob; storage moves and BYODB switches are
 * follow-up work).
 */
class TenantChangeRequestController extends Controller
{
    public function __construct(protected TenantDatabaseService $dbService) {}

    // ─── Tenant admin surface ──────────────────────────────────────────────

    /**
     * Tenant admin submits a request. Payload shape varies by request_type
     * — for db_assign_pool / db_change_pool the tenant suggests a target
     * pool (or leaves blank for superadmin to pick); for byodb switches
     * the tenant supplies their own DB credentials which the superadmin
     * will validate before executing.
     */
    public function tenantStore(Request $request)
    {
        $data = $request->validate([
            'request_type' => ['required', Rule::in([
                TenantChangeRequest::TYPE_DB_ASSIGN_POOL,
                TenantChangeRequest::TYPE_DB_CHANGE_POOL,
                TenantChangeRequest::TYPE_DB_SWITCH_TO_BYODB,
                TenantChangeRequest::TYPE_STORAGE_ASSIGN_POOL,
                TenantChangeRequest::TYPE_STORAGE_CHANGE_POOL,
                TenantChangeRequest::TYPE_STORAGE_SWITCH_TO_BYOS,
            ])],
            'payload' => 'required|array',
            'reason' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();
        $orgId = $user->org_id;
        if (!$orgId) {
            return response()->json(['message' => 'No tenant context — only tenant users can submit infrastructure changes.'], 403);
        }

        // Refuse duplicate pending requests of the same type for the same org
        $existing = TenantChangeRequest::query()
            ->where('org_id', $orgId)
            ->where('request_type', $data['request_type'])
            ->where('status', TenantChangeRequest::STATUS_PENDING)
            ->first();
        if ($existing) {
            return response()->json([
                'message' => 'A pending request of this type already exists for your tenant.',
                'data' => $existing,
            ], 409);
        }

        $req = TenantChangeRequest::create([
            'org_id' => $orgId,
            'requested_by' => $user->id,
            'request_type' => $data['request_type'],
            'payload' => $data['payload'],
            'reason' => $data['reason'] ?? null,
            'status' => TenantChangeRequest::STATUS_PENDING,
        ]);

        AuditLog::log('tenant_change_request', $req->id, 'submitted', [
            'request_type' => $req->request_type,
            'org_id' => $orgId,
        ], 'manual');

        return response()->json([
            'message' => 'Request submitted. Superadmin will review.',
            'data' => $req,
        ], 201);
    }

    /**
     * Tenant admin lists their own org's requests.
     */
    public function tenantIndex(Request $request)
    {
        $orgId = $request->user()->org_id;
        if (!$orgId) {
            return response()->json(['message' => 'No tenant context.'], 403);
        }

        $reqs = TenantChangeRequest::query()
            ->where('org_id', $orgId)
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json(['data' => $reqs]);
    }

    // ─── Superadmin approval queue ─────────────────────────────────────────

    public function adminIndex(Request $request)
    {
        $query = TenantChangeRequest::query()->with(['organization:id,name,slug', 'requester:id,name,email']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('request_type')) {
            $query->where('request_type', $request->request_type);
        }

        $reqs = $query->orderBy('status')   // pending first
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json(['data' => $reqs]);
    }

    public function adminShow(string $id)
    {
        $req = TenantChangeRequest::query()
            ->with(['organization', 'requester:id,name,email', 'reviewer:id,name,email'])
            ->findOrFail($id);
        return response()->json(['data' => $req]);
    }

    /**
     * Approve and execute. For DB pool assignments this dispatches
     * ProvisionTenantDatabaseJob with the change_request_id linked, so
     * the job updates the request's status as it progresses.
     *
     * For storage moves and BYODB switches we currently mark approved
     * but leave execution as a manual step — those flows are richer
     * (data migration, BYODB credential validation) and ship after the
     * FI launch.
     */
    public function approve(Request $request, string $id)
    {
        $data = $request->validate([
            'pool_id' => 'nullable|uuid|exists:database_pools,id',
            'storage_pool_id' => 'nullable|uuid|exists:storage_pools,id',
            'review_notes' => 'nullable|string|max:2000',
        ]);

        $req = TenantChangeRequest::query()->findOrFail($id);
        if ($req->status !== TenantChangeRequest::STATUS_PENDING) {
            return response()->json(['message' => "Request is no longer pending (status={$req->status})."], 409);
        }

        $org = Organization::query()->findOrFail($req->org_id);

        // Mark approved + executing inside transaction so a failure in
        // job dispatch doesn't leave a half-state.
        DB::transaction(function () use ($req, $request, $data) {
            $req->status = TenantChangeRequest::STATUS_APPROVED;
            $req->reviewed_by = $request->user()?->id;
            $req->reviewed_at = now();
            $req->review_notes = $data['review_notes'] ?? null;

            // Persist the resolved pool ids back into payload for traceability
            if (!empty($data['pool_id'])) {
                $req->payload = array_merge($req->payload ?? [], ['resolved_pool_id' => $data['pool_id']]);
            }
            if (!empty($data['storage_pool_id'])) {
                $req->payload = array_merge($req->payload ?? [], ['resolved_storage_pool_id' => $data['storage_pool_id']]);
            }

            $req->save();
        });

        // Dispatch the appropriate executor based on request_type
        try {
            switch ($req->request_type) {
                case TenantChangeRequest::TYPE_DB_ASSIGN_POOL:
                case TenantChangeRequest::TYPE_DB_CHANGE_POOL:
                    $poolId = $data['pool_id']
                        ?? $req->payload['target_pool_id']
                        ?? $req->payload['resolved_pool_id']
                        ?? null;
                    if (!$poolId) {
                        $req->status = TenantChangeRequest::STATUS_FAILED;
                        $req->error = 'No target pool resolved at approval time.';
                        $req->save();
                        return response()->json(['message' => 'pool_id is required for DB assign/change.'], 422);
                    }

                    $req->status = TenantChangeRequest::STATUS_EXECUTING;
                    $req->save();

                    ProvisionTenantDatabaseJob::dispatch($org->id, $poolId, $req->id);
                    break;

                case TenantChangeRequest::TYPE_STORAGE_ASSIGN_POOL:
                case TenantChangeRequest::TYPE_STORAGE_CHANGE_POOL:
                    // Lightweight — no provisioning, just persist the pool assignment.
                    // No data migration here; M7 covers existing-data moves.
                    $storagePoolId = $data['storage_pool_id']
                        ?? $req->payload['target_storage_pool_id']
                        ?? $req->payload['resolved_storage_pool_id']
                        ?? null;
                    if (!$storagePoolId) {
                        $req->status = TenantChangeRequest::STATUS_FAILED;
                        $req->error = 'No target storage pool resolved at approval time.';
                        $req->save();
                        return response()->json(['message' => 'storage_pool_id is required for storage assign/change.'], 422);
                    }
                    $org->storage_pool_id = $storagePoolId;
                    $org->save();

                    $req->status = TenantChangeRequest::STATUS_EXECUTED;
                    $req->executed_at = now();
                    $req->save();
                    break;

                case TenantChangeRequest::TYPE_DB_SWITCH_TO_BYODB:
                case TenantChangeRequest::TYPE_STORAGE_SWITCH_TO_BYOS:
                    // BYODB / BYOS switches require a credential review step
                    // that's not automated in this iteration. Mark approved,
                    // operator runs the artisan command manually after
                    // confirming credentials with the client.
                    // Leave at 'approved' — caller is expected to follow up
                    // with manual ops.
                    break;

                default:
                    $req->status = TenantChangeRequest::STATUS_FAILED;
                    $req->error = "Unsupported request_type: {$req->request_type}";
                    $req->save();
                    return response()->json(['message' => 'Unsupported request_type.'], 422);
            }
        } catch (\Throwable $e) {
            $req->status = TenantChangeRequest::STATUS_FAILED;
            $req->error = $e->getMessage();
            $req->save();
            return response()->json(['message' => 'Approval saved but execution failed: ' . $e->getMessage()], 500);
        }

        AuditLog::log('tenant_change_request', $req->id, 'approved', [
            'request_type' => $req->request_type,
            'org_id' => $req->org_id,
            'reviewer' => $request->user()?->id,
            'final_status' => $req->status,
        ], 'manual');

        return response()->json([
            'message' => "Request approved (status={$req->status}).",
            'data' => $req->fresh(),
        ]);
    }

    public function deny(Request $request, string $id)
    {
        $data = $request->validate([
            'review_notes' => 'required|string|max:2000',
        ]);

        $req = TenantChangeRequest::query()->findOrFail($id);
        if ($req->status !== TenantChangeRequest::STATUS_PENDING) {
            return response()->json(['message' => "Request is no longer pending (status={$req->status})."], 409);
        }

        $req->status = TenantChangeRequest::STATUS_DENIED;
        $req->reviewed_by = $request->user()?->id;
        $req->reviewed_at = now();
        $req->review_notes = $data['review_notes'];
        $req->save();

        AuditLog::log('tenant_change_request', $req->id, 'denied', [
            'request_type' => $req->request_type,
            'org_id' => $req->org_id,
            'reviewer' => $request->user()?->id,
            'reason' => $data['review_notes'],
        ], 'manual');

        return response()->json([
            'message' => 'Request denied.',
            'data' => $req,
        ]);
    }
}
