<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use App\Models\VendorIncident;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TPRM Phase 4 — Incident report management.
 *
 * Endpoint:
 *   GET    /api/tprm/incidents                 list incidents (filter status/severity/vendor)
 *   POST   /api/tprm/incidents                 report new incident
 *   GET    /api/tprm/incidents/{id}            detail
 *   PATCH  /api/tprm/incidents/{id}            update status / resolution
 *   POST   /api/tprm/incidents/{id}/apply-risk apply impact_score_delta ke vendor.risk_score
 *   DELETE /api/tprm/incidents/{id}            soft-delete
 *
 * Permission: vendor_risk.
 */
class TprmIncidentController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = VendorIncident::query()
            ->where('org_id', $orgId);

        // Filters
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }
        if ($vendorId = $request->query('vendor_id')) {
            $query->where('vendor_id', $vendorId);
        }
        if ($kind = $request->query('kind')) {
            $query->where('kind', $kind);
        }

        $rows = $query->orderByDesc('detected_at')->orderByDesc('created_at')->limit(200)->get();

        $vendorIds = $rows->pluck('vendor_id')->unique();
        $vendors = Vendor::query()
            ->where('org_id', $orgId)
            ->whereIn('id', $vendorIds)
            ->get(['id', 'name', 'category'])
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(function ($i) use ($vendors) {
                $v = $vendors->get($i->vendor_id);
                return [
                    'id' => $i->id,
                    'vendor_id' => $i->vendor_id,
                    'vendor_name' => $v?->name,
                    'kind' => $i->kind,
                    'severity' => $i->severity,
                    'status' => $i->status,
                    'title' => $i->title,
                    'description' => mb_substr($i->description ?? '', 0, 200),
                    'occurred_at' => $i->occurred_at?->toIso8601String(),
                    'detected_at' => $i->detected_at?->toIso8601String(),
                    'resolved_at' => $i->resolved_at?->toIso8601String(),
                    'reporter_user_id' => $i->reporter_user_id,
                    'impact_score_delta' => $i->impact_score_delta,
                    'applied_to_risk_score' => $i->applied_to_risk_score,
                ];
            })->values(),
        ]);
    }

    public function store(Request $request)
    {
        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'vendor_id' => 'required|string',
            'kind' => 'required|in:'.implode(',', VendorIncident::ALL_KINDS),
            'severity' => 'required|in:'.implode(',', VendorIncident::ALL_SEVERITIES),
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:5000',
            'occurred_at' => 'nullable|date',
            'detected_at' => 'nullable|date',
            'impact_score_delta' => 'nullable|integer|min:-100|max:100',
        ]);

        $vendor = Vendor::where('org_id', $orgId)->findOrFail($data['vendor_id']);

        $incident = VendorIncident::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'vendor_id' => $vendor->id,
            'reporter_user_id' => $request->user()->id,
            'kind' => $data['kind'],
            'severity' => $data['severity'],
            'title' => $data['title'],
            'description' => $data['description'],
            'occurred_at' => $data['occurred_at'] ?? now(),
            'detected_at' => $data['detected_at'] ?? now(),
            'status' => VendorIncident::STATUS_OPEN,
            'impact_score_delta' => $data['impact_score_delta'] ?? 0,
            'applied_to_risk_score' => false,
        ]);

        AuditLog::create([
            'org_id' => $orgId,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reporter',
            'module' => 'tprm.incident',
            'action' => 'report',
            'record_id' => $incident->id,
            'changes' => ['vendor_id' => $vendor->id, 'kind' => $data['kind'], 'severity' => $data['severity']],
        ]);

        return response()->json(['data' => $incident], 201);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $incident = $this->findOrFail($id, $orgId);
        $incident->load(['vendor:id,name,category,risk_level']);
        return response()->json(['data' => $incident]);
    }

    public function update(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $incident = $this->findOrFail($id, $orgId);

        $data = $request->validate([
            'status' => 'sometimes|in:'.implode(',', VendorIncident::ALL_STATUSES),
            'severity' => 'sometimes|in:'.implode(',', VendorIncident::ALL_SEVERITIES),
            'title' => 'sometimes|string|max:200',
            'description' => 'sometimes|string|max:5000',
            'resolution_note' => 'sometimes|nullable|string|max:2000',
            'impact_score_delta' => 'sometimes|integer|min:-100|max:100',
            'occurred_at' => 'sometimes|nullable|date',
        ]);

        // Auto-set resolved_at + resolved_by saat status pindah ke resolved/mitigated
        if (isset($data['status']) && in_array($data['status'], [
            VendorIncident::STATUS_RESOLVED,
            VendorIncident::STATUS_MITIGATED,
        ], true) && ! $incident->resolved_at) {
            $data['resolved_at'] = now();
            $data['resolved_by'] = $request->user()->id;
        }

        $incident->fill($data)->save();

        AuditLog::create([
            'org_id' => $orgId,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'admin',
            'module' => 'tprm.incident',
            'action' => 'update',
            'record_id' => $incident->id,
            'changes' => array_keys($data),
        ]);

        return response()->json(['data' => $incident->fresh()]);
    }

    /**
     * POST /api/tprm/incidents/{id}/apply-risk
     *
     * Apply impact_score_delta ke vendor.risk_score. Idempotent — kalau
     * sudah applied_to_risk_score=true, skip.
     */
    public function applyRisk(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $incident = $this->findOrFail($id, $orgId);

        if ($incident->applied_to_risk_score) {
            return response()->json([
                'message' => 'Dampak risiko sudah diterapkan sebelumnya.',
            ], 422);
        }

        $vendor = Vendor::where('org_id', $orgId)->findOrFail($incident->vendor_id);
        $newScore = max(0, min(100, ($vendor->risk_score ?? 0) + $incident->impact_score_delta));
        // Adjust risk_level berdasar new score (simple banding)
        $newLevel = $newScore >= 80 ? 'critical' : ($newScore >= 60 ? 'high' : ($newScore >= 40 ? 'medium' : 'low'));

        $vendor->forceFill([
            'risk_score' => $newScore,
            'risk_level' => $newLevel,
            'last_assessed_at' => now(),
        ])->save();

        $incident->forceFill(['applied_to_risk_score' => true])->save();

        AuditLog::create([
            'org_id' => $orgId,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'admin',
            'module' => 'tprm.incident',
            'action' => 'apply_risk',
            'record_id' => $incident->id,
            'changes' => [
                'vendor_id' => $vendor->id,
                'delta' => $incident->impact_score_delta,
                'new_score' => $newScore,
                'new_level' => $newLevel,
            ],
        ]);

        return response()->json([
            'message' => 'Dampak risiko diterapkan ke pihak ketiga.',
            'data' => ['risk_score' => $newScore, 'risk_level' => $newLevel],
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $incident = $this->findOrFail($id, $orgId);
        $incident->delete();
        return response()->json(['message' => 'Insiden dihapus.']);
    }

    /**
     * GET /api/tprm/incidents/meta
     * Return enum + label untuk dropdown FE.
     */
    public function meta()
    {
        return response()->json([
            'kinds' => collect(VendorIncident::ALL_KINDS)
                ->map(fn ($k) => ['key' => $k, 'label' => VendorIncident::KIND_LABELS[$k] ?? $k])->values(),
            'severities' => VendorIncident::ALL_SEVERITIES,
            'statuses' => VendorIncident::ALL_STATUSES,
        ]);
    }

    private function findOrFail(string $id, ?string $orgId): VendorIncident
    {
        if (! $orgId) abort(403, 'Org context required.');
        return VendorIncident::query()
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->firstOrFail();
    }
}
