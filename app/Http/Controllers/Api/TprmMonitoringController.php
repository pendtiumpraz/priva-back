<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use App\Models\VendorMonitoring;
use App\Models\VendorMonitoringReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TPRM Phase 4 — Monitoring berkala vendor aktif.
 *
 * Endpoint:
 *   GET    /api/tprm/monitoring/inbox              list schedule (due + overdue + upcoming)
 *   POST   /api/tprm/monitoring                    create/update schedule untuk vendor
 *   GET    /api/tprm/monitoring/{id}               detail + history reviews
 *   POST   /api/tprm/monitoring/{id}/complete      submit periodic review
 *   DELETE /api/tprm/monitoring/{id}               soft-delete (stop schedule)
 *
 * Permission: vendor_risk (read/write).
 */
class TprmMonitoringController extends Controller
{
    /**
     * GET /api/tprm/monitoring/inbox?filter=overdue|due|upcoming|all
     */
    public function inbox(Request $request)
    {
        $orgId = $request->user()->org_id;
        $filter = $request->query('filter', 'all');

        $query = VendorMonitoring::query()
            ->where('org_id', $orgId)
            ->where('is_active', true);

        // Filter by derived status (cek next_due_at vs now)
        $now = now();
        if ($filter === 'overdue') {
            $query->where('next_due_at', '<', $now);
        } elseif ($filter === 'due') {
            $query->whereBetween('next_due_at', [$now, $now->copy()->addDays(7)]);
        } elseif ($filter === 'upcoming') {
            $query->where('next_due_at', '>', $now->copy()->addDays(7));
        }

        $rows = $query->orderBy('next_due_at')->limit(200)->get();

        $vendorIds = $rows->pluck('vendor_id')->unique();
        $vendors = Vendor::query()
            ->where('org_id', $orgId)
            ->whereIn('id', $vendorIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(function ($m) use ($vendors) {
                $v = $vendors->get($m->vendor_id);
                return [
                    'id' => $m->id,
                    'vendor_id' => $m->vendor_id,
                    'vendor_name' => $v?->name,
                    'vendor_category' => $v?->category,
                    'vendor_risk_level' => $v?->risk_level,
                    'frequency_months' => $m->frequency_months,
                    'next_due_at' => $m->next_due_at?->toIso8601String(),
                    'last_completed_at' => $m->last_completed_at?->toIso8601String(),
                    'reviews_count' => $m->reviews_count,
                    'assigned_user_id' => $m->assigned_user_id,
                    'status' => $m->derive_status,
                ];
            })->values(),
            'counts' => [
                'overdue' => $rows->where('derive_status', 'overdue')->count(),
                'due' => $rows->where('derive_status', 'due')->count(),
                'upcoming' => $rows->where('derive_status', 'upcoming')->count(),
            ],
        ]);
    }

    /**
     * POST /api/tprm/monitoring
     *
     * Set / update schedule untuk vendor. Idempotent: kalau sudah ada
     * schedule aktif, di-update; kalau belum, di-create. Untuk ganti
     * frequency, panggil endpoint ini lagi dengan nilai baru.
     *
     * Body: { vendor_id, frequency_months, assigned_user_id, notes }
     */
    public function store(Request $request)
    {
        $orgId = $request->user()->org_id;

        $data = $request->validate([
            'vendor_id' => 'required|string',
            'frequency_months' => 'required|integer|min:1|max:60',
            'assigned_user_id' => 'nullable|string',
            'notes' => 'nullable|string|max:2000',
            'start_now' => 'nullable|boolean', // true: next_due_at = now + freq; false: now + freq dari last_completed
        ]);

        $vendor = Vendor::where('org_id', $orgId)->findOrFail($data['vendor_id']);

        $monitoring = VendorMonitoring::query()
            ->where('vendor_id', $vendor->id)
            ->where('org_id', $orgId)
            ->where('is_active', true)
            ->first();

        $startNow = $data['start_now'] ?? true;
        $nextDue = $startNow ? now()->addMonths($data['frequency_months']) : null;

        if ($monitoring) {
            $monitoring->forceFill([
                'frequency_months' => $data['frequency_months'],
                'assigned_user_id' => $data['assigned_user_id'] ?? $monitoring->assigned_user_id,
                'notes' => $data['notes'] ?? $monitoring->notes,
                'next_due_at' => $nextDue ?? $monitoring->next_due_at,
            ])->save();
        } else {
            $monitoring = VendorMonitoring::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'vendor_id' => $vendor->id,
                'frequency_months' => $data['frequency_months'],
                'assigned_user_id' => $data['assigned_user_id'] ?? $request->user()->id,
                'created_by' => $request->user()->id,
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
                'next_due_at' => $nextDue,
            ]);
        }

        AuditLog::create([
            'org_id' => $orgId,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'admin',
            'module' => 'tprm.monitoring',
            'action' => 'schedule',
            'record_id' => $monitoring->id,
            'changes' => ['vendor_id' => $vendor->id, 'frequency_months' => $data['frequency_months']],
        ]);

        return response()->json(['data' => $monitoring->fresh()], 201);
    }

    /**
     * GET /api/tprm/monitoring/{id}
     */
    public function show(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $monitoring = $this->findOrFail($id, $orgId);

        $monitoring->load(['vendor:id,name,category,risk_level,risk_score']);
        $reviews = $monitoring->reviews()->limit(50)->get();

        return response()->json([
            'data' => [
                'monitoring' => array_merge($monitoring->toArray(), [
                    'derive_status' => $monitoring->derive_status,
                ]),
                'reviews' => $reviews,
            ],
        ]);
    }

    /**
     * POST /api/tprm/monitoring/{id}/complete
     *
     * Submit hasil periodic review. Tulis row ke vendor_monitoring_reviews +
     * advance next_due_at = now() + frequency_months + update last_completed_at.
     *
     * Body: { decision, checklist_state, notes, action_items, related_incident_ids }
     */
    public function complete(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $monitoring = $this->findOrFail($id, $orgId);

        $data = $request->validate([
            'decision' => 'required|in:'.implode(',', VendorMonitoringReview::ALL_DECISIONS),
            'checklist_state' => 'nullable|array',
            'notes' => 'nullable|string|max:5000',
            'action_items' => 'nullable|string|max:5000',
            'related_incident_ids' => 'nullable|array',
            'related_incident_ids.*' => 'string',
        ]);

        $vendor = Vendor::find($monitoring->vendor_id);

        DB::transaction(function () use ($monitoring, $vendor, $data, $request, $orgId) {
            VendorMonitoringReview::create([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'monitoring_id' => $monitoring->id,
                'vendor_id' => $monitoring->vendor_id,
                'reviewer_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'checklist_state' => $data['checklist_state'] ?? null,
                'decision' => $data['decision'],
                'notes' => $data['notes'] ?? null,
                'action_items' => $data['action_items'] ?? null,
                'related_incident_ids' => $data['related_incident_ids'] ?? null,
                'risk_level_snapshot' => $vendor?->risk_level,
                'risk_score_snapshot' => $vendor?->risk_score,
            ]);

            $monitoring->forceFill([
                'last_completed_at' => now(),
                'next_due_at' => now()->addMonths($monitoring->frequency_months),
                'reviews_count' => $monitoring->reviews_count + 1,
            ])->save();

            // Kalau decision 'terminate', deactivate schedule otomatis
            if ($data['decision'] === VendorMonitoringReview::DECISION_TERMINATE) {
                $monitoring->forceFill(['is_active' => false])->save();
            }
        });

        AuditLog::create([
            'org_id' => $orgId,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'reviewer',
            'module' => 'tprm.monitoring',
            'action' => 'complete_review',
            'record_id' => $monitoring->id,
            'changes' => ['decision' => $data['decision'], 'vendor_id' => $monitoring->vendor_id],
        ]);

        return response()->json([
            'message' => 'Review periodik tersimpan.',
            'data' => $monitoring->fresh(),
        ]);
    }

    /**
     * DELETE /api/tprm/monitoring/{id}
     * Soft-delete + deactivate schedule. Reviews history dipertahankan.
     */
    public function destroy(Request $request, string $id)
    {
        $orgId = $request->user()->org_id;
        $monitoring = $this->findOrFail($id, $orgId);

        $monitoring->forceFill(['is_active' => false])->save();
        $monitoring->delete();

        return response()->json(['message' => 'Schedule dihapus.']);
    }

    private function findOrFail(string $id, ?string $orgId): VendorMonitoring
    {
        if (! $orgId) abort(403, 'Org context required.');
        return VendorMonitoring::query()
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->firstOrFail();
    }
}
