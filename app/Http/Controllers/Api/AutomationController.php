<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\AutomationRule;

class AutomationController extends Controller
{
    /**
     * Get all automation rules for the organization, creating defaults if not exist
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $defaultRules = [
            'dsr_auto_draft' => ['is_active' => false, 'settings' => ['ai_model' => 'gpt-4o-mini', 'draft_language' => 'id']],
            'dpia_auto_trigger' => ['is_active' => true, 'settings' => ['high_risk_only' => true]],
            'expire_consent_reminder' => ['is_active' => true, 'settings' => ['days_before' => 30]],
            'scheduled_scan_reminder' => ['is_active' => true, 'settings' => ['frequency' => 'monthly', 'alert_overdue' => true]],
        ];

        $existingRules = AutomationRule::where('org_id', $orgId)->get()->keyBy('rule_type');

        foreach ($defaultRules as $type => $data) {
            if (!$existingRules->has($type)) {
                $rule = AutomationRule::create([
                    'org_id' => $orgId,
                    'rule_type' => $type,
                    'is_active' => $data['is_active'],
                    'settings' => $data['settings']
                ]);
                $existingRules->put($type, $rule);
            }
        }

        return response()->json([
            'data' => $existingRules->values()
        ]);
    }

    /**
     * Toggle or update a rule
     */
    public function update(Request $request, string $ruleType)
    {
        $request->validate([
            'is_active' => 'boolean',
            'settings' => 'array'
        ]);

        $rule = AutomationRule::where('org_id', $request->user()->org_id)
            ->where('rule_type', $ruleType)
            ->firstOrFail();

        if ($request->has('is_active')) {
            $rule->is_active = $request->input('is_active');
        }

        if ($request->has('settings')) {
            $rule->settings = $request->input('settings');
        }

        $rule->save();

        return response()->json([
            'message' => 'Automation rule updated successfully',
            'data' => $rule
        ]);
    }
}
