<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dpia;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Risk Treatment Plan — Aggregate Cross-DPIA View
 *
 * Menampilkan semua mitigation_tracking items dari SEMUA DPIA di tenant
 * untuk DPO bird's eye view. Per-DPIA editing masih via DpiaRtpController.
 *
 * Phase 2 roadmap: extend ke polymorphic sumber (Breach/Vendor/GAP)
 * via risk_treatments table.
 */
class RiskTreatmentPlanController extends Controller
{
    /**
     * GET /api/rtp
     * Aggregate list dari semua DPIA tenant, dengan filter + pagination.
     *
     * Query params:
     *   status         (filter single)
     *   priority       (filter single)
     *   owner_user_id  (filter single)
     *   dpia_id        (filter single)
     *   search         (match risk_event / action / notes)
     *   due_from       (filter date range)
     *   due_to
     *   per_page       (default 25, max 200)
     *   page           (default 1)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        // Load semua DPIA + mitigation_tracking di tenant
        $dpias = Dpia::where('org_id', $orgId)
            ->whereNotNull('mitigation_tracking')
            ->select(['id', 'registration_number', 'custom_number', 'risk_level', 'status', 'description', 'mitigation_tracking'])
            ->get();

        // Flatten semua items, tambahkan konteks DPIA
        $allItems = [];
        foreach ($dpias as $dpia) {
            $items = $dpia->mitigation_tracking ?? [];
            foreach ($items as $it) {
                $allItems[] = array_merge($it, [
                    '_dpia_id'           => $dpia->id,
                    '_dpia_number'       => $dpia->registration_number ?: $dpia->custom_number,
                    '_dpia_risk_level'   => $dpia->risk_level,
                    '_dpia_status'       => $dpia->status,
                    '_dpia_description'  => mb_substr((string)$dpia->description, 0, 200),
                ]);
            }
        }

        // Auto-flag overdue saat read (frontend sees up-to-date)
        $today = Carbon::today();
        foreach ($allItems as &$it) {
            if (!empty($it['due_date']) && !in_array($it['status'] ?? 'planned', ['verified', 'cancelled', 'on_hold', 'overdue'], true)) {
                try {
                    if (Carbon::parse($it['due_date'])->startOfDay()->lt($today)) {
                        $it['status'] = 'overdue';
                    }
                } catch (\Throwable $e) { /* skip invalid */ }
            }
        }
        unset($it);

        // Filters
        $status = $request->input('status');
        $priority = $request->input('priority');
        $ownerId = $request->input('owner_user_id');
        $dpiaFilter = $request->input('dpia_id');
        $search = mb_strtolower((string) $request->input('search', ''));
        $dueFrom = $request->input('due_from');
        $dueTo = $request->input('due_to');

        $filtered = array_values(array_filter($allItems, function ($it) use ($status, $priority, $ownerId, $dpiaFilter, $search, $dueFrom, $dueTo) {
            if ($status && ($it['status'] ?? null) !== $status) return false;
            if ($priority && ($it['priority'] ?? null) !== $priority) return false;
            if ($ownerId && ($it['owner_user_id'] ?? null) !== $ownerId) return false;
            if ($dpiaFilter && ($it['_dpia_id'] ?? null) !== $dpiaFilter) return false;
            if ($search) {
                $haystack = mb_strtolower(
                    (string)($it['risk_event'] ?? '') . ' ' .
                    (string)($it['action'] ?? '') . ' ' .
                    (string)($it['notes'] ?? '') . ' ' .
                    (string)($it['_dpia_number'] ?? '')
                );
                if (!str_contains($haystack, $search)) return false;
            }
            if ($dueFrom && !empty($it['due_date'])) {
                try { if (Carbon::parse($it['due_date'])->lt(Carbon::parse($dueFrom))) return false; }
                catch (\Throwable $e) {}
            }
            if ($dueTo && !empty($it['due_date'])) {
                try { if (Carbon::parse($it['due_date'])->gt(Carbon::parse($dueTo))) return false; }
                catch (\Throwable $e) {}
            }
            return true;
        }));

        // Sort: priority desc (critical first) → due_date asc → status
        $priorityWeight = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        usort($filtered, function ($a, $b) use ($priorityWeight) {
            $pa = $priorityWeight[$a['priority'] ?? 'medium'] ?? 2;
            $pb = $priorityWeight[$b['priority'] ?? 'medium'] ?? 2;
            if ($pa !== $pb) return $pb - $pa;
            $da = $a['due_date'] ?? '9999-12-31';
            $db = $b['due_date'] ?? '9999-12-31';
            return strcmp($da, $db);
        });

        // Pagination
        $perPage = min(200, max(10, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));
        $total = count($filtered);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($filtered, $offset, $perPage);

        // Stats (from unfiltered allItems — global overview)
        $stats = $this->globalStats($allItems);

        // Resolve owner names for display (single query)
        $ownerIds = array_filter(array_unique(array_map(fn($i) => $i['owner_user_id'] ?? null, $allItems)));
        $owners = $ownerIds
            ? User::whereIn('id', $ownerIds)->select(['id', 'name', 'email'])->get()->keyBy('id')->toArray()
            : [];

        // Attach owner name
        foreach ($paged as &$it) {
            $oid = $it['owner_user_id'] ?? null;
            $it['_owner_name'] = ($oid && isset($owners[$oid])) ? $owners[$oid]['name'] : null;
        }
        unset($it);

        return response()->json([
            'data' => $paged,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'stats' => $stats,
            'filters_applied' => compact('status', 'priority', 'ownerId', 'dpiaFilter', 'search', 'dueFrom', 'dueTo'),
        ]);
    }

    /**
     * GET /api/rtp/facets
     * Return distinct values untuk filter dropdown (owner list, dpia list).
     */
    public function facets(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        $dpias = Dpia::where('org_id', $orgId)
            ->whereNotNull('mitigation_tracking')
            ->select(['id', 'registration_number', 'custom_number', 'risk_level'])
            ->get()
            ->map(fn($d) => [
                'id' => $d->id,
                'number' => $d->registration_number ?: $d->custom_number,
                'risk_level' => $d->risk_level,
            ]);

        // Distinct owners dari mitigation_tracking
        $allItems = [];
        Dpia::where('org_id', $orgId)
            ->whereNotNull('mitigation_tracking')
            ->select(['mitigation_tracking'])
            ->chunk(100, function ($chunk) use (&$allItems) {
                foreach ($chunk as $d) {
                    foreach (($d->mitigation_tracking ?? []) as $it) $allItems[] = $it;
                }
            });

        // Return ALL active tenant users for owner picker (bukan cuma yang sudah assigned)
        // Supaya user bisa assign siapa saja ke RTP — bukan terbatas user yang sudah pernah jadi owner.
        $owners = User::where('org_id', $orgId)
            ->where('is_active', true)
            ->select(['id', 'name', 'email', 'role', 'position'])
            ->orderBy('name')
            ->get()
            ->toArray();

        return response()->json([
            'dpias' => $dpias,
            'owners' => $owners,
            'statuses' => ['planned', 'in_progress', 'implemented', 'verified', 'overdue', 'on_hold', 'cancelled'],
            'priorities' => ['critical', 'high', 'medium', 'low'],
            'treatment_types' => ['avoid', 'reduce', 'transfer', 'accept'],
        ]);
    }

    /**
     * GET /api/rtp/dashboard
     * Stats lengkap untuk dashboard cards + charts.
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        $dpias = Dpia::where('org_id', $orgId)
            ->whereNotNull('mitigation_tracking')
            ->select(['id', 'registration_number', 'mitigation_tracking'])
            ->get();

        $allItems = [];
        foreach ($dpias as $d) {
            foreach (($d->mitigation_tracking ?? []) as $it) {
                $allItems[] = array_merge($it, ['_dpia_id' => $d->id, '_dpia_number' => $d->registration_number]);
            }
        }

        // Flag overdue
        $today = Carbon::today();
        foreach ($allItems as &$it) {
            if (!empty($it['due_date']) && !in_array($it['status'] ?? 'planned', ['verified', 'cancelled', 'on_hold', 'overdue'], true)) {
                try {
                    if (Carbon::parse($it['due_date'])->startOfDay()->lt($today)) {
                        $it['status'] = 'overdue';
                    }
                } catch (\Throwable $e) {}
            }
        }
        unset($it);

        $stats = $this->globalStats($allItems);

        // Top overdue items (top 10 by age)
        $overdueSorted = array_filter($allItems, fn($i) => ($i['status'] ?? null) === 'overdue');
        usort($overdueSorted, function ($a, $b) {
            return strcmp($a['due_date'] ?? '', $b['due_date'] ?? '');
        });
        $topOverdue = array_slice($overdueSorted, 0, 10);

        // Due soon (in 7 days)
        $dueSoon = array_filter($allItems, function ($it) use ($today) {
            if (empty($it['due_date'])) return false;
            if (in_array($it['status'] ?? null, ['verified', 'cancelled', 'on_hold', 'overdue'], true)) return false;
            try {
                $diff = Carbon::parse($it['due_date'])->startOfDay()->diffInDays($today, false);
                return $diff >= -7 && $diff <= 0;  // 0..7 days from now (future)
            } catch (\Throwable $e) { return false; }
        });
        $dueSoon = array_slice(array_values($dueSoon), 0, 10);

        return response()->json([
            'stats' => $stats,
            'top_overdue' => $topOverdue,
            'due_soon' => $dueSoon,
            'total_dpias_with_rtp' => $dpias->count(),
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function globalStats(array $items): array
    {
        $statusCount = ['planned' => 0, 'in_progress' => 0, 'implemented' => 0, 'verified' => 0, 'overdue' => 0, 'on_hold' => 0, 'cancelled' => 0];
        $priorityCount = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $treatmentTypeCount = ['avoid' => 0, 'reduce' => 0, 'transfer' => 0, 'accept' => 0];

        $inherentSum = 0; $inherentCount = 0;
        $residualSum = 0; $residualCount = 0;

        foreach ($items as $it) {
            $status = $it['status'] ?? 'planned';
            if (isset($statusCount[$status])) $statusCount[$status]++;
            $priority = $it['priority'] ?? 'medium';
            if (isset($priorityCount[$priority])) $priorityCount[$priority]++;
            $tt = $it['treatment_type'] ?? 'reduce';
            if (isset($treatmentTypeCount[$tt])) $treatmentTypeCount[$tt]++;

            if (!empty($it['inherent_likelihood']) && !empty($it['inherent_impact'])) {
                $inherentSum += (int)$it['inherent_likelihood'] * (int)$it['inherent_impact'];
                $inherentCount++;
            }
            if (!empty($it['residual_likelihood']) && !empty($it['residual_impact'])) {
                $residualSum += (int)$it['residual_likelihood'] * (int)$it['residual_impact'];
                $residualCount++;
            }
        }

        $total = count($items);
        return [
            'total' => $total,
            'status' => $statusCount,
            'priority' => $priorityCount,
            'treatment_type' => $treatmentTypeCount,
            'avg_inherent_risk' => $inherentCount > 0 ? round($inherentSum / $inherentCount, 1) : null,
            'avg_residual_risk' => $residualCount > 0 ? round($residualSum / $residualCount, 1) : null,
            'risk_reduction_pct' => ($inherentCount > 0 && $residualCount > 0 && $inherentSum > 0)
                ? round(((($inherentSum / max(1, $inherentCount)) - ($residualSum / max(1, $residualCount))) / ($inherentSum / max(1, $inherentCount))) * 100, 1)
                : null,
            'completion_rate' => $total > 0 ? round(($statusCount['verified'] / $total) * 100, 1) : 0,
            'overdue_rate' => $total > 0 ? round(($statusCount['overdue'] / $total) * 100, 1) : 0,
        ];
    }
}
