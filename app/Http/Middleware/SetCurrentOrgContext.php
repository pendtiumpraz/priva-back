<?php

namespace App\Http\Middleware;

use App\Services\CurrentOrgContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Populate CurrentOrgContext from the authenticated user's org_id so that
 * the BelongsToOrg trait's global scope kicks in for every query in this
 * request. Runs after auth:sanctum.
 *
 * Behaviour:
 *   - No authenticated user → context stays null → no scope applied (the
 *     route is presumably a public endpoint or super-admin tool).
 *   - User has org_id → context is set → tenant-scoped models filter by it.
 *   - User is root/superadmin operating cross-tenant → they should still
 *     have an org_id (their home org); routes that need cross-tenant view
 *     must explicitly call `Model::withoutGlobalScope('org')`.
 *
 * Cleanup happens implicitly: the singleton is rebuilt per request because
 * the framework boots a fresh container per request in php-fpm. For long-
 * running workers (Octane, queue), the worker should call
 * `app(CurrentOrgContext::class)->clear()` between jobs.
 */
class SetCurrentOrgContext
{
    public function __construct(private CurrentOrgContext $context) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user && !empty($user->org_id)) {
            $this->context->set($user->org_id);
        }

        return $next($request);
    }
}
