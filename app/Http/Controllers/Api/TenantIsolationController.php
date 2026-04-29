<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\MigrateTenantDataJob;
use App\Jobs\ProvisionTenantDatabaseJob;
use App\Models\AuditLog;
use App\Models\DatabasePool;
use App\Models\Organization;
use App\Services\TenantDb\DatabasePoolRegistry;
use App\Services\TenantDb\PrivasimuHostedProvisioner;
use App\Services\TenantDb\TenantDatabaseService;
use App\Services\TenantDb\TenantDataMigrator;
use Illuminate\Http\Request;

/**
 * Root/superadmin endpoints to inspect and manage per-tenant database
 * isolation. Pairs with the (dashboard)/platform-admin/tenants page on
 * the frontend.
 *
 * The provision/deprovision actions write to the tenant's
 * organizations.tenant_db_state via ProvisionTenantDatabaseJob, which
 * runs the actual DDL + migration work asynchronously. Frontend polls
 * the status endpoint while state is in transit.
 *
 * Gated by `role.root` middleware at the route level.
 */
class TenantIsolationController extends Controller
{
    public function __construct(
        protected DatabasePoolRegistry $registry,
        protected PrivasimuHostedProvisioner $provisioner,
        protected TenantDatabaseService $dbService,
        protected TenantDataMigrator $migrator,
    ) {}

    /**
     * List all tenants with their current isolation state. Useful for
     * the superadmin dashboard to see who's still on shared DB and who's
     * already migrated.
     */
    public function index(Request $request)
    {
        $query = Organization::query()
            ->withoutGlobalScope('org')   // landlord-pinned but defensive — superadmin spans tenants
            ->whereNull('deleted_at');

        if ($request->filled('state')) {
            $query->where('tenant_db_state', $request->state);
        }
        if ($request->filled('provider')) {
            $query->where('tenant_db_provider', $request->provider);
        }
        if ($request->filled('q')) {
            $term = '%' . $request->q . '%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhere('industry', 'like', $term));
        }

        $orgs = $query
            ->orderBy('tenant_db_state')   // shared first, isolated grouped together
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        $orgs->getCollection()->transform(fn ($o) => $this->presentTenant($o));

        return response()->json(['data' => $orgs]);
    }

    /**
     * Detail view for one tenant — current isolation state plus the pool
     * they're in (if any). The encrypted tenant_db_config is NEVER
     * returned even with secrets stripped, since just exposing the
     * database name + host is enough for the superadmin UI.
     */
    public function show(string $id)
    {
        $org = Organization::query()->whereNull('deleted_at')->findOrFail($id);
        return response()->json(['data' => $this->presentTenant($org, includeDetail: true)]);
    }

    /**
     * Dispatch a provisioning job. Auto-picks an active pool if not
     * specified, otherwise uses the pool the caller chose. Refuses if
     * the tenant is already isolated.
     */
    public function provision(Request $request, string $id)
    {
        $data = $request->validate([
            'pool_id' => 'nullable|uuid|exists:database_pools,id',
            'sync' => 'nullable|boolean',  // run inline (mostly for ops smoke testing)
        ]);

        $org = Organization::query()->whereNull('deleted_at')->findOrFail($id);

        if ($org->tenant_db_state === 'isolated') {
            return response()->json([
                'message' => "Tenant '{$org->name}' is already isolated.",
                'data' => $this->presentTenant($org, includeDetail: true),
            ], 409);
        }
        if (in_array($org->tenant_db_state, ['provisioning', 'migrating'], true)) {
            return response()->json([
                'message' => "Tenant '{$org->name}' is already in state '{$org->tenant_db_state}'. Wait for it to finish or fail.",
            ], 409);
        }

        $pool = !empty($data['pool_id'])
            ? DatabasePool::query()->whereNull('deleted_at')->findOrFail($data['pool_id'])
            : $this->registry->findActivePool();

        if (!$pool) {
            return response()->json(['message' => 'No active database pool with available capacity. Create one first.'], 422);
        }
        if (!$pool->isAcceptingTenants()) {
            return response()->json(['message' => "Pool '{$pool->name}' is not accepting new tenants (status={$pool->status}, count={$pool->current_tenants_count}/{$pool->max_tenants})."], 422);
        }

        AuditLog::log('tenant_isolation', $org->id, 'provision_dispatched', [
            'pool_id' => $pool->id, 'pool_name' => $pool->name, 'requested_by' => $request->user()?->id,
        ], 'manual');

        if (!empty($data['sync'])) {
            // Run inline — useful for ops smoke testing where you want errors back synchronously.
            (new ProvisionTenantDatabaseJob($org->id, $pool->id))->handle(
                $this->provisioner,
                $this->registry,
                $this->dbService,
            );
        } else {
            ProvisionTenantDatabaseJob::dispatch($org->id, $pool->id);
        }

        return response()->json([
            'message' => "Provisioning started for '{$org->name}' on pool '{$pool->name}'. Poll /api/platform-admin/tenants/{$org->id}/isolation/status for progress.",
            'data' => $this->presentTenant($org->fresh(), includeDetail: true),
        ], 202);
    }

