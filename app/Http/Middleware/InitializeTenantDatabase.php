<?php

namespace App\Http\Middleware;

use App\Services\TenantDb\TenantDatabaseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Switch the default DB connection for this request to the tenant's
 * dedicated database when the tenant is in 'isolated' state. Runs after
 * `auth:sanctum` and `tenant.context` so the user + org are resolved.
 *
 * If the tenant is still on shared landlord DB (Tier 1) or mid-migration,
 * this middleware is a no-op — queries continue against the default
 * Laravel connection.
 *
 * Order in middleware stack matters:
 *   auth:sanctum → tenant.context → tenant.db
 *
 * Models that should ALWAYS hit landlord (User, Organization, License,
 * AppSetting, MenuItem, DatabasePool, StoragePool, TenantChangeRequest)
 * declare `protected $connection = 'landlord'` so they're immune to this
 * switch. TODO M2.5: actually set $connection on those models.
 */
class InitializeTenantDatabase
{
    public function __construct(private TenantDatabaseService $service) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user) return $next($request);

        $org = $user->organization;
        if (!$org) return $next($request);

        // Only switch when tenant is fully isolated. Mid-migration states
        // (provisioning/migrating) keep the request on shared landlord
        // DB so writes don't land in a half-built database.
        if ($org->tenant_db_state !== 'isolated') {
            return $next($request);
        }

        $connectionName = $this->service->getConnection($org);
        if ($connectionName !== $this->service->landlordConnectionName()) {
            DB::setDefaultConnection($connectionName);
        }

        return $next($request);
    }
}
