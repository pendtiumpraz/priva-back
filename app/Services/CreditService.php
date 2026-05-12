<?php

namespace App\Services;

use App\Models\AiCreditLog;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

class CreditService
{
    /**
     * Credit cost table per action type
     */
    public const COSTS = [
        'autofill_ropa'      => 1.0,
        'autofill_dpia'      => 1.0,
        'autofill_breach'    => 1.0,
        'autofill_dsr'       => 0.5,
        'analysis_ropa'      => 1.0,  // ropaAnalysis (existing)
        'analysis_dpia'      => 1.0,  // dpiaRiskScoring (existing)
        'analysis_breach'    => 1.0,  // breachAdvisor (existing)
        'analysis_dsr'       => 0.5,  // dsrDraft (existing)
        'analysis_consent'   => 0.5,  // consentGenerator (existing)
        'gap_remediation'    => 1.0,
        'dashboard_summary'  => 1.0,
        'drill_scenario'     => 2.0,
        'chat'               => 0.25,
        'ai_doc_analyze'     => 1.0,  // Sprint G.9 — AiDocumentAnalyzer per-evidence call
    ];

    /**
     * Lazy reset: check & reset monthly credits if reset_at has passed.
     * Nullable orgId so root/superadmin users (who have no tenant org) can
     * call through without blowing up — they simply have no credits to reset.
     *
     * Reset date di-anchor ke awal bulan kalender (tanggal 1 jam 00:00),
     * BUKAN rolling 30 hari. Ini supaya konsisten dengan `used_this_month`
     * yang query log pakai `startOfMonth()` — kalau reset rolling, akan ada
     * kasus aneh di mana sisa kredit dan total terpakai bulan ini tidak
     * mathematically konsisten (mis. limit 500, used 91 log, remaining 474
     * karena reset terjadi di tengah bulan kalender).
     */
    public static function resetIfNeeded(?string $orgId): void
    {
        if ($orgId === null || $orgId === '') return;

        $org = Organization::find($orgId);
        if (!$org) return;

        if ($org->ai_credits_reset_at === null || $org->ai_credits_reset_at->isPast()) {
            $org->update([
                'ai_credits_remaining' => $org->ai_credits_monthly,
                'ai_credits_reset_at' => now()->addMonth()->startOfMonth(),
            ]);
        }
    }

    /**
     * Check if org has enough credits for action.
     *
     * On-prem deployments tidak punya pembatasan credit — selalu return true.
     * Credit logging tetap jalan supaya admin bisa lihat usage statistics, tapi
     * gak ada quota gate.
     */
    public static function hasCredit(?string $orgId, string $actionType): bool
    {
        if (self::isOnPrem()) return true;
        if ($orgId === null || $orgId === '') return false;
        $cost = self::COSTS[$actionType] ?? 1.0;
        $org = Organization::find($orgId);
        if (!$org) return false;

        $available = $org->ai_credits_remaining + $org->ai_credits_purchased;
        return $available >= $cost;
    }

    public static function isOnPrem(): bool
    {
        return config('ai.deployment_mode', 'saas') === 'onprem';
    }

    /**
     * Get the cost for an action type
     */
    public static function getCost(string $actionType): float
    {
        return self::COSTS[$actionType] ?? 1.0;
    }

