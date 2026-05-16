<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use Illuminate\Http\Request;

/**
 * TPRM Phase 2 — Approver workflow (stage 3 dari 3-stage approval).
 *
 * Flow:
 *   pending_approval (kiriman dari reviewer)
 *     → POST /approve  → status=approved, sync vendor.risk_score (final)
 *     → POST /reject   → status=rejected, simpan rejection_reason
 *     → POST /return-to-reviewer → status=review_in_progress, balik ke reviewer
 *
 * Permission slug: vendor_risk,write (sama level dengan reviewer karena
 * di Privasimu BUMN biasanya approver sub-set dari role admin/DPO).
 *
 * Setelah approve atau reject: workflow_locked=true supaya tidak bisa
 * di-modify ulang kecuali via path reopen eksplisit.
 */
class TprmApprovalController extends Controller
{
    /**
     * GET /api/tprm/approval/inbox
     *
     * Assessment yang menunggu approval. Filter: assigned_approver_id =
     * user ini ATAU NULL (open pool).
     */
    public function inbox(Request $request)
    {
        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;

        $rows = VendorAssessment::query()
            ->where('org_id', $orgId)
            ->where('status', VendorAssessment::STATUS_PENDING_APPROVAL)
            ->where(function ($q) use ($userId) {
                $q->whereNull('assigned_approver_id')
                    ->orWhere('assigned_approver_id', $userId);
            })
            ->orderByDesc('reviewer_actioned_at')
            ->limit(100)
            ->get();

        $vendorIds = $rows->pluck('vendor_id')->unique();
        $vendors = Vendor::query()
            ->where('org_id', $orgId)
            ->whereIn('id', $vendorIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $rows->map(function ($a) use ($vendors, $userId) {
                $v = $vendors->get($a->vendor_id);
                return [
                    'id' => $a->id,
                    'vendor_id' => $a->vendor_id,
                    'vendor_name' => $v?->name,
                    'vendor_category' => $v?->category,
                    'status' => $a->status,
                    'score' => $a->score,
                    'risk_level' => $a->risk_level,
                    'reviewer_id' => $a->reviewer_id,
                    'reviewer_actioned_at' => $a->reviewer_actioned_at?->toIso8601String(),
                    'reviewer_note' => $a->reviewer_note,
                    'assigned_approver_id' => $a->assigned_approver_id,
                    'is_assigned_to_me' => $a->assigned_approver_id === $userId,
                ];
            }),
        ]);
    }

    /**
     * POST /api/tprm/approval/{id}/approve
     *
     * Final approve. Sync skor ke vendor.risk_score sebagai keputusan final.
     * Lock workflow.
     */
    public function approve(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! $assessment->canTransitionTo(VendorAssessment::STATUS_APPROVED)) {
            return response()->json([
                'message' => "Tidak dapat approve dari status '{$assessment->status}'.",
            ], 422);
        }

        $data = $request->validate([
            'approver_note' => 'nullable|string|max:2000',
        ]);

        $assessment->forceFill([
            'status' => VendorAssessment::STATUS_APPROVED,
            'approver_id' => $request->user()->id,
            'approver_actioned_at' => now(),
            'approver_note' => $data['approver_note'] ?? null,
            'workflow_locked' => true,
        ])->save();

        // Sync ke vendor.risk_score sebagai final decision
        try {
            $vendor = Vendor::find($assessment->vendor_id);
            if ($vendor) {
                $vendor->forceFill([
                    'risk_score' => (int) $assessment->score,
                    'risk_level' => $assessment->risk_level,
                    'last_assessed_at' => now(),
                ])->save();
            }
        } catch (\Throwable $e) {
            \Log::warning('TPRM vendor sync at approve failed: '.$e->getMessage());
        }

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'approver',
            'module' => 'tprm.approval',
            'action' => 'approve',
            'record_id' => $assessment->id,
        ]);

        return response()->json([
            'message' => 'Assessment disetujui. Status final.',
            'data' => ['status' => $assessment->status, 'score' => $assessment->score],
        ]);
    }

    /**
     * POST /api/tprm/approval/{id}/reject
     *
     * Final reject. Rejection reason wajib + simpan ke kolom dedicated
     * untuk audit. Lock workflow (tapi bisa di-reopen).
     */
    public function reject(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! $assessment->canTransitionTo(VendorAssessment::STATUS_REJECTED)) {
            return response()->json([
                'message' => "Tidak dapat reject dari status '{$assessment->status}'.",
            ], 422);
        }

        $data = $request->validate([
            'rejection_reason' => 'required|string|min:10|max:2000',
            'approver_note' => 'nullable|string|max:2000',
        ]);

        $assessment->forceFill([
            'status' => VendorAssessment::STATUS_REJECTED,
            'approver_id' => $request->user()->id,
            'approver_actioned_at' => now(),
            'approver_note' => $data['approver_note'] ?? null,
            'rejection_reason' => $data['rejection_reason'],
            'workflow_locked' => true,
        ])->save();

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'approver',
            'module' => 'tprm.approval',
            'action' => 'reject',
            'record_id' => $assessment->id,
            'changes' => ['reason' => $data['rejection_reason']],
        ]);

        return response()->json([
            'message' => 'Assessment ditolak. Status final.',
            'data' => ['status' => $assessment->status],
        ]);
    }

    /**
     * POST /api/tprm/approval/{id}/return-to-reviewer
     *
     * Approver kembalikan ke reviewer (mis. minta klarifikasi tambahan).
     * Status pending_approval → review_in_progress.
     */
    public function returnToReviewer(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! $assessment->canTransitionTo(VendorAssessment::STATUS_REVIEW_IN_PROGRESS)) {
            return response()->json([
                'message' => "Tidak dapat return ke reviewer dari status '{$assessment->status}'.",
            ], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        $assessment->forceFill([
            'status' => VendorAssessment::STATUS_REVIEW_IN_PROGRESS,
            'approver_note' => "Dikembalikan ke reviewer: ".$data['reason'],
        ])->save();

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'approver',
            'module' => 'tprm.approval',
            'action' => 'return_to_reviewer',
            'record_id' => $assessment->id,
            'changes' => ['reason' => $data['reason']],
        ]);

        return response()->json([
            'message' => 'Dikembalikan ke reviewer untuk klarifikasi.',
        ]);
    }

    /**
     * POST /api/tprm/approval/{id}/reopen
     *
     * Reopen assessment yang sudah final (approved/rejected) — escape
     * hatch kalau ada error dan butuh re-review. Wajib reason.
     */
    public function reopen(Request $request, string $id)
    {
        $assessment = $this->findInOrg($id, $request->user()->org_id);

        if (! $assessment->isFinal()) {
            return response()->json([
                'message' => 'Reopen hanya untuk assessment yang sudah final (approved / rejected).',
            ], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|min:20|max:1000',
        ]);

        $assessment->forceFill([
            'status' => VendorAssessment::STATUS_REVIEW_IN_PROGRESS,
            'workflow_locked' => false,
            'approver_note' => "REOPENED: ".$data['reason']."\n\n".($assessment->approver_note ?? ''),
        ])->save();

        AuditLog::create([
            'org_id' => $assessment->org_id,
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'user_role' => 'approver',
            'module' => 'tprm.approval',
            'action' => 'reopen',
            'record_id' => $assessment->id,
            'changes' => ['reason' => $data['reason']],
        ]);

        return response()->json([
            'message' => 'Assessment dibuka kembali untuk review.',
        ]);
    }

    private function findInOrg(string $id, ?string $orgId): VendorAssessment
    {
        if (! $orgId) {
            abort(403, 'Org context required.');
        }
        return VendorAssessment::query()
            ->where('id', $id)
            ->where('org_id', $orgId)
            ->firstOrFail();
    }
}
