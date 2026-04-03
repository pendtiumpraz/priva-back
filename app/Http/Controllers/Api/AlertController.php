<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityAlert;
use App\Services\AlertEngineService;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * List alerts for current org (with filters).
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = SecurityAlert::where('org_id', $orgId)->orderBy('created_at', 'desc');

        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }

        $alerts = $query->get();

        // Stats
        $stats = [
            'total' => SecurityAlert::where('org_id', $orgId)->whereIn('status', ['open', 'acknowledged'])->count(),
            'critical' => SecurityAlert::where('org_id', $orgId)->where('severity', 'critical')->whereIn('status', ['open', 'acknowledged'])->count(),
            'high' => SecurityAlert::where('org_id', $orgId)->where('severity', 'high')->whereIn('status', ['open', 'acknowledged'])->count(),
            'medium' => SecurityAlert::where('org_id', $orgId)->where('severity', 'medium')->whereIn('status', ['open', 'acknowledged'])->count(),
            'low' => SecurityAlert::where('org_id', $orgId)->where('severity', 'low')->whereIn('status', ['open', 'acknowledged'])->count(),
        ];

        return response()->json(['data' => $alerts, 'stats' => $stats]);
    }

    /**
     * Get count of open alerts (for bell badge).
     */
    public function count(Request $request)
    {
        $orgId = $request->user()->org_id;
        $count = SecurityAlert::where('org_id', $orgId)
            ->whereIn('status', ['open'])
            ->count();
        $criticalCount = SecurityAlert::where('org_id', $orgId)
            ->where('severity', 'critical')
            ->whereIn('status', ['open'])
            ->count();

        return response()->json([
            'count' => $count,
            'critical' => $criticalCount,
        ]);
    }

    /**
     * Acknowledge an alert.
     */
    public function acknowledge(Request $request, $id)
    {
        $alert = SecurityAlert::where('org_id', $request->user()->org_id)->findOrFail($id);
        $alert->update([
            'status' => 'acknowledged',
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
        ]);

        return response()->json(['data' => $alert, 'message' => 'Alert acknowledged.']);
    }

    /**
     * Resolve an alert.
     */
    public function resolve(Request $request, $id)
    {
        $alert = SecurityAlert::where('org_id', $request->user()->org_id)->findOrFail($id);
        $alert->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
        ]);

        return response()->json(['data' => $alert, 'message' => 'Alert resolved.']);
    }

    /**
     * Dismiss an alert.
     */
    public function dismiss(Request $request, $id)
    {
        $alert = SecurityAlert::where('org_id', $request->user()->org_id)->findOrFail($id);
        $alert->update(['status' => 'dismissed']);

        return response()->json(['data' => $alert, 'message' => 'Alert dismissed.']);
    }

    /**
     * Manually trigger the alert engine scan.
     */
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
}
