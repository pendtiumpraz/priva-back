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

    /**
     * DPO approves a single wizard section. Section state is stored in
     * wizard_data.section_approvals[$sectionKey]. Once ALL required
     * sections are approved, the RoPA itself flips to status=approved
     * (no separate approve() click needed).
     *
     * Issue: ROPA #17a (per-section approval).
     */
    public function approveSection(Request $request, string $id, string $sectionKey)
    {
        $user = $request->user();
        if (! $this->isDPO($user)) {
            return response()->json(['error' => 'Hanya DPO yang dapat melakukan approve.'], 403);
        }

        $ropa = Ropa::where('org_id', $user->org_id)->findOrFail($id);
        $this->assertSectionKey($sectionKey);

        if (! in_array($ropa->status, ['waiting', 'revision', 'approved'], true)) {
            return response()->json(['error' => "RoPA dengan status '{$ropa->status}' tidak bisa di-approve per-section."], 422);
        }

        $approvals = $this->upsertSectionApproval($ropa, $sectionKey, [
            'status' => 'approved',
            'notes' => $request->input('notes'),
            'approver_id' => $user->id,
            'approver_name' => $user->name ?? null,
            'updated_at' => now()->toIso8601String(),
        ]);

        // If all required sections approved → promote whole RoPA to approved.
        $allApproved = $this->allRequiredSectionsApproved($approvals);
        $update = ['wizard_data' => $this->mergeWizardData($ropa, ['section_approvals' => $approvals])];
        if ($allApproved && $ropa->status !== 'approved') {
            $update['status'] = 'approved';
            $update['approved_at'] = now();
            $update['approved_by'] = $user->id;
        }
        $ropa->update($update);

        $this->log($ropa, 'section_approved', $user, "Section '{$sectionKey}' di-approve".($request->input('notes') ? " — catatan: {$request->input('notes')}" : ''));

        return response()->json([
            'message' => $allApproved ? 'Section disetujui — semua section sudah approved, RoPA otomatis approved.' : 'Section disetujui.',
            'data' => $ropa->fresh(),
            'all_approved' => $allApproved,
        ]);
    }

    /**
     * DPO rejects a section with required notes. RoPA status flips to
     * 'revision' so the maker can fix and re-submit.
     */
    public function rejectSection(Request $request, string $id, string $sectionKey)
    {
        $request->validate(['notes' => 'required|string|min:5|max:2000']);

        $user = $request->user();
        if (! $this->isDPO($user)) {
            return response()->json(['error' => 'Hanya DPO yang dapat menolak section.'], 403);
        }

        $ropa = Ropa::where('org_id', $user->org_id)->findOrFail($id);
        $this->assertSectionKey($sectionKey);

        $approvals = $this->upsertSectionApproval($ropa, $sectionKey, [
            'status' => 'revision',
            'notes' => $request->input('notes'),
            'approver_id' => $user->id,
            'approver_name' => $user->name ?? null,
            'updated_at' => now()->toIso8601String(),
        ]);

        $ropa->update([
            'wizard_data' => $this->mergeWizardData($ropa, ['section_approvals' => $approvals]),
            'status' => 'revision',
            'review_notes' => $request->input('notes'),
            'approved_at' => null,
            'approved_by' => null,
        ]);

        $this->log($ropa, 'section_rejected', $user, "Section '{$sectionKey}' di-reject — catatan: {$request->input('notes')}");

        $this->notifyMakerSectionEvent($ropa, 'rejected', $sectionKey, $request->input('notes'));

        return response()->json([
            'message' => "Section '{$sectionKey}' di-reject; RoPA dikembalikan ke maker untuk revisi.",
            'data' => $ropa->fresh(),
        ]);
    }

    /**
     * DPO leaves a comment on a section without rejecting it. Per spec,
     * this also flips status to 'revision' so the maker is forced to
     * acknowledge / address the comment before re-submitting.
     */
    public function commentSection(Request $request, string $id, string $sectionKey)
    {
        $request->validate(['comment' => 'required|string|min:1|max:2000']);

        $user = $request->user();
        if (! $this->isDPO($user)) {
            return response()->json(['error' => 'Hanya DPO yang dapat memberi komentar review.'], 403);
        }

        $ropa = Ropa::where('org_id', $user->org_id)->findOrFail($id);
        $this->assertSectionKey($sectionKey);

        $existing = $this->getSectionApproval($ropa, $sectionKey);
        $comments = is_array($existing['comments'] ?? null) ? $existing['comments'] : [];
        $comments[] = [
            'comment' => $request->input('comment'),
            'author_id' => $user->id,
            'author_name' => $user->name ?? null,
            'at' => now()->toIso8601String(),
        ];

        $approvals = $this->upsertSectionApproval($ropa, $sectionKey, [
            'status' => $existing['status'] ?? 'pending',
            'notes' => $existing['notes'] ?? null,
            'approver_id' => $existing['approver_id'] ?? null,
            'comments' => $comments,
            'updated_at' => now()->toIso8601String(),
        ]);

        $update = [
            'wizard_data' => $this->mergeWizardData($ropa, ['section_approvals' => $approvals]),
        ];
        if ($ropa->status !== 'revision') {
            $update['status'] = 'revision';
            $update['approved_at'] = null;
            $update['approved_by'] = null;
        }
        $ropa->update($update);

        $this->log($ropa, 'section_commented', $user, "Komentar pada section '{$sectionKey}': {$request->input('comment')}");

        $this->notifyMakerSectionEvent($ropa, 'commented', $sectionKey, $request->input('comment'));

        return response()->json([
            'message' => 'Komentar tersimpan; RoPA pindah ke status revision.',
            'data' => $ropa->fresh(),
        ]);
    }

    // ---------- Per-section helpers ----------

    private function assertSectionKey(string $key): void
    {
        $valid = array_column(Ropa::WIZARD_SECTIONS, 'key');
        $valid[] = 'ringkasan'; // Phase F intent section
        if (! in_array($key, $valid, true)) {
            abort(422, "Section key '{$key}' tidak dikenal.");
        }
    }

    private function getSectionApproval(Ropa $ropa, string $key): array
    {
        $wiz = $ropa->wizard_data ?? [];
        $approvals = is_array($wiz['section_approvals'] ?? null) ? $wiz['section_approvals'] : [];

        return is_array($approvals[$key] ?? null) ? $approvals[$key] : [];
    }

    private function upsertSectionApproval(Ropa $ropa, string $key, array $patch): array
    {
        $wiz = $ropa->wizard_data ?? [];
        $approvals = is_array($wiz['section_approvals'] ?? null) ? $wiz['section_approvals'] : [];
        $approvals[$key] = array_merge($approvals[$key] ?? [], $patch);

        return $approvals;
    }

    private function mergeWizardData(Ropa $ropa, array $patch): array
    {
        return array_merge($ropa->wizard_data ?? [], $patch);
    }

    private function allRequiredSectionsApproved(array $approvals): bool
    {
        // Required sections = the 7 wizard step keys (ringkasan is Phase-F intent, optional)
        $required = array_column(Ropa::WIZARD_SECTIONS, 'key');
        foreach ($required as $key) {
            if (($approvals[$key]['status'] ?? null) !== 'approved') {
                return false;
            }
        }

        return true;
    }

    private function notifyMakerSectionEvent(Ropa $ropa, string $kind, string $sectionKey, string $detail): void
    {
        try {
            $targets = array_filter(array_merge(
                [$ropa->created_by],
                is_array($ropa->assignees) ? $ropa->assignees : []
            ));
            $title = $kind === 'rejected'
                ? "❌ RoPA {$ropa->registration_number} — section '{$sectionKey}' di-reject"
                : "💬 RoPA {$ropa->registration_number} — komentar baru pada '{$sectionKey}'";
            $severity = $kind === 'rejected' ? 'high' : 'medium';
            $type = $kind === 'rejected' ? 'ropa.section_rejected' : 'ropa.section_commented';
            foreach (array_unique($targets) as $uid) {
                NotificationService::dispatch(
                    kind: 'warning',
                    severity: $severity,
                    module: 'ropa',
                    type: $type,
                    recipient: 'user:'.$uid,
                    orgId: $ropa->org_id,
                    title: $title,
                    body: $detail,
                    actionUrl: "/ropa/{$ropa->id}",
                    metadata: ['record_id' => $ropa->id, 'section' => $sectionKey, 'detail' => $detail]
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('RoPA section event notif failed: '.$e->getMessage());
        }
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
