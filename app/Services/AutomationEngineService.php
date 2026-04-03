<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Ropa;
use App\Models\Dpia;
use Illuminate\Support\Facades\Log;

class AutomationEngineService
{
    /**
     * Run automation actions based on the organization's rules.
     */
    public function runAutomations(string $orgId): void
    {
        $rules = AutomationRule::where('org_id', $orgId)->where('is_active', true)->get()->keyBy('rule_type');

        // Rule: dpia_auto_trigger
        if ($rules->has('dpia_auto_trigger')) {
            $this->triggerDpiaFromHighRiskRopa($orgId);
        }

        // Add more handlers as needed, e.g., dsr_auto_draft logic for new DSRs, etc.
    }

    protected function triggerDpiaFromHighRiskRopa(string $orgId): void
    {
        $highRiskRopas = Ropa::where('org_id', $orgId)
            ->where('risk_level', 'High')
            ->get();

        foreach ($highRiskRopas as $ropa) {
            $hasDpia = Dpia::where('org_id', $orgId)
                ->where('ropa_id', $ropa->id)
                ->exists();

            if (!$hasDpia) {
                // Automatically spawn a new DPIA record
                $dpia = Dpia::create([
                    'org_id' => $orgId,
                    'ropa_id' => $ropa->id,
                    'reference_number' => 'DPIA-AUTO-' . date('Ymd-His') . '-' . substr(uniqid(), -4),
                    'project_name' => "DPIA for " . $ropa->processing_activity_name,
                    'status' => 'draft',
                    'overall_risk' => 'High',
                    'business_process' => "Automatically generated because ROPA risk level is HIGH.",
                    'created_by' => null, // System generated
                ]);

                Log::info("Auto-triggered DPIA [{$dpia->id}] for High-Risk ROPA [{$ropa->id}] in Org [{$orgId}]");
            }
        }
    }
}
