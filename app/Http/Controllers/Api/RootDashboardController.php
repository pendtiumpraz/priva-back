<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Root-only platform dashboard. Aggregate stats that only platform operators
 * need to see (not tenant admins).
 */
class RootDashboardController extends Controller
{
    public function index(Request $request)
    {
        if (($request->user()->role ?? null) !== 'root') {
            abort(403, 'Hanya root yang dapat akses dashboard platform.');
        }

        $today = now()->startOfDay();
        $week = now()->subDays(7)->startOfDay();
        $month = now()->subDays(30)->startOfDay();

        return response()->json([
            'platform_summary' => [
                'total_tenants' => Organization::count(),
                'active_tenants' => Organization::where('lifecycle_status', 'active')->count(),
                'frozen_tenants' => Organization::where('lifecycle_status', 'frozen')->count(),
                'transferred_tenants' => Organization::where('lifecycle_status', 'transferred')->count(),
                'archived_tenants' => Organization::onlyTrashed()->where('lifecycle_status', 'archived')->count(),
                'total_users' => User::where('role', '!=', 'root')->count(),
                'active_licenses' => License::where('status', 'active')->count(),
            ],
            'tenants_by_package' => License::where('status', 'active')
                ->select('package_type', DB::raw('count(*) as count'))
                ->groupBy('package_type')
                ->get(),
            'recent_activity' => [
                'last_7_days_audits' => AuditLog::where('created_at', '>=', $week)->count(),
                'menu_registry_changes_7d' => AuditLog::where('module', 'menu_registry')
                    ->where('created_at', '>=', $week)->count(),
                'lifecycle_changes_30d' => AuditLog::where('module', 'organization')
                    ->whereIn('action', ['frozen', 'transferred', 'archived', 'unfrozen'])
                    ->where('created_at', '>=', $month)->count(),
            ],
            'top_active_tenants' => DB::table('users')
                ->select('org_id', DB::raw('count(*) as user_count'))
                ->whereNotNull('org_id')
                ->groupBy('org_id')
                ->orderByDesc('user_count')
                ->limit(10)
                ->get()
                ->map(function ($r) {
                    $org = Organization::find($r->org_id);
                    return $org ? [
                        'id' => $org->id, 'name' => $org->name,
                        'user_count' => $r->user_count,
                        'lifecycle_status' => $org->lifecycle_status,
                    ] : null;
                })
                ->filter()
                ->values(),
            'expiring_entitlements' => DB::table('tenant_module_entitlements')
                ->join('organizations', 'tenant_module_entitlements.org_id', '=', 'organizations.id')
                ->join('menu_items', 'tenant_module_entitlements.menu_id', '=', 'menu_items.id')
                ->where('tenant_module_entitlements.is_entitled', true)
                ->whereNotNull('tenant_module_entitlements.valid_until')
                ->whereDate('tenant_module_entitlements.valid_until', '>=', $today)
                ->whereDate('tenant_module_entitlements.valid_until', '<=', now()->addDays(30))
                ->select(
                    'organizations.name as org_name',
                    'menu_items.label as menu_label',
                    'menu_items.menu_key',
                    'tenant_module_entitlements.valid_until',
                )
                ->orderBy('tenant_module_entitlements.valid_until')
                ->limit(20)
                ->get(),
            'recent_audits' => AuditLog::with('user:id,name,email,role')
                ->orderByDesc('created_at')
                ->limit(15)
                ->get(),
        ]);
    }
}
