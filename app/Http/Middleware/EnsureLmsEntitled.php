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
        if (! $user || ! $user->org_id) {
            return response()->json(['message' => 'No organisation context.'], 403);
        }

        $lmsMenuItem = MenuItem::where('menu_key', 'lms')->first();

        if (! $lmsMenuItem) {
            return response()->json([
                'message' => 'Your organisation is not entitled to the LMS module.',
                'code' => 'LMS_NOT_ENTITLED',
            ], 403);
        }

        $entitled = TenantModuleEntitlement::query()
            ->where('org_id', $user->org_id)
            ->where('menu_id', $lmsMenuItem->id)
            ->where('is_entitled', true)
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString());
            })
            ->exists();

        if (! $entitled) {
            return response()->json([
                'message' => 'Your organisation is not entitled to the LMS module.',
                'code' => 'LMS_NOT_ENTITLED',
            ], 403);
        }

        return $next($request);
    }
}