    /**
     * Polling endpoint — returns just the lifecycle fields so the frontend
     * doesn't have to refetch the whole tenant payload while waiting.
     */
    public function status(string $id)
    {
        $org = Organization::query()->whereNull('deleted_at')->findOrFail($id);
        return response()->json([
            'data' => [
                'id' => $org->id,
                'tenant_db_provider' => $org->tenant_db_provider,
                'tenant_db_state' => $org->tenant_db_state,
                'tenant_db_provisioned_at' => $org->tenant_db_provisioned_at,
                'tenant_db_isolated_at' => $org->tenant_db_isolated_at,
                'tenant_db_error' => $org->tenant_db_error,
            ],
        ]);
    }

    /**
     * Upgrade an existing tenant (with data) from shared → dedicated pool.
     * Differs from `provision` which is for brand-new tenants — this one
     * runs the full migration pipeline (freeze → copy → verify → cutover).
     */
    public function migrate(Request $request, string $id)
    {
        $data = $request->validate([
            'pool_id' => 'nullable|uuid|exists:database_pools,id',
            'sync' => 'nullable|boolean',
        ]);

        $org = Organization::query()->whereNull('deleted_at')->findOrFail($id);

        if ($org->tenant_db_state !== 'shared') {
            return response()->json([
                'message' => "Tenant must be in 'shared' state to migrate. Current: '{$org->tenant_db_state}'.",
            ], 409);
        }

        $pool = !empty($data['pool_id'])
            ? DatabasePool::query()->whereNull('deleted_at')->findOrFail($data['pool_id'])
            : $this->registry->findActivePool();

        if (!$pool || !$pool->isAcceptingTenants()) {
            return response()->json(['message' => 'No active pool with capacity available.'], 422);
        }

        AuditLog::log('tenant_isolation', $org->id, 'migrate_dispatched', [
            'pool_id' => $pool->id, 'pool_name' => $pool->name, 'requested_by' => $request->user()?->id,
        ], 'manual');

        if (!empty($data['sync'])) {
            (new MigrateTenantDataJob($org->id, $pool->id))->handle(
                $this->provisioner, $this->registry, $this->dbService, $this->migrator,
            );
        } else {
            MigrateTenantDataJob::dispatch($org->id, $pool->id);
        }

        return response()->json([
            'message' => "Migration started for '{$org->name}'. The tenant will be temporarily read-only during the freeze + copy phases. Poll status for progress.",
            'data' => $this->presentTenant($org->fresh(), includeDetail: true),
        ], 202);
    }

    /**
     * Reset a failed provisioning back to 'shared' so the operator can
     * retry. Only allowed when state is 'failed' to avoid accidentally
     * clearing an in-progress migration.
     */
    public function resetFailed(Request $request, string $id)
    {
        $org = Organization::query()->whereNull('deleted_at')->findOrFail($id);

        if ($org->tenant_db_state !== 'failed') {
            return response()->json(['message' => "Reset only allowed from state='failed'; current is '{$org->tenant_db_state}'."], 409);
        }

        $org->tenant_db_state = 'shared';
        $org->tenant_db_provider = 'shared';
        $org->tenant_db_error = null;
        $org->save();

        AuditLog::log('tenant_isolation', $org->id, 'reset_failed_state', [
            'requested_by' => $request->user()?->id,
        ], 'manual');

        return response()->json([
            'message' => "Tenant '{$org->name}' reset to 'shared'. You can now retry provisioning.",
            'data' => $this->presentTenant($org, includeDetail: true),
        ]);
    }

    private function presentTenant(Organization $org, bool $includeDetail = false): array
    {
        $base = [
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'industry' => $org->industry,
            'tenant_db_provider' => $org->tenant_db_provider,
            'tenant_db_state' => $org->tenant_db_state,
            'tenant_db_provisioned_at' => $org->tenant_db_provisioned_at,
            'tenant_db_isolated_at' => $org->tenant_db_isolated_at,
            'tenant_db_error' => $org->tenant_db_error,
            'db_pool_id' => $org->db_pool_id,
            'storage_pool_id' => $org->storage_pool_id,
            'has_storage_override' => !empty($org->storage_driver) && !empty($org->storage_config),
            'created_at' => $org->created_at,
        ];

        if ($includeDetail) {
            // Eager-load pool name (small cost; admin context only)
            $base['db_pool'] = $org->db_pool_id
                ? DatabasePool::query()->where('id', $org->db_pool_id)->first(['id', 'name', 'engine', 'host', 'port', 'region'])
                : null;
        }

        return $base;
    }
}
