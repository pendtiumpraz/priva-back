<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityAlert;
use App\Services\AlertEngineService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlertController extends Controller
{
    /**
     * List alerts / notifications for the current user.
     *
     * Visibility rules:
     * - root/superadmin: see rows with org_id=null (platform-level) plus any
     *   where recipient_role matches their role.
     * - tenant users: see rows matching their org_id AND
     *     (recipient_id = me
     *      OR recipient_role = my role
     *      OR (recipient_id IS NULL AND recipient_role IS NULL))
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = $this->scopedQuery($request)->orderBy('priority', 'desc')->orderBy('created_at', 'desc');

        foreach (['status', 'kind', 'severity', 'module'] as $filter) {
            if ($request->filled($filter) && $request->get($filter) !== 'all') {
                $query->where($filter, $request->get($filter));
            }
        }

        // "unread" shortcut
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        // Stats for UI badge counts (per kind + severity)
        $scoped = $this->scopedQuery($request);
        $baseOpen = (clone $scoped)->whereIn('status', ['open', 'acknowledged']);

        $stats = [
            'total' => (clone $baseOpen)->count(),
            'unread' => (clone $scoped)->whereNull('read_at')->count(),
            'by_kind' => [
                'alert' => (clone $baseOpen)->where('kind', 'alert')->count(),
                'warning' => (clone $baseOpen)->where('kind', 'warning')->count(),
                'info' => (clone $baseOpen)->where('kind', 'info')->count(),
            ],
            'by_severity' => [
                'critical' => (clone $baseOpen)->where('severity', 'critical')->count(),
                'high' => (clone $baseOpen)->where('severity', 'high')->count(),
                'medium' => (clone $baseOpen)->where('severity', 'medium')->count(),
                'low' => (clone $baseOpen)->where('severity', 'low')->count(),
            ],
        ];

        $alerts = $query->limit(min((int) $request->get('limit', 100), 500))->get();

        return response()->json(['data' => $alerts, 'stats' => $stats]);
    }

    /** Badge count for bell icon. */
    public function count(Request $request)
    {
        $scoped = $this->scopedQuery($request);
        $count = (clone $scoped)->whereNull('read_at')->whereIn('status', ['open', 'acknowledged'])->count();
        $critical = (clone $scoped)->whereNull('read_at')->where('severity', 'critical')->whereIn('status', ['open', 'acknowledged'])->count();

        return response()->json([
            'count' => $count,
            'critical' => $critical,
        ]);
    }

    public function markRead(Request $request, $id)
    {
        $alert = $this->scopedQuery($request)->findOrFail($id);
        if (!$alert->read_at) $alert->update(['read_at' => now()]);
        return response()->json(['data' => $alert]);
    }

    public function markAllRead(Request $request)
    {
        $this->scopedQuery($request)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'All marked read']);
    }

    public function acknowledge(Request $request, $id)
    {
        $alert = $this->scopedQuery($request)->findOrFail($id);
        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
            'read_at' => $alert->read_at ?? now(),
        ]);
        return response()->json(['data' => $alert]);
    }

    public function resolve(Request $request, $id)
    {
        $alert = $this->scopedQuery($request)->findOrFail($id);
        $alert->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);
        return response()->json(['data' => $alert]);
    }

    public function dismiss(Request $request, $id)
    {
        $alert = $this->scopedQuery($request)->findOrFail($id);
        $alert->update(['status' => 'dismissed', 'read_at' => $alert->read_at ?? now()]);
        return response()->json(['data' => $alert]);
    }

    public function scan(Request $request)
    {
        $orgId = $request->user()->org_id;
        $engine = new AlertEngineService();
        $newAlerts = $engine->runAllRules($orgId);

        return response()->json([
            'message' => count($newAlerts) . ' anomali baru terdeteksi.',
            'new_alerts' => $newAlerts,
        ]);
    }

    /**
     * Export notifications to CSV (finance/sales follow-up use case,
     * especially for license-expiring module).
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->scopedQuery($request);
        foreach (['kind', 'severity', 'module', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->get($filter));
            }
        }
        $rows = $query->orderBy('created_at', 'desc')->limit(5000)->get();

        $filename = 'notifications-' . now()->format('Y-m-d-His') . '.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Created', 'Kind', 'Severity', 'Module', 'Type', 'Title', 'Body',
                'Org', 'Admin Name', 'Admin Email', 'Admin Phone', 'WA Link',
                'Expires', 'Days Left', 'Status',
            ]);
            foreach ($rows as $r) {
                $m = is_array($r->metadata) ? $r->metadata : [];
                fputcsv($out, [
                    $r->created_at?->toDateTimeString(),
                    $r->kind,
                    $r->severity,
                    $r->module,
                    $r->type ?? $r->rule_code,
                    $r->title,
                    $r->description,
                    $m['org_name'] ?? '',
                    $m['admin_name'] ?? '',
                    $m['admin_email'] ?? '',
                    $m['admin_phone'] ?? '',
                    $m['wa_url'] ?? '',
                    $m['expires_at'] ?? '',
                    $m['days_left'] ?? '',
                    $r->status,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Query builder scoped to the authenticated user's visibility rules.
     * Centralizes the scoping logic so every endpoint applies it consistently.
     */
    private function scopedQuery(Request $request)
    {
        $user = $request->user();
        $q = SecurityAlert::query();

        if (in_array($user->role, ['root', 'superadmin'], true)) {
            // Platform admins see platform-level (org_id null) + anything
            // targeted at their role.
            $q->where(function ($qq) use ($user) {
                $qq->whereNull('org_id')
                   ->orWhere('recipient_role', $user->role)
                   ->orWhere('recipient_id', $user->id);
            });
        } else {
            $q->where('org_id', $user->org_id)
              ->where(function ($qq) use ($user) {
                  $qq->where('recipient_id', $user->id)
                     ->orWhere('recipient_role', $user->role)
                     ->orWhere(function ($inner) {
                         $inner->whereNull('recipient_id')->whereNull('recipient_role');
                     });
              });
        }
        return $q;
    }
}
