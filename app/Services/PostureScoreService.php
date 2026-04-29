<?php

namespace App\Services;

use App\Models\BreachIncident;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\InformationSystem;
use App\Models\MaturityAssessment;
use App\Models\PostureSnapshot;
use App\Models\Ropa;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3a rewrite — Privacy Posture Score.
 *
 * Replaces the synthetic 5-factor formula (gap/vendor/ropa/breach/discovery
 * average with rand() trend) with a 3-layer 12-pillar architecture grounded
 * in actual data from Data Discovery + Phase 1 (CBDT) + Phase 2 (TPRM) +
 * existing modules (DPIA, RTP, breach, DSR, maturity).
 *
 * Layer weighting (sum 100%):
 *   - Data Layer    (50%) — DSPM core (discovery, classification, encryption)
 *   - Process Layer (30%) — Compliance pipeline (ROPA, DPIA, RTP, vendor, CBDT)
 *   - Response Layer (20%) — When things go wrong (breach, DSR, maturity)
 *
 * Each pillar returns a score 0-100 + raw_metrics + reason. Layer score is
 * the weighted average within layer, normalized back to 0-100. Overall =
 * weighted sum of layers.
 *
 * No randomness. Same input = same output. Snapshot-able for real trend.
 */
class PostureScoreService
{
    public const LAYER_DATA = 'data';
    public const LAYER_PROCESS = 'process';
    public const LAYER_RESPONSE = 'response';

    /** Pillar weight in points within its layer. Sum per layer = layer total. */
    public const PILLAR_WEIGHTS = [
        // Layer Data — sum 50
        'discovery_coverage'      => ['layer' => self::LAYER_DATA,     'weight' => 15],
        'classification_coverage' => ['layer' => self::LAYER_DATA,     'weight' => 15],
        'sensitive_protection'    => ['layer' => self::LAYER_DATA,     'weight' => 15],
        'schema_drift'            => ['layer' => self::LAYER_DATA,     'weight' =>  5],
        // Layer Process — sum 30
        'ropa_coverage'           => ['layer' => self::LAYER_PROCESS,  'weight' =>  8],
        'dpia_compliance'         => ['layer' => self::LAYER_PROCESS,  'weight' => 10],
        'rtp_hygiene'             => ['layer' => self::LAYER_PROCESS,  'weight' =>  7],
        'vendor_risk'             => ['layer' => self::LAYER_PROCESS,  'weight' =>  3],
        'cross_border_basis'      => ['layer' => self::LAYER_PROCESS,  'weight' =>  2],
        // Layer Response — sum 20
        'breach_readiness'        => ['layer' => self::LAYER_RESPONSE, 'weight' => 10],
        'dsr_compliance'          => ['layer' => self::LAYER_RESPONSE, 'weight' =>  5],
        'maturity_self_eval'      => ['layer' => self::LAYER_RESPONSE, 'weight' =>  5],
    ];

    public const PILLAR_LABELS = [
        'discovery_coverage' => 'Cakupan Data Discovery',
        'classification_coverage' => 'Cakupan Klasifikasi PII',
        'sensitive_protection' => 'Proteksi Data Spesifik (Pasal 4)',
        'schema_drift' => 'Schema Drift (Perubahan PII)',
        'ropa_coverage' => 'Cakupan RoPA',
        'dpia_compliance' => 'Kepatuhan DPIA (Pasal 35)',
        'rtp_hygiene' => 'Risk Treatment Plan',
        'vendor_risk' => 'Risiko Vendor (TPRM)',
        'cross_border_basis' => 'Dasar Hukum Transfer (Pasal 56)',
        'breach_readiness' => 'Kesiapan Breach (Pasal 46)',
        'dsr_compliance' => 'SLA Hak Subjek Data',
        'maturity_self_eval' => 'Maturity Assessment',
    ];

