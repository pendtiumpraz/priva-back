<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate untuk endpoint yang hanya boleh diakses oleh role 'root'.
 * Lebih ketat dari RootOrSuperadmin — superadmin pun ditolak.
 * Dipakai oleh /api/root/qa/* (QA Center) dan endpoint platform-level
 * lain yang strictly root-only.
 */
class RootOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'root') {
            return response()->json([
                'error' => 'Forbidden — root role required.',
            ], 403);
        }

        return $next($request);
    }
}
