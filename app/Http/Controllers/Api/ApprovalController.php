<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
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
                if (!isset($workflow->steps[$workflow->current_step])) return false;
                
                $step = $workflow->steps[$workflow->current_step];
                $isApprover = false;
                
                if (isset($step['approver_id']) && $step['approver_id'] === $user->id) {
                    $isApprover = true;
                } elseif (isset($step['role'])) {
                    // check tenant role logic
                    if ($user->role === $step['role'] || ($user->tenantRole && strtolower($user->tenantRole->name) === strtolower($step['role']))) {
                        $isApprover = true;
                    }
                }
                
                return $isApprover && $step['status'] === 'pending';
            })->values();

        // Attach related models for context
        foreach ($workflows as $wf) {
            if ($wf->module === 'ropa') {
                $wf->related_record = \App\Models\Ropa::find($wf->record_id);
            } elseif ($wf->module === 'dpia') {
                $wf->related_record = \App\Models\Dpia::find($wf->record_id);
            }
            // other modules can be added
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
        } elseif (isset($step['role'])) {
            if ($user->role === $step['role'] || ($user->tenantRole && strtolower($user->tenantRole->name) === strtolower($step['role']))) {
                $isApprover = true;
            }
        }

        if (!$isApprover) {
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
            $modelClass = $workflow->module === 'ropa' ? \App\Models\Ropa::class : ($workflow->module === 'dpia' ? \App\Models\Dpia::class : null);
            if ($modelClass) {
                $record = $modelClass::find($workflow->record_id);
                if ($record) {
                    $record->update([
                        'status' => 'approved', 
                        'approver_id' => $user->id,
                        'approved_at' => now(),
                    ]);
                }
            }
        }
        $workflow->save();

        AuditLog::log($user->org_id, $user->id, 'approve', $workflow->module, $workflow->record_id, [
            'workflow_id' => $workflow->id,
            'step' => $current,
            'action' => 'approved'
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
        $modelClass = $workflow->module === 'ropa' ? \App\Models\Ropa::class : ($workflow->module === 'dpia' ? \App\Models\Dpia::class : null);
        if ($modelClass) {
            $record = $modelClass::find($workflow->record_id);
            if ($record) {
                $record->update(['status' => 'revision']);
            }
        }

        AuditLog::log($user->org_id, $user->id, 'reject', $workflow->module, $workflow->record_id, [
            'workflow_id' => $workflow->id,
            'step' => $current,
            'action' => 'rejected',
            'reason' => $request->reason
        ]);

        return response()->json(['message' => 'Rejected successfully', 'data' => $workflow]);
    }
}
