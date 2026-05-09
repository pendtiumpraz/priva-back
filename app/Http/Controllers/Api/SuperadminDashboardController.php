<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\DsrRequest;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard untuk role superadmin — fokus operasional cross-tenant:
 * health summary, license expiring, alert critical lintas tenant,
 * top breach/DSR pending, recent activity per tenant.
 *
 * Beda dengan RootDashboard yang lebih ke platform infrastructure
 * (lifecycle status, audit menu_registry, dll).
 */
class SuperadminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! in_array($user->role ?? null, ['root', 'superadmin'], true)) {
            abort(403, 'Hanya root atau superadmin yang dapat akses dashboard ini.');
        }

        $today = now()->startOfDay();
        $week = now()->subDays(7)->startOfDay();
        $thirty = now()->addDays(30);

        return response()->json([
            'tenant_summary' => [
                'total_tenants' => Organization::count(),
                'active' => Organization::where('lifecycle_status', 'active')->count(),
                'frozen' => Organization::where('lifecycle_status', 'frozen')->count(),
                'archived' => Organization::onlyTrashed()->where('lifecycle_status', 'archived')->count(),
                'total_users' => User::whereNotNull('org_id')->count(),
            ],

            'license_health' => [
                'active' => License::where('status', 'active')->count(),
                'expiring_30d' => License::where('status', 'active')
                    ->whereNotNull('expires_at')
                    ->whereBetween('expires_at', [$today, $thirty])
                    ->count(),
                'expired' => License::where('status', 'expired')->count(),
                'tenants_no_license' => Organization::whereNotIn('id',
                    License::where('status', 'active')->whereNotNull('org_id')->pluck('org_id')
                )->count(),
            ],

            'license_expiring_list' => License::with('organization:id,name')
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [$today, $thirty])
                ->orderBy('expires_at')
                ->limit(15)
                ->get(['id', 'org_id', 'license_key', 'package_type', 'expires_at']),

            'pending_breach_critical' => BreachIncident::with('organization:id,name')
                ->where('severity', 'critical')
                ->whereNotIn('status', ['closed'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'org_id', 'incident_code', 'title', 'severity', 'status', 'detected_at']),

            'overdue_dsr' => DsrRequest::with('organization:id,name')
                ->whereIn('status', ['pending_review', 'in_progress'])
                ->whereNotNull('deadline_at')
                ->where('deadline_at', '<', now())
                ->orderBy('deadline_at')
                ->limit(10)
                ->get(['id', 'org_id', 'request_id', 'request_type', 'requester_name', 'deadline_at', 'status']),

            // audit_logs tidak punya kolom org_id — attribute via users.org_id
            'tenant_activity_7d' => DB::table('audit_logs as a')
                ->join('users as u', 'a.user_id', '=', 'u.id')
                ->select('u.org_id', DB::raw('count(*) as event_count'))
                ->whereNotNull('u.org_id')
                ->where('a.created_at', '>=', $week)
                ->groupBy('u.org_id')
                ->orderByDesc('event_count')
                ->limit(10)
                ->get()
                ->map(function ($r) {
                    $org = Organization::find($r->org_id);

                    return $org ? [
                        'org_id' => $org->id,
                        'org_name' => $org->name,
                        'event_count' => $r->event_count,
                    ] : null;
                })
                ->filter()
                ->values(),

            'recent_audits' => AuditLog::with(['user:id,name,email,role,org_id', 'user.organization:id,name'])
                ->whereHas('user', fn ($q) => $q->whereNotNull('org_id'))
                ->orderByDesc('created_at')
                ->limit(20)
                ->get()
                ->map(function ($a) {
                    $a->org_name = $a->user?->organization?->name;

                    return $a;
                }),
        ]);
    }
}
