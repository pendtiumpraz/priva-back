<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Ropa;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * RoPA DPO approval workflow endpoints.
 *
 * State machine (status column):
 *   draft     → any user with ropa:write can submit()
 *   waiting   → DPO can approve() or reject()
 *   revision  → back to maker, which can re-submit after fixes
 *   approved  → final. DPO can still reject() back to revision.
 */
class RopaApprovalController extends Controller
{
    /**
     * Maker submits a draft RoPA for DPO review.
     */
    public function submit(Request $request, string $id)
    {
        $user = $request->user();
        $ropa = Ropa::where('org_id', $user->org_id)->findOrFail($id);

        if (! in_array($ropa->status, ['draft', 'revision'], true)) {
            return response()->json(['error' => "RoPA dengan status '{$ropa->status}' tidak dapat di-submit."], 422);
        }

        $ropa->update([
            'status' => 'waiting',
            'submitted_at' => now(),
            'submitted_by' => $user->id,
            'review_notes' => null, // clear previous revision notes
        ]);

        $this->log($ropa, 'submitted', $user, 'RoPA di-submit untuk review DPO');

        return response()->json([
            'message' => 'RoPA berhasil di-submit untuk review DPO.',
            'data' => $ropa->fresh(),
        ]);
    }

    /**
     * DPO approves a submitted RoPA.
     */
    public function approve(Request $request, string $id)
    {
        $user = $request->user();
        if (! $this->isDPO($user)) {
            return response()->json(['error' => 'Hanya DPO yang dapat melakukan approve.'], 403);
        }

        $ropa = Ropa::where('org_id', $user->org_id)->findOrFail($id);

        if ($ropa->status !== 'waiting') {
            return response()->json(['error' => "RoPA harus dalam status 'waiting' untuk di-approve, saat ini '{$ropa->status}'."], 422);
        }

        $ropa->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $user->id,
            'review_notes' => $request->input('notes'),
        ]);

        $this->log($ropa, 'approved', $user, 'RoPA disetujui DPO'.($request->input('notes') ? " — catatan: {$request->input('notes')}" : ''));

        // Notify creator + assignees that the RoPA was approved.
        try {
            $targets = array_filter(array_merge(
                [$ropa->created_by],
                is_array($ropa->assignees) ? $ropa->assignees : []
            ));
            foreach (array_unique($targets) as $uid) {
                NotificationService::dispatch(
                    kind: 'info',
                    severity: 'low',
                    module: 'ropa',
                    type: 'ropa.approved',
                    recipient: 'user:'.$uid,
                    orgId: $ropa->org_id,
                    title: "✅ RoPA {$ropa->registration_number} disetujui",
                    body: 'Disetujui DPO'.($request->input('notes') ? " — catatan: {$request->input('notes')}" : ''),
                    actionUrl: "/ropa/{$ropa->id}",
                    metadata: ['record_id' => $ropa->id]
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('RoPA approved notif failed: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'RoPA disetujui.',
            'data' => $ropa->fresh(),
        ]);
    }

    /**
     * DPO rejects — sends back to maker for revision with notes.
     */
    public function reject(Request $request, string $id)
    {
        $request->validate([
            'notes' => 'required|string|min:5|max:2000',
        ]);

        $user = $request->user();
        if (! $this->isDPO($user)) {
            return response()->json(['error' => 'Hanya DPO yang dapat menolak RoPA.'], 403);
        }

        $ropa = Ropa::where('org_id', $user->org_id)->findOrFail($id);

        if (! in_array($ropa->status, ['waiting', 'approved'], true)) {
            return response()->json(['error' => "RoPA dengan status '{$ropa->status}' tidak bisa direject."], 422);
        }

        $ropa->update([
            'status' => 'revision',
            'review_notes' => $request->input('notes'),
            'approved_at' => null,   // un-approve kalau sebelumnya approved
            'approved_by' => null,
        ]);

        $this->log($ropa, 'rejected', $user, "RoPA di-reject dengan catatan: {$request->input('notes')}");

        // Notify creator + assignees that the RoPA was rejected and needs revision.
        try {
            $targets = array_filter(array_merge(
                [$ropa->created_by],
                is_array($ropa->assignees) ? $ropa->assignees : []
            ));
            foreach (array_unique($targets) as $uid) {
                NotificationService::dispatch(
                    kind: 'warning',
                    severity: 'high',
                    module: 'ropa',
                    type: 'ropa.rejected',
                    recipient: 'user:'.$uid,
                    orgId: $ropa->org_id,
                    title: "❌ RoPA {$ropa->registration_number} perlu revisi",
                    body: "DPO reject dengan catatan: {$request->input('notes')}",
                    actionUrl: "/ropa/{$ropa->id}",
                    metadata: ['record_id' => $ropa->id, 'notes' => $request->input('notes')]
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('RoPA rejected notif failed: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'RoPA di-reject; dikembalikan ke maker untuk revisi.',
            'data' => $ropa->fresh(),
        ]);
    }

    private function isDPO($user): bool
    {
        if (in_array($user->role, ['root', 'superadmin', 'dpo'], true)) {
            return true;
        }
        $tenantRoleName = optional($user->tenantRole)->name ?? '';

        return str_contains(strtolower($tenantRoleName), 'dpo');
    }

    private function log(Ropa $ropa, string $action, $user, string $detail): void
    {
        try {
            AuditLog::create([
                'org_id' => $ropa->org_id,
                'module' => 'ropa',
                'record_id' => $ropa->id,
                'action' => $action,
                'user_name' => $user->name ?? 'Unknown',
                'user_role' => $user->role ?? 'user',
                'section' => 'approval_workflow',
                'changes' => ['status' => $ropa->status, 'detail' => $detail],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Ropa approval audit log failed: '.$e->getMessage());
        }
    }
}
