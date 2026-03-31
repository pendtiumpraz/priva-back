<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
            'gap_score' => $latestGap->overall_score ?? 0,
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
            ->select('overall_score as score', 'compliance_level', 'created_at')
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

    /**
     * Get detailed risk analytics for dashboard.
     */
    public function riskAnalytics(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        // 1. ROPA by risk level
        $ropaByRisk = DB::table('ropas')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->select('risk_level', DB::raw('count(*) as count'))
            ->groupBy('risk_level')->get();

        // 2. ROPA top 10 highest risk
        $ropaTopRisks = DB::table('ropas')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->orderByRaw("CASE risk_level WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('created_at', 'desc')
            ->select('id', 'processing_activity', 'division', 'risk_level', 'data_categories', 'status', 'created_at')
            ->limit(10)->get()
            ->map(function ($row) {
                $row->data_categories = json_decode($row->data_categories, true) ?? [];
                return $row;
            });

        // 3. DPIA risk heatmap — aggregate from risk_assessment JSON
        $dpias = DB::table('dpias')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('risk_assessment')
            ->select('id', 'description', 'risk_assessment', 'risk_level')
            ->get();

        $heatmapData = [];
        $unmitigated = [];

        foreach ($dpias as $dpia) {
            $assessment = json_decode($dpia->risk_assessment, true);
            if (!is_array($assessment)) continue;

            foreach ($assessment as $categoryKey => $riskData) {
                $likelihood = $riskData['likelihood'] ?? 0;
                $impact = $riskData['impact'] ?? 0;

                if ($likelihood > 0 && $impact > 0) {
                    $key = "{$likelihood}-{$impact}";
                    if (!isset($heatmapData[$key])) {
                        $heatmapData[$key] = ['likelihood' => $likelihood, 'impact' => $impact, 'count' => 0];
                    }
                    $heatmapData[$key]['count']++;
                }

                // Check for unmitigated risks (high risk without mitigation)
                $riskScore = $likelihood * $impact;
                $risks = $riskData['risks'] ?? [];
                if ($riskScore >= 12 && empty($risks)) {
                    $unmitigated[] = [
                        'dpia_id' => $dpia->id,
                        'description' => $dpia->description,
                        'risk_category' => $categoryKey,
                        'likelihood' => $likelihood,
                        'impact' => $impact,
                        'risk_score' => $riskScore,
                    ];
                }
            }
        }

        // Sort unmitigated by risk score desc
        usort($unmitigated, fn($a, $b) => $b['risk_score'] <=> $a['risk_score']);
        $unmitigated = array_slice($unmitigated, 0, 10);

        // 4. DSR response times — average days per month
        $dsrResponseTimes = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();

            $record = DB::table('dsr_requests')
                ->where('org_id', $orgId)->whereNull('deleted_at')
                ->whereNotNull('responded_at')
                ->whereBetween('responded_at', [$start, $end])
                // Using DATEDIFF (MySQL) or simply grabbing them and computing in PHP to be safe across DBs
                ->get(['created_at', 'responded_at']);

            $sumDays = 0;
            $count = $record->count();
            foreach ($record as $row) {
                // Carbon parse
                $createDate = \Carbon\Carbon::parse($row->created_at);
                $respDate = \Carbon\Carbon::parse($row->responded_at);
                $sumDays += $createDate->diffInDays($respDate);
            }
            $avg = $count > 0 ? ($sumDays / $count) : 0;

            $dsrResponseTimes[] = [
                'month' => $date->format('M'),
                'avg_days' => round($avg ?? 0, 1),
            ];
        }

        // 5. Breach timeline — recent incidents
        $breachTimeline = DB::table('breach_incidents')
            ->where('org_id', $orgId)
            ->where('is_simulation', false)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->select('id', 'title', 'severity', 'status', 'detected_at', 'created_at', 'affected_subjects_count')
            ->limit(8)->get();

        // 6. Consent adoption — records per month
        $consentAdoption = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $start = $date->copy()->startOfMonth();
            $end = $date->copy()->endOfMonth();

            $total = DB::table('consent_records as cr')
                ->join('consent_collection_points as cp', 'cr.collection_point_id', '=', 'cp.id')
                ->where('cp.org_id', $orgId)
                ->whereBetween('cr.created_at', [$start, $end])
                ->count();

            $granted = DB::table('consent_records as cr')
                ->join('consent_collection_points as cp', 'cr.collection_point_id', '=', 'cp.id')
                ->where('cp.org_id', $orgId)
                ->where('cr.is_granted', true)
                ->whereBetween('cr.created_at', [$start, $end])
                ->count();

            $consentAdoption[] = [
                'month' => $date->format('M'),
                'total_records' => $total,
                'granted' => $granted,
                'acceptance_rate' => $total > 0 ? round(($granted / $total) * 100, 1) : 0,
            ];
        }

        return response()->json([
            'ropa_by_risk' => $ropaByRisk,
            'ropa_top_risks' => $ropaTopRisks,
            'dpia_heatmap' => array_values($heatmapData),
            'dpia_unmitigated' => $unmitigated,
            'dsr_response_times' => $dsrResponseTimes,
            'breach_timeline' => $breachTimeline,
            'consent_adoption' => $consentAdoption,
        ]);
    }

    /**
     * Download Excel/CSV Template for DPIA
     */
    public function downloadDpiaTemplate(Request $request)
    {
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="Template_DPIA_PIC.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];

        $columns = [
            'NAMA_SISTEM', 'DESKRIPSI_SISTEM', 'TUJUAN_PEMROSESAN', 'KATEGORI_DATA', 
            'SUBJEK_DATA_TERDAMPAK', 'RISIKO_AWAL_LIKELIHOOD(1-5)', 'RISIKO_AWAL_IMPACT(1-5)',
            'MITIGASI_YANG_DILAKUKAN', 'PIC_NAMA', 'PIC_EMAIL'
        ];

        $callback = function () use ($columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);
            
            // Example Row
            fputcsv($file, [
                'Sistem HRIS Terpadu', 'Sistem utama pencatatan presensi dan cuti pegawai',
                'Mengelola data SDM dan penggajian', 'Nama, NIK, No Rekening, Gaji',
                'Pegawai Internal', '3', '4',
                'Enkripsi kolom spesifik (NIK, No Rekening) pada database',
                'Budi Santoso', 'budi.it@perusahaan.com'
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
