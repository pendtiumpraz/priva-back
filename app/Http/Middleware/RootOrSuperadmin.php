<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate untuk endpoint yang hanya boleh diakses oleh role 'root' atau 'superadmin'.
 * Dipakai oleh /api/admin/landing/* — manajemen landing page Privasimu sendiri,
 * bukan tenant. Tenant admin (admin/dpo/maker) harus 403.
 */
class RootOrSuperadmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['root', 'superadmin'], true)) {
            return response()->json([
                'error' => 'Forbidden — root or superadmin role required.',
            ], 403);
        }

        return $next($request);
    }
}
