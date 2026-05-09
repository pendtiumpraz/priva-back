<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\CrossBorderTransfer;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\Ropa;
use App\Models\Vendor;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    /**
     * Resolve Eloquent model class dari module slug. Dipakai approve/reject
     * untuk update record status dan kirim notifikasi.
     */
    private function modelClassForModule(string $module): ?string
    {
        return match ($module) {
            'ropa' => Ropa::class,
            'dpia' => Dpia::class,
            'breach' => BreachIncident::class,
            'dsr' => DsrRequest::class,
            'cross_border' => CrossBorderTransfer::class,
            'vendor_risk' => Vendor::class,
            default => null,
        };
    }

    /**
     * Status target setelah approval workflow selesai. Setiap module punya
     * status enum berbeda — RoPA/DPIA pakai 'approved', Breach 'closed',
     * DSR 'completed'.
     */
    private function statusAfterApproval(string $module): string
    {
        return match ($module) {
            'breach' => 'closed',
            'dsr' => 'completed',
            // ropa, dpia, cross_border, vendor_risk → 'approved'
            default => 'approved',
        };
    }

    /**
     * Status target setelah workflow di-reject. Kembali ke draft/in-progress.
     */
    private function statusAfterRejection(string $module): string
    {
        return match ($module) {
            'breach' => 'containment',
            'dsr' => 'in_progress',
            'cross_border' => 'rejected',
            // ropa, dpia, vendor_risk → 'revision'
            default => 'revision',
        };
    }

    /**
     * Get pending approvals for the current user
     */
    public function pending(Request $request)
    {
        $user = $request->user();

        $workflows = ApprovalWorkflow::where('org_id', $user->org_id)
            ->where('status', 'pending')
            ->get()
            ->filter(function ($workflow) use ($user) {
                if (! isset($workflow->steps[$workflow->current_step])) {
                    return false;
                }

                $step = $workflow->steps[$workflow->current_step];
                $isApprover = false;

                if (isset($step['approver_id']) && $step['approver_id'] === $user->id) {
                    $isApprover = true;
                } elseif (isset($step['tenant_role_id']) && $step['tenant_role_id'] && $user->tenant_role_id === $step['tenant_role_id']) {
                    // Step pakai config baru: match by tenant_role_id (paling presisi)
                    $isApprover = true;
                } elseif (isset($step['role'])) {
                    // Legacy fallback: match by role string
                    if ($user->role === $step['role'] || ($user->tenantRole && strtolower($user->tenantRole->name) === strtolower($step['role']))) {
                        $isApprover = true;
                    }
                }

                return $isApprover && $step['status'] === 'pending';
            })->values();

        // Attach related models for context
        foreach ($workflows as $wf) {
            $cls = $this->modelClassForModule($wf->module);
            if ($cls) {
                $wf->related_record = $cls::find($wf->record_id);
            }
        }

        return response()->json(['data' => $workflows]);
    }

    public function approve(Request $request, $id)
    {
        $user = $request->user();
        $workflow = ApprovalWorkflow::findOrFail($id);

        if ($workflow->status !== 'pending') {
            return response()->json(['message' => 'Workflow is not pending'], 400);
        }

        $steps = $workflow->steps;
        $current = $workflow->current_step;

        // Verify if currently assigned
        $step = $steps[$current];
        $isApprover = false;
        if (isset($step['approver_id']) && $step['approver_id'] === $user->id) {
            $isApprover = true;
        } elseif (isset($step['tenant_role_id']) && $step['tenant_role_id'] && $user->tenant_role_id === $step['tenant_role_id']) {
            $isApprover = true;
        } elseif (isset($step['role'])) {
            if ($user->role === $step['role'] || ($user->tenantRole && strtolower($user->tenantRole->name) === strtolower($step['role']))) {
                $isApprover = true;
            }
        }

        // Tambahan gate: cek permission `<module>:approve` di tenant_role
        if ($isApprover && $user->tenantRole && is_array($user->tenantRole->permissions ?? null)) {
            $perms = $user->tenantRole->permissions;
            $approveKey = "{$workflow->module}:approve";
            if (! in_array('*', $perms, true) && ! in_array($approveKey, $perms, true)) {
                // Role belum punya permission approve untuk module ini.
                // Skip check ini untuk system role (admin/dpo legacy) supaya
                // backward-compat — mereka implicitly punya approve.
                if (! $user->tenantRole->is_system) {
                    return response()->json([
                        'message' => "Role '{$user->tenantRole->name}' tidak punya permission '{$approveKey}'. Tambah permission Approve di /settings → Manajemen Role.",
                    ], 403);
                }
            }
        }

        if (! $isApprover) {
            return response()->json(['message' => 'Unauthorized approver'], 403);
        }

        $steps[$current]['status'] = 'approved';
        $steps[$current]['approved_by'] = $user->id;
        $steps[$current]['approved_at'] = now()->toIso8601String();

        $workflow->steps = $steps;

        // If there is next step
        if ($current + 1 < count($steps)) {
            $workflow->current_step = $current + 1;
        } else {
            $workflow->status = 'approved';

            // Mark model as approved
            $modelClass = $this->modelClassForModule($workflow->module);
            if ($modelClass) {
                $record = $modelClass::find($workflow->record_id);
                if ($record) {
                    // Build update payload defensively — approver_id/approved_at
                    // mungkin tidak ada di model breach/dsr.
                    $payload = ['status' => $this->statusAfterApproval($workflow->module)];
                    if (in_array('approver_id', $record->getFillable(), true)) {
                        $payload['approver_id'] = $user->id;
                    }
                    if (in_array('approved_at', $record->getFillable(), true)) {
                        $payload['approved_at'] = now();
                    }
                    $record->update($payload);

                    // Notify creator + assignees that the record was approved.
                    try {
                        $targets = array_filter(array_merge(
                            [$record->created_by ?? null],
                            is_array($record->assignees) ? $record->assignees : []
                        ));
                        $regNum = $record->registration_number ?? '';
                        foreach (array_unique($targets) as $uid) {
                            NotificationService::dispatch(
                                kind: 'info',
                                severity: 'low',
                                module: $workflow->module,
                                type: "{$workflow->module}.approved",
                                recipient: 'user:'.$uid,
                                orgId: $record->org_id,
                                title: '✅ '.strtoupper($workflow->module)." {$regNum} disetujui",
                                body: 'Semua step approval sudah selesai.',
                                actionUrl: "/{$workflow->module}/{$record->id}",
                                metadata: ['record_id' => $record->id]
                            );
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('Approval approved notif failed: '.$e->getMessage());
                    }
                }
            }
        }
        $workflow->save();

        AuditLog::log($user->org_id, $user->id, 'approve', $workflow->module, $workflow->record_id, [
            'workflow_id' => $workflow->id,
            'step' => $current,
            'action' => 'approved',
        ]);

        return response()->json(['message' => 'Approved successfully', 'data' => $workflow]);
    }

    public function reject(Request $request, $id)
    {
        $request->validate(['reason' => 'required|string']);
        $user = $request->user();
        $workflow = ApprovalWorkflow::findOrFail($id);

        if ($workflow->status !== 'pending') {
            return response()->json(['message' => 'Workflow is not pending'], 400);
        }

        $steps = $workflow->steps;
        $current = $workflow->current_step;

        $steps[$current]['status'] = 'rejected';
        $steps[$current]['rejected_by'] = $user->id;
        $steps[$current]['rejected_at'] = now()->toIso8601String();
        $steps[$current]['reason'] = $request->reason;

        $workflow->steps = $steps;
        $workflow->status = 'rejected';
        $workflow->rejection_reason = $request->reason;
        $workflow->save();

        // Mark model as rejection/draft
        $modelClass = $workflow->module === 'ropa' ? Ropa::class : ($workflow->module === 'dpia' ? Dpia::class : null);
        if ($modelClass) {
            $record = $modelClass::find($workflow->record_id);
            if ($record) {
                $record->update(['status' => $this->statusAfterRejection($workflow->module)]);

                // Notify creator + assignees that the record was rejected with reason.
                try {
                    $targets = array_filter(array_merge(
                        [$record->created_by ?? null],
                        is_array($record->assignees) ? $record->assignees : []
                    ));
                    $regNum = $record->registration_number ?? '';
                    foreach (array_unique($targets) as $uid) {
                        NotificationService::dispatch(
                            kind: 'warning',
                            severity: 'high',
                            module: $workflow->module,
                            type: "{$workflow->module}.rejected",
                            recipient: 'user:'.$uid,
                            orgId: $record->org_id,
                            title: '❌ '.strtoupper($workflow->module)." {$regNum} perlu revisi",
                            body: "Catatan reviewer: {$request->reason}",
                            actionUrl: "/{$workflow->module}/{$record->id}",
                            metadata: ['record_id' => $record->id, 'reason' => $request->reason]
                        );
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Approval rejected notif failed: '.$e->getMessage());
                }
            }
        }

        AuditLog::log($user->org_id, $user->id, 'reject', $workflow->module, $workflow->record_id, [
            'workflow_id' => $workflow->id,
            'step' => $current,
            'action' => 'rejected',
            'reason' => $request->reason,
        ]);

        return response()->json(['message' => 'Rejected successfully', 'data' => $workflow]);
    }
}
