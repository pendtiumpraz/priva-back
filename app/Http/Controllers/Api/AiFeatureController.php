<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiService;
use App\Models\{GapAssessment, Ropa, Dpia, BreachIncident, DsrRequest, BreachSimulation, License};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiFeatureController extends Controller
{
    private function checkAiLicense(Request $request): ?License
    {
        $user = $request->user();
        $license = License::where('org_id', $user->org_id)
            ->where('status', 'active')
            ->first();

        if (!$license || $license->package_type === 'basic') {
            return null;
        }
        return $license;
    }

    private function denyBasic()
    {
        return response()->json([
            'message' => 'Fitur AI hanya tersedia untuk paket Pro AI dan Enterprise.',
            'upgrade_required' => true,
        ], 403);
    }

    // =============================================
    // GAP ASSESSMENT — AI Remediation Plan
    // =============================================
    public function gapRemediation(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $assessment = GapAssessment::findOrFail($id);
        $result = GapAssessment::calculateScore($assessment->answers ?? []);

        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->gapRemediationPlan(
            $result['recommendations'] ?? [],
            $result['overall_score'] ?? 0,
            $result['compliance_level'] ?? 'low'
        );

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'gap_remediation']);
    }

    // =============================================
    // ROPA — AI Analysis
    // =============================================
    public function ropaAnalysis(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $ropa = Ropa::findOrFail($id);
        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->ropaAnalysis([
            'processing_activity' => $ropa->processing_activity,
            'division' => $ropa->division,
            'risk_level' => $ropa->risk_level,
            'purpose' => $ropa->purpose,
            'legal_basis' => $ropa->legal_basis,
            'data_categories' => $ropa->data_categories,
            'data_subjects' => $ropa->data_subjects,
            'recipients' => $ropa->recipients,
            'retention_period' => $ropa->retention_period,
            'security_measures' => $ropa->security_measures,
            'wizard_data' => $ropa->wizard_data,
        ]);

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'ropa_analysis']);
    }

    // =============================================
    // DPIA — AI Risk Scoring
    // =============================================
    public function dpiaRiskScoring(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $dpia = Dpia::findOrFail($id);
        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->dpiaRiskScoring(
            [
                'description' => $dpia->description,
                'risk_level' => $dpia->risk_level,
                'wizard_data' => $dpia->wizard_data,
            ],
            $dpia->risk_assessment ?? []
        );

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'dpia_risk_scoring']);
    }

    // =============================================
    // BREACH — AI Advisor
    // =============================================
    public function breachAdvisor(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $breach = BreachIncident::findOrFail($id);
        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->breachAdvisor([
            'title' => $breach->title,
            'description' => $breach->description,
            'severity' => $breach->severity,
            'source' => $breach->source,
            'affected_data_types' => $breach->affected_data_types,
            'affected_subjects_count' => $breach->affected_subjects_count,
            'root_cause' => $breach->root_cause,
            'containment_checklist' => $breach->containment_checklist,
            'notification_required' => $breach->notification_required,
            'detected_at' => $breach->detected_at?->toISOString(),
        ]);

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'breach_advisor']);
    }

    // =============================================
    // DSR — AI Response Draft
    // =============================================
    public function dsrDraft(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $dsr = DsrRequest::findOrFail($id);
        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->dsrResponseDraft([
            'request_type' => $dsr->request_type,
            'requester_name' => $dsr->requester_name,
            'description' => $dsr->description,
            'status' => $dsr->status,
            'deadline_at' => $dsr->deadline_at?->toISOString(),
            'verification_status' => $dsr->verification_status,
        ]);

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'dsr_draft']);
    }

    // =============================================
    // CONSENT — AI Text Generator
    // =============================================
    public function consentGenerator(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $request->validate([
            'purpose' => 'required|string',
            'data_types' => 'required|array',
            'domain' => 'nullable|string',
        ]);

        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->consentTextGenerator(
            $request->purpose,
            $request->data_types,
            $request->domain ?? 'unknown'
        );

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'consent_generator']);
    }

    // =============================================
    // DASHBOARD — AI Compliance Summary
    // =============================================
    public function dashboardSummary(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $orgId = $request->user()->org_id;

        $latestGap = DB::table('gap_assessments')
            ->where('org_id', $orgId)->whereNull('deleted_at')
            ->latest('created_at')->first();

        $stats = [
            'gap_score' => $latestGap->overall_score ?? 0,
            'gap_level' => $latestGap->compliance_level ?? 'low',
            'total_ropa' => DB::table('ropas')->where('org_id', $orgId)->whereNull('deleted_at')->count(),
            'total_dpia' => DB::table('dpias')->where('org_id', $orgId)->whereNull('deleted_at')->count(),
            'total_dsr' => DB::table('dsr_requests')->where('org_id', $orgId)->whereNull('deleted_at')->count(),
            'dsr_pending' => DB::table('dsr_requests')->where('org_id', $orgId)->whereNull('deleted_at')->where('status', 'pending')->count(),
            'active_breaches' => DB::table('breach_incidents')->where('org_id', $orgId)->whereNull('deleted_at')
                ->whereNotIn('status', ['closed', 'resolved'])->where('is_simulation', false)->count(),
            'total_breaches' => DB::table('breach_incidents')->where('org_id', $orgId)->whereNull('deleted_at')->where('is_simulation', false)->count(),
            'total_simulations' => DB::table('breach_simulations')->where('org_id', $orgId)->whereNull('deleted_at')->count(),
        ];

        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->complianceSummary($stats);

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'dashboard_summary']);
    }

    // =============================================
    // FIRE DRILL — AI Custom Scenario (Enterprise only)
    // =============================================
    public function drillScenario(Request $request)
    {
        $license = $this->checkAiLicense($request);
        if (!$license) return $this->denyBasic();

        // Enterprise-only feature
        if ($license->package_type !== 'ai_agent') {
            return response()->json([
                'message' => 'Fitur AI Custom Scenario hanya tersedia untuk paket Enterprise AI Agent.',
                'upgrade_required' => true,
            ], 403);
        }

        $request->validate([
            'industry' => 'required|string',
            'risk_profile' => 'nullable|string',
            'question_count' => 'nullable|integer|min:3|max:10',
        ]);

        $ai = new AiService();
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->customDrillScenario(
            $request->industry,
            $request->risk_profile ?? 'medium',
            $request->question_count ?? 5
        );

        if (!$response) {
            return response()->json(['message' => 'AI sedang tidak tersedia'], 502);
        }

        return response()->json(['data' => $response, 'type' => 'drill_scenario']);
    }
}