    /**
     * Deduct credits AFTER successful AI call.
     * Priority: use ai_credits_remaining first, then ai_credits_purchased.
     */
    public static function deduct(
        string $orgId,
        string $userId,
        string $actionType,
        ?string $module = null,
        ?string $recordId = null,
        array $meta = []
    ): AiCreditLog {
        $cost = self::COSTS[$actionType] ?? 1.0;
        $org = Organization::findOrFail($orgId);

        // Deduct from monthly remaining first
        $fromMonthly = min($org->ai_credits_remaining, $cost);
        $fromPurchased = $cost - $fromMonthly;

        $org->decrement('ai_credits_remaining', $fromMonthly);
        if ($fromPurchased > 0) {
            $org->decrement('ai_credits_purchased', $fromPurchased);
        }

        return AiCreditLog::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'action_type' => $actionType,
            'credits_used' => $cost,
            'status' => 'success',
            'module' => $module,
            'record_id' => $recordId,
            'metadata' => $meta,
        ]);
    }

    /**
     * Log a failed AI call (no credits deducted)
     */
    public static function logFailed(
        string $orgId,
        string $userId,
        string $actionType,
        string $errorMessage,
        ?string $module = null
    ): AiCreditLog {
        return AiCreditLog::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'action_type' => $actionType,
            'credits_used' => 0,
            'status' => 'failed',
            'module' => $module,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Refund a previously deducted credit
     */
    public static function refund(string $logId): void
    {
        $log = AiCreditLog::findOrFail($logId);
        if ($log->status !== 'success') return;

        $org = Organization::findOrFail($log->org_id);
        $org->increment('ai_credits_remaining', $log->credits_used);

        $log->update(['status' => 'refunded']);
    }

    /**
     * Get usage summary for dashboard display
     */
    public static function getUsage(string $orgId): array
    {
        $org = Organization::findOrFail($orgId);

        $usedThisMonth = AiCreditLog::where('org_id', $orgId)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('credits_used');

        $byAction = AiCreditLog::where('org_id', $orgId)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('action_type, SUM(credits_used) as total, COUNT(*) as count')
            ->groupBy('action_type')
            ->get()
            ->keyBy('action_type');

        $recentLogs = AiCreditLog::where('org_id', $orgId)
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        $usedAllTime = AiCreditLog::where('org_id', $orgId)
            ->where('status', 'success')
            ->sum('credits_used');

        return [
            'monthly_limit' => $org->ai_credits_monthly,
            'remaining' => $org->ai_credits_remaining,
            'purchased' => $org->ai_credits_purchased,
            'used_this_month' => round($usedThisMonth, 2),
            'used_all_time' => round($usedAllTime, 2),
            'reset_at' => $org->ai_credits_reset_at?->toISOString(),
            'breakdown' => $byAction,
            'recent_logs' => $recentLogs,
            'deployment_mode' => self::isOnPrem() ? 'onprem' : 'saas',
            'has_quota' => ! self::isOnPrem(),
        ];
    }

    /**
     * Per-bulan usage untuk grafik history. Return last $months bulan.
     * Untuk SaaS: setiap bulan = satu cycle reset (alokasi monthly).
     * Untuk on-prem: angka usage murni (gak ada concept "limit").
     */
    public static function getMonthlyHistory(string $orgId, int $months = 12): array
    {
        $start = now()->subMonths($months - 1)->startOfMonth();

        // DB-agnostic grouping di PHP — fetch (created_at, credits_used) dalam
        // range, group per Y-m. Untuk 12 bulan rentang ini cukup ringan.
        $logs = AiCreditLog::where('org_id', $orgId)
            ->where('status', 'success')
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'credits_used']);

        $byYm = [];
        foreach ($logs as $log) {
            $ym = $log->created_at->format('Y-m');
            if (! isset($byYm[$ym])) {
                $byYm[$ym] = ['total' => 0, 'count' => 0];
            }
            $byYm[$ym]['total'] += (float) $log->credits_used;
            $byYm[$ym]['count']++;
        }

        // Build full timeline (fill missing months with 0)
        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->subMonths($i)->startOfMonth();
            $ym = $m->format('Y-m');
            $bucket = $byYm[$ym] ?? null;
            $result[] = [
                'month' => $ym,
                'month_label' => $m->locale('id')->isoFormat('MMM YYYY'),
                'used' => round((float) ($bucket['total'] ?? 0), 2),
                'count' => (int) ($bucket['count'] ?? 0),
                'is_current' => $m->isSameMonth(now()),
            ];
        }

        return $result;
    }

    /**
     * Super Admin: Get usage across all tenants
     */
    public static function getAllTenantsUsage(): array
    {
        return Organization::whereNull('deleted_at')
            ->select('id', 'name', 'industry', 'ai_credits_monthly', 'ai_credits_remaining', 'ai_credits_purchased', 'ai_credits_reset_at')
            ->withCount(['creditLogs as credits_used_this_month' => function ($q) {
                $q->where('status', 'success')->where('created_at', '>=', now()->startOfMonth());
            }])
            ->orderBy('name')
            ->get()
            ->toArray();
    }
}
