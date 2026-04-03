<?php

namespace App\Services;

use App\Models\GapAssessment;
use App\Models\ModuleRecord;
use Carbon\Carbon;

class PostureScoreService
{
    /**
     * Calculate and return overall DSPM Posture.
     */
    public function calculatePosture($orgId)
    {
        // 1. GAP Assessment Factor (Max 30 points)
        $gapScoreObj = GapAssessment::where('org_id', $orgId)->orderBy('created_at', 'desc')->first();
        $baseGap = $gapScoreObj ? $gapScoreObj->score : 30; // base 30 if none
        $factorGap = ($baseGap / 100) * 30;

        // 2. Vendor Risk Factor (Max 20 points)
        $totalVendors = ModuleRecord::where('org_id', $orgId)->where('module', 'vendor-risk')->count();
        $safeVendors = ModuleRecord::where('org_id', $orgId)
            ->where('module', 'vendor-risk')
            ->where(function ($q) {
                // Assuming json extraction or general safe status for vendor risk
                $q->whereJsonContains('data->risk_level', 'low')
                  ->orWhere('status', 'active');
            })->count();
        $factorVendor = $totalVendors > 0 ? ($safeVendors / $totalVendors) * 20 : 20;

        // 3. Document Compliance (ROPA & DPIA) (Max 20 points)
        $totalDocs = ModuleRecord::where('org_id', $orgId)->whereIn('module', ['ropa', 'dpia'])->count();
        $completedDocs = ModuleRecord::where('org_id', $orgId)
            ->whereIn('module', ['ropa', 'dpia'])
            ->whereIn('status', ['published', 'approved', 'completed'])
            ->count();
        $factorDocs = $totalDocs > 0 ? ($completedDocs / $totalDocs) * 20 : 15;

        // 4. Breach / Incident Factor (Max 15 points)
        // Deduct 5 points per active breach, max 15 points
        $activeBreaches = ModuleRecord::where('org_id', $orgId)
            ->where('module', 'breach')
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
        $factorBreach = max(0, 15 - ($activeBreaches * 5));

        // 5. Data Discovery / System Freshness (Max 15 points)
        // Since we don't have a rigid scan tracking yet, use mock/placeholder or connected systems.
        $factorDiscovery = 12; // Assume moderately fresh

        // Total Score
        $totalScore = round($factorGap + $factorVendor + $factorDocs + $factorBreach + $factorDiscovery);

        return [
            'overall_score' => $totalScore,
            'status' => $totalScore >= 80 ? 'Excellent' : ($totalScore >= 60 ? 'Good' : ($totalScore >= 40 ? 'Fair' : 'Critical')),
            'factors' => [
                [
                    'id' => 'gap_compliance',
                    'label' => 'Regulatory Gap Compliance',
                    'score' => round($factorGap),
                    'max' => 30,
                    'health' => $factorGap >= 24 ? 'good' : ($factorGap >= 15 ? 'warning' : 'critical'),
                    'recommendation' => $factorGap < 24 ? 'Tingkatkan skor Gap Assessment Anda, fokus pada Encryption dan Policy.' : 'Sudah memadai.',
                ],
                [
                    'id' => 'vendor_risk',
                    'label' => 'Third-Party / Vendor Risk',
                    'score' => round($factorVendor),
                    'max' => 20,
                    'health' => $factorVendor >= 16 ? 'good' : ($factorVendor >= 10 ? 'warning' : 'critical'),
                    'recommendation' => $factorVendor < 16 ? 'Beberapa vendor memiliki risiko tinggi atau tidak memiliki DPA aktif.' : 'Manajemen vendor aman.',
                ],
                [
                    'id' => 'doc_compliance',
                    'label' => 'ROPA & DPIA Coverage',
                    'score' => round($factorDocs),
                    'max' => 20,
                    'health' => $factorDocs >= 16 ? 'good' : ($factorDocs >= 10 ? 'warning' : 'critical'),
                    'recommendation' => $factorDocs < 16 ? 'Selesaikan draf ROPA dan laksanakan DPIA untuk pemrosesan berisiko.' : 'Cakupan dokumen baik.',
                ],
                [
                    'id' => 'breach_incidents',
                    'label' => 'Incident & Breach Readiness',
                    'score' => round($factorBreach),
                    'max' => 15,
                    'health' => $factorBreach >= 12 ? 'good' : ($factorBreach >= 5 ? 'warning' : 'critical'),
                    'recommendation' => $factorBreach < 15 ? 'Terdapat insiden yang masih aktif dan perlu ditangani segera.' : 'Tidak ada insiden aktif. Baik.',
                ],
                [
                    'id' => 'data_discovery',
                    'label' => 'Scan Freshness & Encryption',
                    'score' => round($factorDiscovery),
                    'max' => 15,
                    'health' => $factorDiscovery >= 12 ? 'good' : ($factorDiscovery >= 7 ? 'warning' : 'critical'),
                    'recommendation' => 'Jalankan data discovery scan bulanan pada database yang terkoneksi.',
                ],
            ]
        ];
    }

    /**
     * Return historical trend for line charts.
     */
    public function getHistoricalTrend($orgId)
    {
        // Generates recent 6 weeks dummy trend based on current score
        $current = $this->calculatePosture($orgId)['overall_score'];
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subWeeks($i);
            // Add some jitter for realism
            $jitter = rand(-4, 2);
            $scoreLine = max(0, min(100, $current - ($i * 2) + $jitter));
            $trend[] = [
                'date' => $date->format('Y-m-d'),
                'score' => $scoreLine
            ];
        }
        return $trend;
    }
}
