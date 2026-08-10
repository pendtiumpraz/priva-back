<?php

namespace App\Http\Middleware;

use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tegakkan entitlement modul pada route yang TIDAK melewati gerbang izin.
 *
 * Sebagian besar route modul sudah dijaga lewat CheckPermission (yang kini
 * memanggil EntitlementService). Route yang tidak memakai `permission:` —
 * misalnya modul yang tak punya konsep izin per-role — dipasangi middleware
 * ini agar tetap terkunci saat entitlement-nya dicabut.
 *
 * Penggunaan:
 *   ->middleware('entitlement:knowledge-base')
 *
 * Argumennya menu_key (slug menu), bukan module id izin.
 */
class EnsureModuleEntitled
{
    public function __construct(private EntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $menuKey): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Platform staff mengelola semua tenant; entitlement satu tenant tidak
        // membatasi mereka. Konsisten dengan EntitlementService::allowsModule.
        if (in_array($user->role, ['root', 'superadmin'], true)) {
            return $next($request);
        }

        if (! $this->entitlements->allowsMenuKey($user->org_id, $menuKey)) {
            return response()->json([
                'message' => 'Modul ini tidak aktif untuk organisasi Anda. Hubungi administrator platform.',
            ], 403);
        }

        return $next($request);
    }
}