    /**
     * Compute posture for an org. Returns the full structure ready for
     * the FE: overall + layer scores + per-pillar breakdown.
     */
    public function compute(string $orgId): array
    {
        $pillars = [
            'discovery_coverage'      => $this->discoveryCoverage($orgId),
            'classification_coverage' => $this->classificationCoverage($orgId),
            'sensitive_protection'    => $this->sensitiveProtection($orgId),
            'schema_drift'            => $this->schemaDrift($orgId),
            'ropa_coverage'           => $this->ropaCoverage($orgId),
            'dpia_compliance'         => $this->dpiaCompliance($orgId),
            'rtp_hygiene'             => $this->rtpHygiene($orgId),
            'vendor_risk'             => $this->vendorRisk($orgId),
            'cross_border_basis'      => $this->crossBorderBasis($orgId),
            'breach_readiness'        => $this->breachReadiness($orgId),
            'dsr_compliance'          => $this->dsrCompliance($orgId),
            'maturity_self_eval'      => $this->maturitySelfEval($orgId),
        ];

        // Roll up per layer
        $layerScores = [
            self::LAYER_DATA     => $this->aggregateLayer($pillars, self::LAYER_DATA),
            self::LAYER_PROCESS  => $this->aggregateLayer($pillars, self::LAYER_PROCESS),
            self::LAYER_RESPONSE => $this->aggregateLayer($pillars, self::LAYER_RESPONSE),
        ];

        // Overall = weighted by layer weight (data 50%, process 30%, response 20%)
        $overall = (int) round(
            $layerScores[self::LAYER_DATA] * 0.50 +
            $layerScores[self::LAYER_PROCESS] * 0.30 +
            $layerScores[self::LAYER_RESPONSE] * 0.20
        );

        return [
            'overall_score' => $overall,
            'status' => $this->scoreToStatus($overall),
            'layer_scores' => [
                'data'     => $layerScores[self::LAYER_DATA],
                'process'  => $layerScores[self::LAYER_PROCESS],
                'response' => $layerScores[self::LAYER_RESPONSE],
            ],
            'pillars' => collect(self::PILLAR_WEIGHTS)->map(function ($meta, $key) use ($pillars) {
                $p = $pillars[$key] ?? ['score' => 0, 'raw' => [], 'reason' => null];
                return [
                    'pillar' => $key,
                    'label' => self::PILLAR_LABELS[$key] ?? $key,
                    'layer' => $meta['layer'],
                    'weight' => $meta['weight'],
                    'score' => $p['score'],
                    'health' => $this->scoreToHealth($p['score']),
                    'raw' => $p['raw'] ?? [],
                    'reason' => $p['reason'] ?? null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Persist a snapshot — called by the daily scheduled job and on-demand
     * by the FE refresh button. Computes deltas vs previous snapshot for
     * the breakdown JSON.
     */
    public function takeSnapshot(string $orgId, string $source = PostureSnapshot::SOURCE_SCHEDULED): PostureSnapshot
    {
        $current = $this->compute($orgId);
        $prev = PostureSnapshot::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->orderByDesc('taken_at')
            ->first();

        // Annotate pillars with delta vs previous snapshot
        $prevPillars = collect($prev?->pillar_breakdown ?? [])->keyBy('pillar');
        $pillarsWithDelta = collect($current['pillars'])->map(function ($p) use ($prevPillars) {
            $prevScore = $prevPillars->get($p['pillar'])['score'] ?? null;
            $p['delta_vs_prev'] = $prevScore !== null ? $p['score'] - $prevScore : null;
            return $p;
        })->all();

        return PostureSnapshot::create([
            'org_id' => $orgId,
            'taken_at' => now(),
            'overall_score' => $current['overall_score'],
            'layer_data_score' => $current['layer_scores']['data'],
            'layer_process_score' => $current['layer_scores']['process'],
            'layer_response_score' => $current['layer_scores']['response'],
            'pillar_breakdown' => $pillarsWithDelta,
            'source' => $source,
        ]);
    }

    /**
     * Read trend from posture_snapshots — no rand(). Returns an array of
     * { date, score, layer_scores } points. FE renders the line directly.
     *
     * Strategy: take ≤1 snapshot per day (the latest of that day) so a
     * manual refresh doesn't pollute the trend. If < 7 snapshots exist,
     * caller should display "trend building" hint.
     */
    public function getHistoricalTrend(string $orgId, int $days = 30): array
    {
        $since = now()->subDays($days)->startOfDay();

        $snapshots = PostureSnapshot::query()
            ->withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('taken_at', '>=', $since)
            ->orderBy('taken_at')
            ->get(['id', 'taken_at', 'overall_score', 'layer_data_score', 'layer_process_score', 'layer_response_score']);

        // Collapse to one-per-day (keep latest per day)
        $perDay = [];
        foreach ($snapshots as $s) {
            $key = $s->taken_at->format('Y-m-d');
            $perDay[$key] = $s;
        }

        return collect($perDay)->values()->map(fn ($s) => [
            'date' => $s->taken_at->format('Y-m-d'),
            'score' => $s->overall_score,
            'layer_scores' => [
                'data' => $s->layer_data_score,
                'process' => $s->layer_process_score,
                'response' => $s->layer_response_score,
            ],
        ])->all();
    }

    // ─── Pillar implementations ──────────────────────────────────────────

    /**
     * % of registered information_systems scanned within last 30 days.
     * No systems registered → 30 (low) — signals "register data sources",
     * not max-zero which would punish day-1 tenants.
     */
    private function discoveryCoverage(string $orgId): array
    {
        $total = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();

        if ($total === 0) {
            return ['score' => 30, 'raw' => ['systems_total' => 0],
                'reason' => 'Belum ada sistem informasi yang didaftarkan untuk discovery scan.'];
        }

        $scanned = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('last_scanned_at', '>=', now()->subDays(30))
            ->count();

        $score = (int) round(($scanned / $total) * 100);
        return [
            'score' => $score,
            'raw' => ['systems_total' => $total, 'systems_scanned_30d' => $scanned],
            'reason' => "{$scanned} dari {$total} sistem ke-scan dalam 30 hari terakhir.",
        ];
    }

    /**
     * Across all scanned systems' columns, % of pii_detected columns that
     * have a corresponding entry in protection_assessments.
     */
    private function classificationCoverage(string $orgId): array
    {
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'scan_results', 'protection_assessments']);

        if ($systems->isEmpty()) {
            return ['score' => 50, 'raw' => ['pii_columns' => 0],
                'reason' => 'Belum ada hasil scan PII.'];
        }

        $piiCount = 0;
        $assessedCount = 0;

        foreach ($systems as $sys) {
            $scan = $sys->scan_results ?? [];
            $protections = $sys->protection_assessments ?? [];
            $tables = $scan['tables'] ?? [];

            foreach ($tables as $t) {
                $tableName = $t['name'] ?? '';
                foreach (($t['columns'] ?? []) as $c) {
                    if (!($c['pii_detected'] ?? false)) continue;
                    $piiCount++;
                    $key = "{$tableName}." . ($c['name'] ?? '');
                    if (isset($protections[$key]) && !empty($protections[$key])) {
                        $assessedCount++;
                    }
                }
            }
        }

        if ($piiCount === 0) {
            return ['score' => 70, 'raw' => ['pii_columns' => 0],
                'reason' => 'Tidak ada kolom PII terdeteksi — verifikasi cakupan scan.'];
        }

        $score = (int) round(($assessedCount / $piiCount) * 100);
        return [
            'score' => $score,
            'raw' => ['pii_columns' => $piiCount, 'assessed' => $assessedCount],
            'reason' => "{$assessedCount} dari {$piiCount} kolom PII sudah punya protection assessment.",
        ];
    }

    /**
     * Subset of classification_coverage focused only on Pasal 4 spesifik
     * categories (NIK, biometrik, kesehatan, dst). These need higher
     * scrutiny — the score is "% spesifik columns with protection".
     */
    private function sensitiveProtection(string $orgId): array
    {
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'scan_results', 'protection_assessments']);

        if ($systems->isEmpty()) {
            return ['score' => 50, 'raw' => ['sensitive_columns' => 0],
                'reason' => 'Belum ada hasil scan untuk dievaluasi.'];
        }

        $sensitiveCount = 0;
        $protectedCount = 0;

        foreach ($systems as $sys) {
            $tables = ($sys->scan_results['tables'] ?? []);
            $protections = $sys->protection_assessments ?? [];
            foreach ($tables as $t) {
                $tableName = $t['name'] ?? '';
                foreach (($t['columns'] ?? []) as $c) {
                    if (($c['pdp_category'] ?? null) !== 'spesifik') continue;
                    $sensitiveCount++;
                    $key = "{$tableName}." . ($c['name'] ?? '');
                    $a = $protections[$key] ?? null;
                    // Counts as protected if assessment indicates encryption OR access control
                    $protected = $a && (
                        !empty($a['encryption']) || !empty($a['access_control']) ||
                        !empty($a['masking']) || !empty($a['tokenization'])
                    );
                    if ($protected) $protectedCount++;
                }
            }
        }

        if ($sensitiveCount === 0) {
            return ['score' => 100, 'raw' => ['sensitive_columns' => 0],
                'reason' => 'Tidak ada data spesifik (NIK, biometrik, dst) terdeteksi.'];
        }

        $score = (int) round(($protectedCount / $sensitiveCount) * 100);
        return [
            'score' => $score,
            'raw' => ['sensitive_columns' => $sensitiveCount, 'protected' => $protectedCount],
            'reason' => "{$protectedCount} dari {$sensitiveCount} kolom spesifik (Pasal 4) sudah ada kontrol enkripsi/akses.",
        ];
    }

    /**
     * Schema drift = unresolved diff_alerts in scan_results. Each alert
     * = -10 points. Caps at 100 and floors at 0.
     */
    private function schemaDrift(string $orgId): array
    {
        $systems = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('scan_results')
            ->get(['id', 'scan_results']);

        $alertsTotal = 0;
        foreach ($systems as $sys) {
            $alertsTotal += count(($sys->scan_results['diff_alerts'] ?? []));
        }

        $score = max(0, 100 - ($alertsTotal * 10));
        return [
            'score' => $score,
            'raw' => ['unresolved_alerts' => $alertsTotal],
            'reason' => $alertsTotal === 0
                ? 'Tidak ada drift terdeteksi.'
                : "{$alertsTotal} schema drift alert belum di-acknowledge.",
        ];
    }

    /**
     * % of information_systems that have ≥1 linked RoPA. Without a
     * linked processing activity, the data store is "shadow data".
     */
    private function ropaCoverage(string $orgId): array
    {
        $total = InformationSystem::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();

        if ($total === 0) {
            return ['score' => 50, 'raw' => ['systems' => 0],
                'reason' => 'Belum ada sistem terdaftar.'];
        }

        // Use the information_system_ropa pivot to count systems with at least one RoPA
        $linked = DB::table('information_system_ropa')
            ->where('org_id', $orgId)
            ->distinct('information_system_id')
            ->count('information_system_id');

        $score = (int) round(($linked / $total) * 100);
        return [
            'score' => $score,
            'raw' => ['systems_total' => $total, 'systems_with_ropa' => $linked],
            'reason' => "{$linked} dari {$total} sistem sudah ditautkan ke RoPA.",
        ];
    }

    /**
     * For HIGH-risk RoPAs, % that have an approved DPIA. UU PDP Pasal 35
     * mandates DPIA for processing aktivitas berisiko tinggi.
     * No HIGH-risk RoPA → 100 (no DPIA owed).
     */
    private function dpiaCompliance(string $orgId): array
    {
        $highRisk = Ropa::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('risk_level', 'high')->count();

        if ($highRisk === 0) {
            return ['score' => 100, 'raw' => ['high_risk_ropa' => 0],
                'reason' => 'Tidak ada RoPA HIGH-risk — DPIA tidak wajib (Pasal 35).'];
        }

        $approved = Ropa::query()->withoutGlobalScope('org')
            ->where('ropas.org_id', $orgId)->whereNull('ropas.deleted_at')
            ->where('ropas.risk_level', 'high')
            ->whereExists(function ($q) {
                $q->from('dpias')
                    ->whereColumn('dpias.ropa_id', 'ropas.id')
                    ->where('dpias.status', 'approved')
                    ->whereNull('dpias.deleted_at');
            })
            ->count();

        $score = (int) round(($approved / $highRisk) * 100);
        return [
            'score' => $score,
            'raw' => ['high_risk_ropa' => $highRisk, 'with_approved_dpia' => $approved],
            'reason' => "{$approved} dari {$highRisk} RoPA HIGH-risk sudah punya DPIA approved.",
        ];
    }

    /**
     * RTP hygiene from Dpia.mitigation_tracking items. Count overdue +
     * verified rate. -10 per overdue item, +1 per verified item, capped 0-100.
     */
    private function rtpHygiene(string $orgId): array
    {
        $dpias = Dpia::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('mitigation_tracking')
            ->get(['id', 'mitigation_tracking']);

        $total = 0; $overdue = 0; $verified = 0; $today = Carbon::today();
        foreach ($dpias as $d) {
            foreach (($d->mitigation_tracking ?? []) as $it) {
                $total++;
                $status = $it['status'] ?? 'planned';
                if ($status === 'verified') $verified++;
                if (!empty($it['due_date']) && !in_array($status, ['verified', 'cancelled', 'on_hold'], true)) {
                    try {
                        if (Carbon::parse($it['due_date'])->lt($today)) $overdue++;
                    } catch (\Throwable $e) {}
                }
            }
        }

        if ($total === 0) {
            return ['score' => 70, 'raw' => ['rtp_items' => 0],
                'reason' => 'Belum ada Risk Treatment Plan items.'];
        }

        $base = 100;
        $base -= $overdue * 10;
        $base += min(20, $verified);   // cap verified bonus
        $score = max(0, min(100, $base));

        return [
            'score' => $score,
            'raw' => ['rtp_items' => $total, 'overdue' => $overdue, 'verified' => $verified],
            'reason' => "{$overdue} item overdue, {$verified} verified dari {$total} RTP items.",
        ];
    }

    /**
     * % of vendors not overdue for re-assessment AND with risk_level
     * in low/medium. Uses Phase 2 next_assessment_due_at + risk_level.
     */
    private function vendorRisk(string $orgId): array
    {
        $total = Vendor::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();

        if ($total === 0) {
            return ['score' => 70, 'raw' => ['vendors' => 0],
                'reason' => 'Belum ada vendor terdaftar.'];
        }

        $healthy = Vendor::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereIn('risk_level', ['low', 'medium'])
            ->where(function ($q) {
                $q->whereNull('next_assessment_due_at')
                    ->orWhere('next_assessment_due_at', '>=', now()->toDateString());
            })
            ->count();

        $score = (int) round(($healthy / $total) * 100);
        return [
            'score' => $score,
            'raw' => ['vendors_total' => $total, 'healthy' => $healthy],
            'reason' => "{$healthy} dari {$total} vendor risk_level low/medium dan tidak overdue assessment.",
        ];
    }

    /**
     * % of cross-border transfers with a non-'none' legal_basis.
     * UU PDP Pasal 56 — transfer tanpa dasar hukum eksplisit ilegal.
     */
    private function crossBorderBasis(string $orgId): array
    {
        $total = CrossBorderTransfer::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')->count();

        if ($total === 0) {
            return ['score' => 100, 'raw' => ['transfers' => 0],
                'reason' => 'Tidak ada cross-border transfer terdaftar.'];
        }

        $valid = CrossBorderTransfer::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('legal_basis')
            ->where('legal_basis', '!=', 'none')
            ->count();

        $score = (int) round(($valid / $total) * 100);
        return [
            'score' => $score,
            'raw' => ['transfers' => $total, 'with_legal_basis' => $valid],
            'reason' => "{$valid} dari {$total} transfer punya dasar hukum Pasal 56 yang valid.",
        ];
    }

    /**
     * Active breach count penalty + 72h notification compliance bonus.
     * Pasal 46: notify Komdigi within 72h.
     */
    private function breachReadiness(string $orgId): array
    {
        $active = BreachIncident::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        // Last 12 months breaches: how many were notified within 72h
        $recent = BreachIncident::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('detected_at', '>=', now()->subYear())
            ->whereNotNull('detected_at')
            ->get(['detected_at', 'notified_komdigi_at', 'notified_subjects_at']);

        $on_time = 0; $total = $recent->count();
        foreach ($recent as $b) {
            $notified = $b->notified_komdigi_at ?? $b->notified_subjects_at ?? null;
            if (!$notified) continue;
            try {
                $hours = Carbon::parse($b->detected_at)->diffInHours(Carbon::parse($notified));
                if ($hours <= 72) $on_time++;
            } catch (\Throwable $e) {}
        }

        $score = 100 - ($active * 20);
        if ($total > 0) {
            // Adjust by notification compliance rate — slight bonus/penalty
            $rate = $on_time / $total;
            $score += ($rate - 0.7) * 20;  // 70% on-time = neutral
        }
        $score = (int) round(max(0, min(100, $score)));

        return [
            'score' => $score,
            'raw' => [
                'active_breaches' => $active,
                'breaches_12mo' => $total,
                'notified_within_72h' => $on_time,
            ],
            'reason' => $active > 0
                ? "{$active} breach masih aktif — penalti 20 poin per breach."
                : ($total > 0
                    ? "{$on_time} dari {$total} breach 12 bulan terakhir di-notify dalam 72h."
                    : 'Tidak ada insiden aktif.'),
        ];
    }

    /**
     * % of closed DSR requests where closed_at <= deadline_at.
     */
    private function dsrCompliance(string $orgId): array
    {
        $closed = DsrRequest::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->whereNotNull('deadline_at')
            ->get(['closed_at', 'deadline_at']);

        if ($closed->isEmpty()) {
            return ['score' => 70, 'raw' => ['dsr_closed' => 0],
                'reason' => 'Belum ada DSR yang ditutup untuk dinilai.'];
        }

        $on_time = $closed->filter(fn ($d) => $d->closed_at <= $d->deadline_at)->count();
        $score = (int) round(($on_time / $closed->count()) * 100);
        return [
            'score' => $score,
            'raw' => ['dsr_closed' => $closed->count(), 'on_time' => $on_time],
            'reason' => "{$on_time} dari {$closed->count()} DSR ditutup tepat waktu.",
        ];
    }

    /**
     * Maturity Assessment freshness + level. Stale (>12 mo) or absent
     * = low score. Recent published assessment = base 50 + level × 12.5.
     */
    private function maturitySelfEval(string $orgId): array
    {
        $latest = MaturityAssessment::query()->withoutGlobalScope('org')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->whereIn('status', ['submitted', 'published'])
            ->orderByDesc('submitted_at')
            ->first();

        if (!$latest) {
            return ['score' => 30, 'raw' => ['has_assessment' => false],
                'reason' => 'Belum ada Maturity Assessment yang disubmit.'];
        }

        $ageDays = $latest->submitted_at ? now()->diffInDays($latest->submitted_at) : 9999;
        if ($ageDays > 365) {
            return ['score' => 40, 'raw' => ['age_days' => $ageDays, 'level' => $latest->overall_level],
                'reason' => "Maturity Assessment terakhir {$ageDays} hari lalu — perlu refresh."];
        }

        $level = (int) ($latest->overall_level ?? 1);
        $score = (int) round(50 + ($level * 12.5));   // L1=62, L2=75, L3=87, L4=100
        $score = min(100, $score);

        return [
            'score' => $score,
            'raw' => ['age_days' => $ageDays, 'level' => $level, 'overall_score' => $latest->overall_score],
            'reason' => "Maturity Level {$level} ({$ageDays} hari lalu).",
        ];
    }

    // ─── Aggregation helpers ─────────────────────────────────────────────

    private function aggregateLayer(array $pillars, string $layerKey): int
    {
        $total = 0; $weightSum = 0;
        foreach (self::PILLAR_WEIGHTS as $key => $meta) {
            if ($meta['layer'] !== $layerKey) continue;
            $score = $pillars[$key]['score'] ?? 0;
            $total += $score * $meta['weight'];
            $weightSum += $meta['weight'];
        }
        return $weightSum > 0 ? (int) round($total / $weightSum) : 0;
    }

    public function scoreToStatus(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Excellent',
            $score >= 60 => 'Good',
            $score >= 40 => 'Fair',
            default      => 'Critical',
        };
    }

    private function scoreToHealth(int $score): string
    {
        return match (true) {
            $score >= 75 => 'good',
            $score >= 50 => 'warning',
            default      => 'critical',
        };
    }

    /**
     * Backward compat — old endpoint shape. Kept so the existing FE
     * continues to render until the new `/security/posture/v2` rolls.
     */
    public function calculatePosture($orgId): array
    {
        $result = $this->compute((string) $orgId);
        return [
            'overall_score' => $result['overall_score'],
            'status' => $result['status'],
            'layer_scores' => $result['layer_scores'],
            'factors' => collect($result['pillars'])->map(fn ($p) => [
                'id' => $p['pillar'],
                'label' => $p['label'],
                'score' => $p['score'],
                'max' => 100,
                'health' => $p['health'],
                'recommendation' => $p['reason'] ?? '',
                'layer' => $p['layer'],
                'weight' => $p['weight'],
                'raw' => $p['raw'],
            ])->all(),
        ];
    }
}
