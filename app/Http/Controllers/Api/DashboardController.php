<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
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
        $yearly = [];
        $availableYears = [];

        // Query param control:
        //   ?period=monthly&year=YYYY  → 12 bulan tahun tsb (Jan-Des)
        //   ?period=yearly             → 5 tahun terakhir (aggregate)
        //   default (no period)        → last 7 months (backward compat)
        $period = $request->query('period');
        $year = (int) $request->query('year', now()->year);

        // Cari tahun-tahun yang punya data (untuk picker frontend).
        $oldestDate = DB::table('ropas')->where('org_id', $orgId)->whereNull('deleted_at')->min('created_at');
        $oldestYear = $oldestDate ? (int) date('Y', strtotime($oldestDate)) : now()->year;
        for ($y = now()->year; $y >= $oldestYear; $y--) {
            $availableYears[] = $y;
        }
        if (empty($availableYears)) {
            $availableYears = [now()->year];
        }

        if ($period === 'yearly') {
            // Aggregate per tahun untuk 5 tahun terakhir (atau sejak data ada).
            $startYear = max($oldestYear, now()->year - 4);
            for ($y = $startYear; $y <= now()->year; $y++) {
                $yStart = "{$y}-01-01 00:00:00";
                $yEnd = "{$y}-12-31 23:59:59";
                $yearly[] = [
                    'year' => (string) $y,
                    'ropa' => DB::table('ropas')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$yStart, $yEnd])->count(),
                    'dpia' => DB::table('dpias')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$yStart, $yEnd])->count(),
                    'dsr' => DB::table('dsr_requests')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$yStart, $yEnd])->count(),
                    'breach' => DB::table('breach_incidents')->where('org_id', $orgId)
                        ->where('is_simulation', false)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$yStart, $yEnd])->count(),
                    'consent' => DB::table('consent_collection_points')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$yStart, $yEnd])->count(),
                ];
            }
            // Tetap include monthly_trend default supaya backward compat untuk
            // konsumer yang tidak handle period=yearly.
        }

        if ($period === 'monthly') {
            // 12 bulan dari tahun yang diminta.
            for ($m = 1; $m <= 12; $m++) {
                $start = "{$year}-" . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
                $endDt = (new \DateTime($start))->modify('last day of this month')->format('Y-m-d') . ' 23:59:59';
                $label = (new \DateTime($start))->format('M');
                $months[] = [
                    'month' => $label,
                    'year' => $year,
                    'ropa' => DB::table('ropas')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$start, $endDt])->count(),
                    'dpia' => DB::table('dpias')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$start, $endDt])->count(),
                    'dsr' => DB::table('dsr_requests')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$start, $endDt])->count(),
                    'breach' => DB::table('breach_incidents')->where('org_id', $orgId)
                        ->where('is_simulation', false)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$start, $endDt])->count(),
                    'consent' => DB::table('consent_collection_points')->where('org_id', $orgId)->whereNull('deleted_at')
                        ->whereBetween('created_at', [$start, $endDt])->count(),
                ];
            }
        } else {
            // Default: last 7 months (legacy behaviour) — kumulatif untuk ropa/dpia/consent,
            // per-month untuk dsr/breach. Dipertahankan supaya UI lama tidak rusak.
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
        }

        // GAP Assessment history (all scores)
        $gapHistory = DB::table('gap_assessments')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->select('overall_score as score', 'compliance_level', 'created_at')
            ->limit(20)->get();

        // RoPA status breakdown
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
            'yearly_trend' => $yearly,
            'available_years' => $availableYears,
            'period' => $period ?: 'default',
            'selected_year' => $year,
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

        // 1. RoPA by risk level
        $ropaByRisk = DB::table('ropas')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->select('risk_level', DB::raw('count(*) as count'))
            ->groupBy('risk_level')->get();

        // 2. RoPA top 10 highest risk
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

        /*
         * 3. Heatmap risiko DPIA (likelihood x impact) + daftar risiko tinggi
         *    yang belum ditangani.
         *
         * KENAPA DITULIS ULANG. Versi lama membaca `risk_assessment` seolah ia
         * peta KATEGORI -> {likelihood, impact, risks}. Wizard DPIA tidak pernah
         * menulis bentuk itu; yang ditulisnya adalah
         * {likelihood, impact, risks: [...]} — satu pasang angka ringkasan di
         * tingkat atas. Menelusurinya sebagai peta kategori menghasilkan
         * `$riskData` berupa INT (3, 4) lalu `$riskData['likelihood']` menjadi
         * null, sehingga TIDAK ADA SATU SEL PUN yang pernah terisi. Heatmap
         * selalu kosong untuk setiap DPIA yang dibuat lewat wizard, dan pesan
         * "Belum ada DPIA dengan skor risiko" selalu bohong.
         *
         * Sumber yang benar sama dengan yang dibaca halaman rincian DPIA:
         *   UTAMA   wizard_data.potensi_risiko[kategori].risk_events[]
         *           dengan `probabilitas` dan `dampak` — inilah yang diisi
         *           orang di bagian 3 wizard, satu baris per peristiwa risiko.
         *   CADANGAN risk_assessment.risks[] dengan `likelihood`/`impact` —
         *           bentuk lama hasil inferensi otomatis dan impor.
         * Cadangan hanya dipakai bila kategori itu tidak punya risk_events,
         * sehingga satu peristiwa tidak pernah terhitung dua kali.
         */
        $dpias = DB::table('dpias')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->select('id', 'description', 'risk_assessment', 'wizard_data', 'risk_level')
            ->get();

        $heatmapData = [];
        $unmitigated = [];

        $catat = function (int $likelihood, int $impact) use (&$heatmapData): void {
            if ($likelihood < 1 || $likelihood > 5 || $impact < 1 || $impact > 5) {
                return;
            }
            $key = "{$likelihood}-{$impact}";
            $heatmapData[$key] ??= ['likelihood' => $likelihood, 'impact' => $impact, 'count' => 0];
            $heatmapData[$key]['count']++;
        };

        foreach ($dpias as $dpia) {
            $wizard = json_decode((string) $dpia->wizard_data, true);
            $potensi = is_array($wizard) && is_array($wizard['potensi_risiko'] ?? null)
                ? $wizard['potensi_risiko']
                : [];
            $legacy = json_decode((string) $dpia->risk_assessment, true);
            $legacyRisks = is_array($legacy) && is_array($legacy['risks'] ?? null) ? $legacy['risks'] : [];

            // Peristiwa per kategori: wizard dulu, warisan hanya bila kosong.
            $perKategori = [];
            foreach ($potensi as $kategori => $isi) {
                foreach ((is_array($isi) ? ($isi['risk_events'] ?? []) : []) as $e) {
                    if (! is_array($e)) {
                        continue;
                    }
                    $perKategori[$kategori][] = [
                        'likelihood' => (int) ($e['probabilitas'] ?? 0),
                        'impact' => (int) ($e['dampak'] ?? 0),
                        // Risiko dianggap sudah ditangani bila ada kontrol atau
                        // keputusan penanganan — bukan sekadar ada catatan.
                        'ditangani' => ! empty($e['kontrol']) || ! empty($e['penanganan']),
                    ];
                }
            }
            foreach ($legacyRisks as $e) {
                $kategori = is_array($e) ? (string) ($e['risk'] ?? '') : '';
                if ($kategori === '' || isset($perKategori[$kategori])) {
                    continue;
                }
                $perKategori[$kategori][] = [
                    'likelihood' => (int) ($e['likelihood'] ?? 0),
                    'impact' => (int) ($e['impact'] ?? 0),
                    'ditangani' => ! empty($e['mitigation']),
                ];
            }

            foreach ($perKategori as $kategori => $peristiwa) {
                foreach ($peristiwa as $p) {
                    $catat($p['likelihood'], $p['impact']);

                    $skor = $p['likelihood'] * $p['impact'];
                    if ($skor >= 12 && ! $p['ditangani']) {
                        $unmitigated[] = [
                            'dpia_id' => $dpia->id,
                            'description' => $dpia->description,
                            'risk_category' => $kategori,
                            'likelihood' => $p['likelihood'],
                            'impact' => $p['impact'],
                            'risk_score' => $skor,
                        ];
                    }
                }
            }
        }

        // Sort unmitigated by risk score desc
        usort($unmitigated, fn ($a, $b) => $b['risk_score'] <=> $a['risk_score']);
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
                $createDate = Carbon::parse($row->created_at);
                $respDate = Carbon::parse($row->responded_at);
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
            'Expires' => '0',
        ];

        $columns = [
            'NAMA_SISTEM', 'DESKRIPSI_SISTEM', 'TUJUAN_PEMROSESAN', 'KATEGORI_DATA',
            'SUBJEK_DATA_TERDAMPAK', 'RISIKO_AWAL_LIKELIHOOD(1-5)', 'RISIKO_AWAL_IMPACT(1-5)',
            'MITIGASI_YANG_DILAKUKAN', 'PIC_NAMA', 'PIC_EMAIL',
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
                'Budi Santoso', 'budi.it@perusahaan.com',
            ]);
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
