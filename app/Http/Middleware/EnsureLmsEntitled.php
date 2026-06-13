<?php

namespace App\Http\Middleware;

use App\Models\MenuItem;
use App\Models\TenantModuleEntitlement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLmsEntitled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('lms.enabled', false)) {
            return response()->json(
                ['message' => 'LMS is currently disabled platform-wide.'],
                503
            );
        }

        $user = $request->user();

        // Platform roles (root/superadmin) have no tenant assignment and bypass
        // the per-tenant entitlement check — they have platform-wide access by
        // definition. Mirrors the bypass in CheckPermission.
        if ($user && in_array($user->role ?? null, ['root', 'superadmin'], true)) {
            return $next($request);
        }

        if (! $user || ! $user->org_id) {
            return response()->json(['message' => 'No organisation context.'], 403);
        }

        // LMS is entitled for ALL tenants by DEFAULT (including future orgs).
        // Root/superadmin can disable it for a specific tenant by recording an
        // explicit revoke (is_entitled=false) via Menu Registry / the platform
        // entitlement toggle. Deny ONLY when such a revoke row exists; otherwise
        // allow. (Mirrors MenuRegistryService, which hides the 'lms' menu for a
        // tenant only when explicitly revoked.)
        $lmsMenuItem = MenuItem::where('menu_key', 'lms')->first();

        if ($lmsMenuItem) {
            $revoked = TenantModuleEntitlement::query()
                ->where('org_id', $user->org_id)
                ->where('menu_id', $lmsMenuItem->id)
                ->where('is_entitled', false)
                ->exists();

            if ($revoked) {
                return response()->json([
                    'message' => 'Akses LMS dinonaktifkan untuk organisasi Anda oleh administrator platform.',
                    'code' => 'LMS_DISABLED_FOR_TENANT',
                ], 403);
            }
        }

        return $next($request);
    }
}
