<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CookieLog;
use App\Services\TenantContextService;
use Illuminate\Http\Request;

/**
 * Admin inbox for cookie_logs (anonymous visitor consent).
 * Filterable + paginated. Tenant-scoped by org.
 */
class CookieLogAdminController extends Controller
{
    public function __construct(private TenantContextService $tenant) {}

    public function index(Request $request)
    {
        $orgId = $this->tenant->currentOrgId();
        if (! $orgId) {
            return response()->json(['error' => 'No org context'], 403);
        }

        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));

        $q = CookieLog::query()
            ->where('org_id', $orgId)
            ->orderByDesc('captured_at');

        if ($cid = $request->input('collection_id')) {
            $q->where('collection_id', $cid);
        }
        if ($visitor = $request->input('visitor_id')) {
            $q->where('visitor_id', $visitor);
        }
        if ($country = $request->input('country')) {
            $q->where('ip_country', strtoupper($country));
        }
        if ($device = $request->input('device_type')) {
            $q->where('device_type', $device);
        }
        if ($from = $request->input('date_from')) {
            $q->where('captured_at', '>=', $from);
        }
        if ($to = $request->input('date_to')) {
            $q->where('captured_at', '<=', $to);
        }
        if ($cat = $request->input('category')) {
            // Filter by category=true in choices JSON
            // (DB-portable; uses LIKE since SQLite test driver doesn't have JSON ops)
            $q->where('choices', 'like', '%"'.addslashes($cat).'":true%');
        }

        $page = $q->paginate($perPage);
        return response()->json($page);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $this->tenant->currentOrgId();
        $log = CookieLog::query()
            ->where('org_id', $orgId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json(['data' => $log]);
    }

    public function destroy(Request $request, string $id)
    {
        $orgId = $this->tenant->currentOrgId();
        $log = CookieLog::query()
            ->where('org_id', $orgId)
            ->where('id', $id)
            ->firstOrFail();

        $log->delete();
        return response()->json(['ok' => true]);
    }

    public function stats(Request $request)
    {
        $orgId = $this->tenant->currentOrgId();
        $base = CookieLog::query()->where('org_id', $orgId);

        $total = (clone $base)->count();
        $last24 = (clone $base)->where('captured_at', '>=', now()->subDay())->count();
        $last7d = (clone $base)->where('captured_at', '>=', now()->subWeek())->count();

        // Choice rates from latest log per visitor (approximate via sample of 1000 most recent)
        $sample = (clone $base)->orderByDesc('captured_at')->limit(1000)->get(['choices']);
        $rates = ['necessary' => 0, 'analytics' => 0, 'marketing' => 0, 'preferences' => 0];
        if ($sample->isNotEmpty()) {
            foreach ($rates as $k => $_) {
                $rates[$k] = round(
                    100 * $sample->filter(fn ($r) => ($r->choices[$k] ?? false) === true)->count() / $sample->count(),
                    1
                );
            }
        }

        return response()->json([
            'data' => [
                'total' => $total,
                'last_24h' => $last24,
                'last_7d' => $last7d,
                'opt_in_rate_pct' => $rates,
            ],
        ]);
    }
}
