<?php

namespace App\Services;

use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowConfig;
use App\Models\TenantRole;

/**
 * Helper untuk fire ApprovalWorkflow dari controller mana pun.
 *
 * Dipakai oleh ModuleCrudController (RoPA/DPIA/Breach/DSR), CrossBorderController
 * (cross-border transfer), VendorRiskController (vendor assessment), dan controller
 * bespoke lain yang butuh trigger workflow saat record di-submit.
 *
 * Logic:
 *   - Lookup ApprovalWorkflowConfig per (org, module).
 *   - Kalau enabled + ada steps → bikin workflow pakai tenant_role_id.
 *   - Kalau gak ada / disabled → fallback ke hardcoded DPO → Admin (legacy).
 *
 * Caller wajib pass orgId, module, recordId. Notification ke approver dispatch
 * sendiri (bukan tanggung jawab dispatcher).
 */
class ApprovalWorkflowDispatcher
{
    /**
     * Dispatch / re-dispatch workflow untuk satu record. updateOrCreate
     * supaya idempoten — submit ulang gak bikin duplicate workflow.
     */
    public static function dispatch(string $orgId, string $module, string $recordId): ApprovalWorkflow
    {
        $cfg = ApprovalWorkflowConfig::where('org_id', $orgId)
            ->where('module', $module)
            ->first();

        if ($cfg && $cfg->enabled && ! empty($cfg->steps)) {
            $steps = [];
            foreach ($cfg->steps as $s) {
                $tr = TenantRole::find($s['tenant_role_id'] ?? null);
                $steps[] = [
                    'tenant_role_id' => $s['tenant_role_id'] ?? null,
                    'role' => $tr ? strtolower($tr->name) : null,
                    'status' => 'pending',
                    'name' => $s['label'] ?? ($tr->name ?? 'Approval'),
                ];
            }
        } else {
            // Default fallback: DPO → Admin (backward-compat)
            $steps = [
                ['role' => 'dpo', 'status' => 'pending', 'name' => 'Review DPO'],
                ['role' => 'admin', 'status' => 'pending', 'name' => 'Final Approval (Management)'],
            ];
        }

        return ApprovalWorkflow::updateOrCreate(
            ['module' => $module, 'record_id' => $recordId, 'status' => 'pending'],
            ['org_id' => $orgId, 'steps' => $steps, 'current_step' => 0]
        );
    }
}
