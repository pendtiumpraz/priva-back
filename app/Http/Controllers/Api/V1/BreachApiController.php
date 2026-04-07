<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BreachIncident;
use Illuminate\Http\Request;

/**
 * Public API v1 — Breach Incidents
 * Authenticated via X-Api-Key header (partner API key)
 */
class BreachApiController extends Controller
{
    private function orgId(Request $request): string
    {
        return $request->attributes->get('api_org_id');
    }

    /**
     * GET /api/v1/breach
     * List breach incidents with filtering & pagination.
     */
    public function index(Request $request)
    {
        $query = BreachIncident::where('org_id', $this->orgId($request))
            ->where('is_simulation', false);

        // Filters
        if ($request->status) $query->where('status', $request->status);
        if ($request->severity) $query->where('severity', $request->severity);
        if ($request->since) $query->where('created_at', '>=', $request->since);
        if ($request->until) $query->where('created_at', '<=', $request->until);
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('title', 'like', "%{$request->search}%")
                    ->orWhere('incident_code', 'like', "%{$request->search}%");
            });
        }

        $breaches = $query->orderBy($request->sort ?? 'created_at', $request->order ?? 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => $breaches->items(),
            'meta' => [
                'current_page' => $breaches->currentPage(),
                'last_page' => $breaches->lastPage(),
                'per_page' => $breaches->perPage(),
                'total' => $breaches->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/breach/{id}
     * Show a single breach incident.
     */
    public function show(string $id, Request $request)
    {
        $breach = BreachIncident::where('org_id', $this->orgId($request))
            ->findOrFail($id);

        return response()->json(['data' => $breach]);
    }

    /**
     * POST /api/v1/breach
     * Create a new breach incident.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:500',
            'description' => 'nullable|string',
            'severity' => 'required|in:low,medium,high,critical',
            'source' => 'nullable|string|max:255',
            'affected_data_types' => 'nullable|array',
            'affected_subjects_count' => 'nullable|integer|min:0',
            'root_cause' => 'nullable|string',
            'detected_at' => 'nullable|date',
            'detected_by' => 'nullable|string',
            'pic_name' => 'nullable|string',
        ]);

        $orgId = $this->orgId($request);

        // Generate incident code
        $count = BreachIncident::where('org_id', $orgId)->count() + 1;
        $code = 'BRC-' . date('Y') . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

        $breach = BreachIncident::create(array_merge($validated, [
            'org_id' => $orgId,
            'incident_code' => $code,
            'status' => 'open',
            'is_simulation' => false,
            'notification_required' => in_array($validated['severity'], ['high', 'critical']),
            'notification_deadline' => in_array($validated['severity'], ['high', 'critical']) ? now()->addHours(72) : null,
            'timeline_log' => [
                ['event' => 'Breach dilaporkan via API', 'at' => now()->toISOString(), 'by' => 'API Partner'],
            ],
        ]));

        // TODO: trigger webhook 'breach.created'

        return response()->json([
            'message' => 'Breach incident berhasil dibuat.',
            'data' => $breach,
        ], 201);
    }

    /**
     * PUT /api/v1/breach/{id}
     * Update a breach incident.
     */
    public function update(string $id, Request $request)
    {
        $breach = BreachIncident::where('org_id', $this->orgId($request))
            ->findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:500',
            'description' => 'sometimes|nullable|string',
            'severity' => 'sometimes|in:low,medium,high,critical',
            'status' => 'sometimes|in:open,assessing,containing,contained,closed',
            'source' => 'sometimes|nullable|string',
            'affected_data_types' => 'sometimes|nullable|array',
            'affected_subjects_count' => 'sometimes|nullable|integer',
            'root_cause' => 'sometimes|nullable|string',
            'containment_actions' => 'sometimes|nullable|string',
            'remediation_plan' => 'sometimes|nullable|string',
        ]);

        // Update status timestamps
        if (isset($validated['status'])) {
            $statusTimestamps = [
                'assessing' => 'assessed_at',
                'containing' => 'assessed_at',
                'contained' => 'contained_at',
                'closed' => 'closed_at',
            ];

            $tsField = $statusTimestamps[$validated['status']] ?? null;
            if ($tsField && !$breach->$tsField) {
                $validated[$tsField] = now();
            }

            // Add to timeline
            $log = $breach->timeline_log ?? [];
            $log[] = [
                'event' => "Status diubah ke {$validated['status']} via API",
                'at' => now()->toISOString(),
                'by' => 'API Partner',
            ];
            $validated['timeline_log'] = $log;
        }

        $breach->update($validated);

        // TODO: trigger webhook 'breach.updated' / 'breach.status_changed'

        return response()->json([
            'message' => 'Breach incident berhasil diperbarui.',
            'data' => $breach->fresh(),
        ]);
    }

    /**
     * GET /api/v1/breach/stats
     * Get breach statistics for the organization.
     */
    public function stats(Request $request)
    {
        $orgId = $this->orgId($request);
        $base = BreachIncident::where('org_id', $orgId)->where('is_simulation', false);

        return response()->json([
            'data' => [
                'total' => (clone $base)->count(),
                'open' => (clone $base)->where('status', 'open')->count(),
                'assessing' => (clone $base)->where('status', 'assessing')->count(),
                'containing' => (clone $base)->where('status', 'containing')->count(),
                'contained' => (clone $base)->where('status', 'contained')->count(),
                'closed' => (clone $base)->where('status', 'closed')->count(),
                'by_severity' => [
                    'critical' => (clone $base)->where('severity', 'critical')->count(),
                    'high' => (clone $base)->where('severity', 'high')->count(),
                    'medium' => (clone $base)->where('severity', 'medium')->count(),
                    'low' => (clone $base)->where('severity', 'low')->count(),
                ],
                'this_month' => (clone $base)->where('created_at', '>=', now()->startOfMonth())->count(),
                'avg_resolution_hours' => round(
                    (clone $base)->whereNotNull('closed_at')->whereNotNull('detected_at')
                        ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, detected_at, closed_at)) as avg_h')
                        ->value('avg_h') ?? 0, 1
                ),
            ],
        ]);
    }
}
