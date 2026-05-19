<?php

namespace App\Http\Middleware;

use App\Services\CurrentOrgContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * Postgres RLS integration (vector_embeddings):
 *   - Sets `app.current_org_id` session variable di Postgres connection
 *     supaya RLS policy `vector_embeddings_tenant_isolation` punya konteks
 *     untuk filter row. Tanpa ini, RLS reject SEMUA row.
 *   - Untuk root/superadmin: set `app.bypass_rls = 'true'` supaya bisa
 *     cross-tenant query.
 *   - RESET di terminate() supaya nilai tidak bocor ke request berikutnya
 *     yang share connection pool.
 *   - Cuma dijalanin di pgsql driver (no-op untuk MySQL/SQLite).
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
            $this->setPostgresSessionVars($user->org_id, $user->role ?? null);
        } elseif ($user && in_array($user->role ?? null, ['root', 'superadmin'], true)) {
            // SuperAdmin tanpa org_id (platform tools) — bypass RLS.
            $this->setPostgresSessionVars(null, $user->role);
        }

        return $next($request);
    }

    /**
     * Reset session variables setelah response sent supaya connection
     * pool tidak leak konteks ke request berikutnya. Laravel auto-call
     * terminate() setelah HTTP response selesai dikirim.
     */
    public function terminate(Request $request, $response): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        try {
            DB::statement("RESET app.current_org_id");
            DB::statement("RESET app.bypass_rls");
        } catch (\Throwable $e) {
            // Connection mungkin sudah closed, abaikan
        }
    }

    /**
     * Set Postgres session variables untuk RLS policy.
     *
     * Pakai `SET` (session-level), bukan `SET LOCAL` (transaction-level)
     * supaya berlaku untuk seluruh request lifecycle. Cleanup di terminate().
     *
     * Skip kalau driver bukan pgsql (MySQL/SQLite tidak punya RLS).
     */
    private function setPostgresSessionVars(?string $orgId, ?string $role): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;

        try {
            if ($orgId) {
                // Pakai bindings supaya UUID di-escape proper
                DB::statement("SELECT set_config('app.current_org_id', ?, false)", [$orgId]);
            }

            // Bypass RLS untuk root/superadmin — mereka boleh cross-tenant query
            if (in_array($role, ['root', 'superadmin'], true)) {
                DB::statement("SELECT set_config('app.bypass_rls', 'true', false)");
            }
        } catch (\Throwable $e) {
            // Jangan kill request hanya karena RLS setup gagal — log doang.
            // Worst case: RLS akan reject query (fail closed) — tetap aman.
            Log::warning('SetCurrentOrgContext: failed to set Postgres session vars', [
                'error' => $e->getMessage(),
                'org_id' => $orgId,
            ]);
        }
    }
}
