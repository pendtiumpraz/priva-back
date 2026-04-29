<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Block mutating requests when the tenant is mid-migration so we don't
 * write to the source DB after we've started copying data. Read-only
 * requests pass through.
 *
 * Triggered states (from organizations.tenant_db_state):
 *   - 'freezing'  → tenant is being prepared for cutover; writes blocked
 *   - 'migrating' → data copy in progress; writes blocked
 *
 * 'shared' and 'isolated' tenants pass freely. 'provisioning' is for
 * brand-new tenants where there's no data to protect, so writes are also
 * allowed (the tenant DB doesn't exist yet anyway).
 *
 * Runs after auth:sanctum + tenant.context. Order matters — we need to
 * know which org the caller belongs to. Returns 503 with a clear message
 * so the frontend can show "tenant under migration" UX.
 */
class EnforceTenantReadOnly
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const BLOCKED_STATES = ['freezing', 'migrating'];

    public function handle(Request $request, Closure $next)
    {
        // Read methods always allowed — migration is read-friendly
        if (in_array($request->method(), self::READ_METHODS, true)) {
            return $next($request);
        }

        $user = $request->user();
        if (!$user || !$user->org_id) {
            return $next($request);
        }

        $org = $user->organization;
        if (!$org) return $next($request);

        if (in_array($org->tenant_db_state, self::BLOCKED_STATES, true)) {
            return response()->json([
                'message' => "Tenant '{$org->name}' is in maintenance mode (state={$org->tenant_db_state}). Read-only access is allowed; writes are blocked until migration completes.",
                'tenant_db_state' => $org->tenant_db_state,
                'retry_after_seconds' => 60,
            ], 503, ['Retry-After' => 60]);
        }

        return $next($request);
    }
}
