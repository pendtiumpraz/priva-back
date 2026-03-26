<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        // Latest GAP Assessment score
        $latestGap = DB::table('gap_assessments')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();

        $stats = [
            'gap_score' => $latestGap->score ?? 0,
            'gap_compliance_level' => $latestGap->compliance_level ?? 'low',
            'gap_progress' => $latestGap->progress ?? 0,

            'total_ropa' => DB::table('ropas')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count(),

            'total_dpia' => DB::table('dpias')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count(),

            'total_users' => DB::table('users')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count(),

            'total_dsr' => DB::table('dsr_requests')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count(),

            'dsr_pending' => DB::table('dsr_requests')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereIn('status', ['new', 'new_reply'])->count(),

            'dsr_overdue' => DB::table('dsr_requests')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereIn('status', ['new', 'new_reply'])
            ->where('deadline_at', '<', now())->count(),

            'active_breaches' => DB::table('breach_incidents')
            ->where('org_id', $orgId)->where('is_simulation', false)
            ->whereNotIn('status', ['closed'])->whereNull('deleted_at')->count(),

            'total_breaches' => DB::table('breach_incidents')
            ->where('org_id', $orgId)->where('is_simulation', false)
            ->whereNull('deleted_at')->count(),

            'consent_collection_points' => DB::table('consent_collection_points')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count(),

            'total_consent_records' => DB::table('consent_records as cr')
            ->join('consent_collection_points as cp', 'cr.collection_point_id', '=', 'cp.id')
            ->where('cp.org_id', $orgId)->count(),

            'data_sources' => DB::table('information_systems')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count(),

            'total_simulations' => DB::table('breach_simulations')
            ->where('org_id', $orgId)->count(),

            'feature_requests' => DB::table('feature_requests')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count(),
        ];

        return response()->json(['stats' => $stats]);
    }

    /**
     * Get chart data — module counts by month for trend chart
     */
    public function charts(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;
        $months = [];

        // Last 7 months
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();
            $label = $date->format('M');

            $months[] = [
                'month' => $label,
                'ropa' => DB::table('ropas')->where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->where('created_at', '<=', $end)->count(),
                'dpia' => DB::table('dpias')->where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->where('created_at', '<=', $end)->count(),
                'dsr' => DB::table('dsr_requests')->where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$start, $end])->count(),
                'breach' => DB::table('breach_incidents')->where('org_id', $orgId)
                ->where('is_simulation', false)->whereNull('deleted_at')
                ->whereBetween('created_at', [$start, $end])->count(),
                'consent' => DB::table('consent_collection_points')->where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->where('created_at', '<=', $end)->count(),
            ];
        }

        // GAP Assessment history (all scores)
        $gapHistory = DB::table('gap_assessments')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->select('score', 'compliance_level', 'created_at')
            ->limit(20)->get();

        // ROPA status breakdown
        $ropaByStatus = DB::table('ropas')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')->get();

        // DPIA risk breakdown
        $dpiaByRisk = DB::table('dpias')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->select('risk_level', DB::raw('count(*) as count'))
            ->groupBy('risk_level')->get();

        // DSR by type
        $dsrByType = DB::table('dsr_requests')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->select('request_type', DB::raw('count(*) as count'))
            ->groupBy('request_type')->get();

        // Breach by severity
        $breachBySeverity = DB::table('breach_incidents')
            ->where('org_id', $orgId)->where('is_simulation', false)->whereNull('deleted_at')
            ->select('severity', DB::raw('count(*) as count'))
            ->groupBy('severity')->get();

        return response()->json([
            'monthly_trend' => $months,
            'gap_history' => $gapHistory,
            'ropa_by_status' => $ropaByStatus,
            'dpia_by_risk' => $dpiaByRisk,
            'dsr_by_type' => $dsrByType,
            'breach_by_severity' => $breachBySeverity,
        ]);
    }
}
