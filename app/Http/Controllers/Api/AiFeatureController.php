<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiService;
use App\Services\CreditService;
use App\Services\TenantContextService;
use App\Services\TenantStorageService;
use App\Models\{AiResult, AiCreditLog, GapAssessment, Ropa, Dpia, BreachIncident, DsrRequest, BreachSimulation, License, Organization};
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

    /**
     * Save AI result to database and return response
     */
    /**
     * Check credit availability. Returns error response or null if OK.
     */
    private function checkCredit(Request $request, string $actionType)
    {
        $orgId = $request->user()->org_id;
        if (!$orgId) return null; // superadmin bypass

        CreditService::resetIfNeeded($orgId);

        if (!CreditService::hasCredit($orgId, $actionType)) {
            $cost = CreditService::getCost($actionType);
            return response()->json([
                'message' => "Quota AI Anda habis bulan ini. Dibutuhkan {$cost} credit untuk fitur ini.",
                'credits_exhausted' => true,
                'upgrade_required' => true,
            ], 402);
        }

        return null;
    }

    /**
     * Save AI result + deduct credit (only on success)
     */
    private function saveAndRespond(Request $request, string $featureType, ?array $response, array $inputData = [], ?string $recordId = null, ?string $recordType = null)
    {
        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;

        if (!$response) {
            // Log failed attempt (NO credit deducted)
            if ($orgId) {
                CreditService::logFailed($orgId, $userId, $featureType, 'AI response null/unavailable');
            }
            return response()->json(['message' => 'AI sedang tidak tersedia', 'credits_used' => 0], 502);
        }

        $saved = AiResult::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'feature_type' => $featureType,
            'record_id' => $recordId,
            'record_type' => $recordType,
            'input_data' => $inputData,
            'result_data' => $response,
        ]);

        // Deduct credit only on success
        $creditLog = null;
        if ($orgId) {
            $creditLog = CreditService::deduct($orgId, $userId, $featureType, $this->featureToModule($featureType), $recordId);
        }

        $org = $orgId ? Organization::find($orgId) : null;

        return response()->json([
            'data' => $response,
            'type' => $featureType,
            'ai_result_id' => $saved->id,
            'saved' => true,
            'credits_used' => $creditLog?->credits_used ?? 0,
            'credits_remaining' => $org ? ($org->ai_credits_remaining + $org->ai_credits_purchased) : null,
        ]);
    }

    private function featureToModule(string $featureType): ?string
    {
        return match (true) {
            str_contains($featureType, 'ropa') => 'ropa',
            str_contains($featureType, 'dpia') => 'dpia',
            str_contains($featureType, 'breach') => 'breach',
            str_contains($featureType, 'dsr') => 'dsr',
            str_contains($featureType, 'consent') => 'consent',
            str_contains($featureType, 'gap') => 'gap',
            str_contains($featureType, 'drill') => 'simulation',
            str_contains($featureType, 'dashboard') => 'dashboard',
            str_contains($featureType, 'chat') => 'chat',
            str_contains($featureType, 'contract') => 'contract-review',
            str_contains($featureType, 'policy') => 'policy-review',
            str_contains($featureType, 'discovery') => 'data-discovery',
            default => null,
        };
    }

    /**
     * Get previous AI results for a record
     * Sanitizes corrupt/invalid cached results to prevent frontend crashes
     */
    public function history(Request $request, string $featureType, string $recordId)
    {
        $results = AiResult::where('org_id', $request->user()->org_id)
            ->where('feature_type', $featureType)
            ->where('record_id', $recordId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Sanitize: filter out entries with null/invalid result_data
        $sanitized = $results->filter(function ($item) {
            $data = $item->result_data;
            // result_data must be a non-null array/object
            if (is_null($data)) return false;
            if (is_string($data)) {
                // Try to decode if stored as string
                $decoded = json_decode($data, true);
                if ($decoded === null) return false;
                $item->result_data = $decoded;
            }
            return true;
        })->values();

        return response()->json(['data' => $sanitized]);
    }

    // =============================================
    // GAP ASSESSMENT — AI Remediation Plan
    // =============================================
    public function gapRemediation(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'gap_remediation');
        if ($creditErr) return $creditErr;

        $assessment = GapAssessment::findOrFail($id);
        $result = GapAssessment::calculateScore($assessment->answers ?? []);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
            'overall_score' => $result['overall_score'] ?? 0,
            'compliance_level' => $result['compliance_level'] ?? 'low',
            'recommendations_count' => count($result['recommendations'] ?? []),
        ];

        $response = $ai->gapRemediationPlan(
            $result['recommendations'] ?? [],
            $result['overall_score'] ?? 0,
            $result['compliance_level'] ?? 'low'
        );

        return $this->saveAndRespond($request, 'gap_remediation', $response, $inputData, $id, 'GapAssessment');
    }

    // =============================================
    // GAP COMPARISON — AI Scoring & Insight
    // =============================================
    public function gapComparisonGenerate(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        
        $comparison = \App\Models\GapComparison::findOrFail($id);
        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $systemPrompt = "Kamu adalah Data Protection Officer ahli UU PDP Indonesia. Output WAJIB berupa JSON valid.\n"
            . "Format: {\"ai_score_mapping\":[{\"version\":\"...\",\"category\":\"...\",\"ai_score\":85}], \"sections\":[{\"type\":\"text\",\"title\":\"...\",\"content\":\"...\"}], \"closing\":\"...\"}";

        $userPrompt = "Lakukan asesmen ulang (AI Scoring) dan analisis untuk perbandingan Gap Assessment ini:\n"
            . "Chart Data Historis: " . json_encode($comparison->chart_data) . "\n"
            . "Sistem Score Asal: " . json_encode($comparison->chart_data) . "\n\n"
            . "Berikan:\n"
            . "1. Tinjauan ulang probabilitas kepatuhan di dunia nyata untuk setiap kategori (AI Score vs System Score). Tentukan skor realistik versi AI di 'ai_score_mapping'.\n"
            . "2. Analisis DPO tentang tren (Sections)\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        $response = $ai->ask($systemPrompt, $userPrompt, 3000);

        return $this->saveAndRespond($request, 'gap_comparison', $response, ['chart_data' => $comparison->chart_data], $id, 'GapComparison');
    }

    // =============================================
    // ROPA — AI Analysis
    // =============================================
    public function ropaAnalysis(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'analysis_ropa');
        if ($creditErr) return $creditErr;

        $ropa = Ropa::findOrFail($id);
        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
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
        ];

        $response = $ai->ropaAnalysis($inputData);

        return $this->saveAndRespond($request, 'ropa_analysis', $response, $inputData, $id, 'Ropa');
    }

    // =============================================
    // DPIA — AI Risk Scoring
    // =============================================
    public function dpiaRiskScoring(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'analysis_dpia');
        if ($creditErr) return $creditErr;

        $dpia = Dpia::findOrFail($id);
        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
            'description' => $dpia->description,
            'risk_level' => $dpia->risk_level,
            'wizard_data' => $dpia->wizard_data,
            'risk_assessment' => $dpia->risk_assessment ?? [],
        ];

        $response = $ai->dpiaRiskScoring(
            [
                'description' => $dpia->description,
                'risk_level' => $dpia->risk_level,
                'wizard_data' => $dpia->wizard_data,
            ],
            $dpia->risk_assessment ?? []
        );

        return $this->saveAndRespond($request, 'dpia_risk_scoring', $response, $inputData, $id, 'Dpia');
    }

    // =============================================
    // BREACH — AI Advisor
    // =============================================
    public function breachAdvisor(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'analysis_breach');
        if ($creditErr) return $creditErr;

        $breach = BreachIncident::findOrFail($id);
        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
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
        ];

        $response = $ai->breachAdvisor($inputData);

        return $this->saveAndRespond($request, 'breach_advisor', $response, $inputData, $id, 'BreachIncident');
    }

    // =============================================
    // DSR — AI Response Draft
    // =============================================
    public function dsrDraft(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'analysis_dsr');
        if ($creditErr) return $creditErr;

        $dsr = DsrRequest::findOrFail($id);
        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
            'request_type' => $dsr->request_type,
            'requester_name' => $dsr->requester_name,
            'description' => $dsr->description,
            'status' => $dsr->status,
            'deadline_at' => $dsr->deadline_at?->toISOString(),
            'verification_status' => $dsr->verification_status,
        ];

        $response = $ai->dsrResponseDraft($inputData);

        return $this->saveAndRespond($request, 'dsr_draft', $response, $inputData, $id, 'DsrRequest');
    }

    // =============================================
    // CONSENT — AI Text Generator
    // =============================================
    public function consentGenerator(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'analysis_consent');
        if ($creditErr) return $creditErr;

        $request->validate([
            'purpose' => 'required|string',
            'data_types' => 'required|array',
            'domain' => 'nullable|string',
        ]);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
            'purpose' => $request->purpose,
            'data_types' => $request->data_types,
            'domain' => $request->domain ?? 'unknown',
        ];

        $response = $ai->consentTextGenerator(
            $request->purpose,
            $request->data_types,
            $request->domain ?? 'unknown'
        );

        return $this->saveAndRespond($request, 'consent_generator', $response, $inputData);
    }

    // =============================================
    // DASHBOARD — AI Compliance Summary
    // =============================================
    public function dashboardSummary(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'dashboard_summary');
        if ($creditErr) return $creditErr;

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

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $response = $ai->complianceSummary($stats);

        // Tie the saved result to the tenant org so the front-end
        // AiFeatureButton can retrieve the last cached summary via
        // /ai-features/history/dashboard_summary/{org_id}.
        return $this->saveAndRespond($request, 'dashboard_summary', $response, $stats, $orgId, 'Organization');
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

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
            'industry' => $request->industry,
            'risk_profile' => $request->risk_profile ?? 'medium',
            'question_count' => $request->question_count ?? 5,
        ];

        $response = $ai->customDrillScenario(
            $request->industry,
            $request->risk_profile ?? 'medium',
            $request->question_count ?? 5
        );

        return $this->saveAndRespond($request, 'drill_scenario', $response, $inputData);
    }

    // =============================================
    // AUTO-FILL ENDPOINTS
    // =============================================

    public function autofillRopa(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'autofill_ropa');
        if ($creditErr) return $creditErr;

        $request->validate(['activity_name' => 'required|string|max:500']);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $context = TenantContextService::buildContext($request->user()->org_id);
        $response = $ai->ropaAutoFill($request->activity_name, $context);

        return $this->saveAndRespond($request, 'autofill_ropa', $response, ['activity_name' => $request->activity_name]);
    }

    public function autofillDpia(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'autofill_dpia');
        if ($creditErr) return $creditErr;

        $request->validate(['description' => 'required|string|max:500']);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $context = TenantContextService::buildContext($request->user()->org_id);
        $response = $ai->dpiaAutoFill($request->description, $context);

        return $this->saveAndRespond($request, 'autofill_dpia', $response, ['description' => $request->description]);
    }

    /**
     * Sprint F1/F2/F3: Assessment AI analysis (generic dispatcher).
     */
    public function assessmentAnalysis(Request $request, string $kind)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $feature = "assessment_{$kind}";
        $creditErr = $this->checkCredit($request, $feature);
        if ($creditErr) return $creditErr;

        $data = $request->validate(['id' => 'required|uuid']);
        $orgId = $request->user()->org_id;

        $model = match ($kind) {
            'lia' => \App\Models\LiaAssessment::class,
            'tia' => \App\Models\TiaAssessment::class,
            'maturity' => \App\Models\MaturityAssessment::class,
            default => null,
        };
        if (!$model) return response()->json(['message' => 'Unknown assessment kind'], 422);

        $record = $model::where('org_id', $orgId)->find($data['id']);
        if (!$record) return response()->json(['message' => 'Assessment tidak ditemukan'], 404);

        $ai = (new AiService($orgId))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $response = match ($kind) {
            'lia' => $ai->liaAnalysis($record->toArray()),
            'tia' => $ai->tiaAnalysis($record->toArray()),
            'maturity' => $ai->maturityAnalysis($record->toArray()),
        };

        // Persist key fields back to record for convenience
        if ($response) {
            $updates = [];
            if ($kind === 'lia') {
                if (isset($response['overall_score'])) $updates['overall_score'] = $response['overall_score'];
                if (isset($response['assessment_result'])) $updates['assessment_result'] = $response['assessment_result'];
            } elseif ($kind === 'tia') {
                if (isset($response['overall_risk_level'])) $updates['overall_risk_level'] = $response['overall_risk_level'];
            } elseif ($kind === 'maturity') {
                if (isset($response['overall_level'])) $updates['overall_level'] = $response['overall_level'];
                if (isset($response['overall_score'])) $updates['overall_score'] = $response['overall_score'];
                if (isset($response['roadmap'])) $updates['recommendations'] = $response['roadmap'];
            }
            if (!empty($updates)) $record->update($updates);
        }

        return $this->saveAndRespond($request, $feature, $response, ['id' => $data['id']]);
    }

    /**
     * Sprint X4: "Tanya AI" — free-form question against a specific
     * LIA/TIA/Maturity record. Distinct from `assessmentAnalysis`
     * (which returns full structured analysis) — this is conversational
     * Q&A grounded in the specific record so an auditor can drill into
     * "kenapa skor balancing test segini?" or "apa risiko utama transfer
     * ini?" with citations.
     */
    public function assessmentAskAi(Request $request, string $kind)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $feature = "assessment_ask_{$kind}";
        $creditErr = $this->checkCredit($request, $feature);
        if ($creditErr) return $creditErr;

        $data = $request->validate([
            'id' => 'required|uuid',
            'question' => 'required|string|min:5|max:1000',
        ]);

        $orgId = $request->user()->org_id;
        $model = match ($kind) {
            'lia' => \App\Models\LiaAssessment::class,
            'tia' => \App\Models\TiaAssessment::class,
            'maturity' => \App\Models\MaturityAssessment::class,
            default => null,
        };
        if (!$model) return response()->json(['message' => 'Unknown assessment kind'], 422);

        $record = $model::where('org_id', $orgId)->find($data['id']);
        if (!$record) return response()->json(['message' => 'Assessment tidak ditemukan'], 404);

        // For maturity, attach question responses so the AI can reason about
        // specific sub-question scores (e.g. "kenapa C12 enkripsi cuma 4?")
        $payload = $record->toArray();
        if ($kind === 'maturity') {
            $payload['responses'] = $record->responses()->get(['question_code', 'domain', 'score', 'notes', 'source'])->toArray();
        }

        $ai = (new AiService($orgId))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $response = $ai->assessmentAskAi($kind, $payload, $data['question']);

        return $this->saveAndRespond($request, $feature, $response, [
            'id' => $data['id'],
            'question' => $data['question'],
        ]);
    }

    /**
     * Sprint D4: Generate dynamic containment steps for a breach incident.
     */
    public function breachContainmentSteps(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'breach_containment');
        if ($creditErr) return $creditErr;

        $data = $request->validate(['breach_id' => 'required|uuid']);

        $breach = BreachIncident::where('org_id', $request->user()->org_id)->find($data['breach_id']);
        if (!$breach) return response()->json(['message' => 'Breach tidak ditemukan'], 404);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $response = $ai->breachContainmentSteps([
            'title' => $breach->title,
            'description' => $breach->description,
            'severity' => $breach->severity,
            'source' => $breach->source,
            'affected_data_types' => $breach->affected_data_types,
            'affected_subjects_count' => $breach->affected_subjects_count,
            'root_cause' => $breach->root_cause,
        ]);

        if ($response && !empty($response['containment_steps'])) {
            $breach->update(['containment_steps' => $response['containment_steps']]);
        }

        return $this->saveAndRespond($request, 'breach_containment', $response, [
            'breach_id' => $data['breach_id'],
        ]);
    }

    /**
     * Sprint D1: Contract upload & structured compliance review (comply vs non-comply + page refs).
     */
    public function contractAnalyze(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'contract_review');
        if ($creditErr) return $creditErr;

        $request->validate([
            'file' => 'required|file|mimes:pdf,docx|max:10240',
            'contract_type' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $org = Organization::findOrFail($request->user()->org_id);
        $ts = app(TenantStorageService::class);
        $stored = $ts->storeTenantPrivateFile($org, $file, 'contract-uploads');
        $storedPath = $stored['path'];
        [$fullPath, $cleanup] = $ts->getLocalPathForProcessing($org, $storedPath);

        try {
            $parser = new \App\Services\DocumentParserService();
            $parsed = $parser->parse($fullPath, $ext);
        } catch (\Exception $e) {
            $cleanup();
            return response()->json(['message' => 'Gagal parse dokumen: ' . $e->getMessage()], 422);
        }
        $cleanup();

        $pages = [];
        foreach (($parsed['sections'] ?? []) as $idx => $sec) {
            $pages[] = ['page' => $idx + 1, 'text' => $sec['content'] ?? ''];
        }
        if (count($pages) === 0) {
            return response()->json(['message' => 'Dokumen tidak berisi teks yang bisa dibaca'], 422);
        }

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $response = $ai->contractComplianceAnalyzer($pages, $request->contract_type ?? 'other');

        try {
            DB::table('contract_reviews')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'org_id' => $request->user()->org_id,
                'title' => $file->getClientOriginalName(),
                'contract_type' => $request->contract_type ?? 'other',
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'review_result' => json_encode($response),
                'risk_score' => $response['risk_score'] ?? 0,
                'status' => 'completed',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::warning('contract_reviews save failed: ' . $e->getMessage());
        }

        return $this->saveAndRespond($request, 'contract_review', $response, [
            'file_name' => $file->getClientOriginalName(),
            'pages' => count($pages),
        ]);
    }

    /**
     * Sprint D2: Policy / SOP upload & compliance review.
     */
    public function policyAnalyze(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'policy_review');
        if ($creditErr) return $creditErr;

        $request->validate([
            'file' => 'required|file|mimes:pdf,docx|max:10240',
            'policy_type' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $org = Organization::findOrFail($request->user()->org_id);
        $ts = app(TenantStorageService::class);
        $stored = $ts->storeTenantPrivateFile($org, $file, 'policy-uploads');
        $storedPath = $stored['path'];
        [$fullPath, $cleanup] = $ts->getLocalPathForProcessing($org, $storedPath);

        try {
            $parser = new \App\Services\DocumentParserService();
            $parsed = $parser->parse($fullPath, $ext);
        } catch (\Exception $e) {
            $cleanup();
            return response()->json(['message' => 'Gagal parse dokumen: ' . $e->getMessage()], 422);
        }
        $cleanup();

        $pages = [];
        foreach (($parsed['sections'] ?? []) as $idx => $sec) {
            $pages[] = ['page' => $idx + 1, 'text' => $sec['content'] ?? ''];
        }
        if (count($pages) === 0) {
            return response()->json(['message' => 'Dokumen tidak berisi teks yang bisa dibaca'], 422);
        }

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $response = $ai->policyComplianceAnalyzer($pages, $request->policy_type ?? 'sop');

        try {
            DB::table('policy_reviews')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'org_id' => $request->user()->org_id,
                'title' => $file->getClientOriginalName(),
                'policy_type' => $request->policy_type ?? 'sop',
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'review_result' => json_encode($response),
                'risk_score' => $response['risk_score'] ?? 0,
                'status' => 'completed',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::warning('policy_reviews save failed: ' . $e->getMessage());
        }

        return $this->saveAndRespond($request, 'policy_review', $response, [
            'file_name' => $file->getClientOriginalName(),
            'pages' => count($pages),
        ]);
    }

    /**
     * Sprint C6: Batch AI Review. Queues a review job per record and returns batch_id
     * so the frontend can poll ai_results for progress.
     */
    public function batchReview(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();

        $data = $request->validate([
            'module' => 'required|in:ropa,dpia',
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'required|uuid',
        ]);

        $orgId = $request->user()->org_id;

        // Credit check: 1 credit per record, deducted by individual job via AiResult flow.
        $cost = count($data['ids']);
        if ($orgId) {
            $org = Organization::find($orgId);
            $available = ($org->ai_credits_remaining ?? 0) + ($org->ai_credits_purchased ?? 0);
            if ($available < $cost) {
                return response()->json([
                    'message' => "Quota AI tidak cukup untuk batch ({$cost} credits dibutuhkan, {$available} tersedia).",
                    'credits_exhausted' => true,
                ], 402);
            }
        }

        $batchId = (string) \Illuminate\Support\Str::uuid();

        foreach ($data['ids'] as $recordId) {
            \App\Jobs\BatchAiReviewJob::dispatch(
                $orgId,
                $request->user()->id,
                $data['module'],
                $recordId,
                $batchId,
                $request->user()->locale ?? 'id'
            );
        }

        return response()->json([
            'message' => "Batch review dimulai untuk {$cost} record",
            'batch_id' => $batchId,
            'total' => $cost,
        ]);
    }

    /**
     * Sprint C6: Batch review progress — count AiResult rows tagged with batch_id.
     */
    public function batchReviewStatus(Request $request, string $batchId)
    {
        $orgId = $request->user()->org_id;
        $results = AiResult::where('org_id', $orgId)
            ->whereIn('feature', ['analysis_ropa', 'analysis_dpia'])
            ->where('input_summary', 'like', '%"batch_id":"' . $batchId . '"%')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'batch_id' => $batchId,
            'completed' => $results->count(),
            'results' => $results,
        ]);
    }

    /**
     * Sprint C2: AI-generated RACI matrix for ROPA / DPIA.
     */
    public function generateRaci(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'generate_raci');
        if ($creditErr) return $creditErr;

        $data = $request->validate([
            'module' => 'required|in:ropa,dpia',
            'record_id' => 'required|uuid',
        ]);

        $orgId = $request->user()->org_id;
        $record = $data['module'] === 'ropa'
            ? Ropa::where('org_id', $orgId)->find($data['record_id'])
            : Dpia::where('org_id', $orgId)->find($data['record_id']);

        if (!$record) return response()->json(['message' => 'Record tidak ditemukan'], 404);

        $users = \App\Models\User::where('org_id', $orgId)
            ->select('id', 'name', 'role')
            ->get()
            ->map(fn($u) => ['name' => $u->name, 'role' => $u->role])
            ->toArray();

        if (count($users) < 2) {
            return response()->json(['message' => 'Butuh minimal 2 user dalam tenant untuk generate RACI'], 422);
        }

        $recordData = [
            'title' => $record->processing_activity ?? $record->description ?? '',
            'description' => $record->description ?? '',
            'risk_level' => $record->risk_level ?? null,
            'wizard_data' => $record->wizard_data ?? [],
        ];

        $ai = (new AiService($orgId))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $response = $ai->raciSuggestion($data['module'], $recordData, $users);

        return $this->saveAndRespond($request, 'generate_raci', $response, [
            'module' => $data['module'],
            'record_id' => $data['record_id'],
        ]);
    }

    public function autofillBreach(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'autofill_breach');
        if ($creditErr) return $creditErr;

        $request->validate(['incident_title' => 'required|string|max:500']);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $context = TenantContextService::buildContext($request->user()->org_id);
        $response = $ai->breachAutoFill($request->incident_title, $context);

        return $this->saveAndRespond($request, 'autofill_breach', $response, ['incident_title' => $request->incident_title]);
    }

    public function autofillDsr(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'autofill_dsr');
        if ($creditErr) return $creditErr;

        $request->validate([
            'request_type' => 'required|string',
            'requester_name' => 'required|string|max:255',
        ]);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $context = TenantContextService::buildContext($request->user()->org_id);
        $response = $ai->dsrAutoFill($request->request_type, $request->requester_name, $context);

        return $this->saveAndRespond($request, 'autofill_dsr', $response, [
            'request_type' => $request->request_type,
            'requester_name' => $request->requester_name,
        ]);
    }

    public function autofillConsentItems(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'autofill_consent');
        if ($creditErr) return $creditErr;

        $point = DB::table('consent_collection_points')->where('id', $id)->first();
        if (!$point) return response()->json(['message' => 'Collection point not found'], 404);

        $existingItems = DB::table('consent_items')->where('collection_point_id', $id)->pluck('title')->toArray();

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $context = TenantContextService::buildContext($request->user()->org_id);
        $response = $ai->consentItemsGenerator($context, $point->name, $point->domain, $existingItems);

        return $this->saveAndRespond($request, 'autofill_consent', $response, ['point_name' => $point->name]);
    }

    public function consentAudit(Request $request, $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        
        $creditErr = $this->checkCredit($request, 'consent_audit');
        if ($creditErr) return $creditErr;

        $point = \App\Models\ConsentCollectionPoint::where('org_id', $request->user()->org_id)->with('items')->find($id);
        if (!$point) return response()->json(['message' => 'Collection point not found'], 404);

        $items = $point->items->map(function ($i) { 
            return "ID: {$i->id} | Title: {$i->title} | Required: " . ($i->is_required ? 'Yes' : 'No') . " | Text: {$i->description} {$i->full_text}"; 
        })->implode("\n");

        if (empty($items)) {
            return response()->json(['message' => 'Tidak ada consent items untuk diaudit. Silakan tambahkan minimal satu item.', 'status' => 'error'], 400);
        }

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) return response()->json(['message' => 'API key belum dikonfigurasi'], 503);

        $context = TenantContextService::buildContext($request->user()->org_id);
        
        $systemPrompt = "Kamu adalah auditor kepatuhan UU PDP (No. 27/2022). Jabaran tugas:\n"
            . "Audit item persetujuan (consent items) untuk titik pengumpulan data. Berikan evaluasi komprehensif terkait transparansi, spesifikitas, dan kepatuhan.\n"
            . "Konteks Tenant:\n$context\n\n"
            . "Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info|table|code\",\"title\":\"...\",\"content\":\"...\",\"items\":[],\"table_data\":[{\"col1\":\"v1\",\"col2\":\"v2\"}],\"headers\":[\"col1\",\"col2\"]}],\"closing\":\"...\"}";

        $userPrompt = "Audit consent items berikut untuk domain {$point->domain} (Tujuan: {$point->name}):\n\n$items\n\n"
            . "Berikan:\n"
            . "1. Risk assessment keseluruhan (overall risk: High/Medium/Low) dan skor 0-100\n"
            . "2. Evaluasi per-item: apakah sudah transparan, spesifik, dan comply UU PDP\n"
            . "3. Temuan masalah beserta dampak dan rekomendasi perbaikan\n"
            . "4. Elemen krusial yang hilang atau perlu ditambahkan\n"
            . "5. Warning jika ada potensi pelanggaran UU PDP\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        $response = $ai->ask($systemPrompt, $userPrompt, 2500);

        return $this->saveAndRespond($request, 'consent_audit', $response, ['point_name' => $point->name]);
    }

    // =============================================
    // FIRE DRILL — AI Custom Scenario Generator
    // =============================================
    public function drillScenarioGenerator(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'drill_scenario');
        if ($creditErr) return $creditErr;

        $mode = $request->input('mode', 'quiz'); // quiz | tabletop | walkthrough
        $industry = $request->input('industry', 'Teknologi');
        $riskProfile = $request->input('risk_profile', 'medium');
        $questionCount = min((int) $request->input('question_count', 5), 10);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        if ($mode === 'quiz') {
            $result = $ai->customDrillScenario($industry, $riskProfile, $questionCount);
        } elseif ($mode === 'tabletop') {
            $systemPrompt = "Kamu adalah cybersecurity incident response trainer. Output WAJIB JSON valid.\n"
                . "Format KHUSUS tabletop exercise:\n"
                . "{\"title\":\"...\",\"emoji\":\"...\",\"description\":\"...\","
                . "\"steps\":[{\"phase\":\"...\",\"situation\":\"...\",\"prompt\":\"...\",\"time_limit\":300,"
                . "\"guidance\":\"...\",\"ideal_response\":\"...\"}]}";

            $userPrompt = "Generate skenario tabletop exercise kustom:\n"
                . "- Industri: {$industry}\n- Risk profile: {$riskProfile}\n- Jumlah tahap: {$questionCount}\n\n"
                . "Buat skenario realistis dengan:\n"
                . "1. Setiap step berupa situasi naratif yang harus direspon secara tertulis\n"
                . "2. Phase: Detection, Assessment, Containment, Notification, Recovery\n"
                . "3. time_limit dalam detik (300-600)\n"
                . "4. guidance: petunjuk pemikiran (tampil opsional)\n"
                . "5. ideal_response: jawaban ideal untuk evaluasi\n"
                . "6. Bahasa Indonesia\n"
                . "Output JSON mentah saja.";

            $result = $ai->ask($systemPrompt, $userPrompt, 4000);
        } else { // walkthrough
            $systemPrompt = "Kamu adalah cybersecurity SOP trainer. Output WAJIB JSON valid.\n"
                . "Format KHUSUS walkthrough exercise:\n"
                . "{\"title\":\"...\",\"emoji\":\"...\",\"description\":\"...\","
                . "\"steps\":[{\"phase\":\"...\",\"title\":\"...\",\"description\":\"...\",\"time_limit\":300,"
                . "\"checklist\":[{\"id\":\"...\",\"label\":\"...\",\"critical\":true/false}],"
                . "\"success_criteria\":\"...\"}]}";

            $userPrompt = "Generate skenario SOP walkthrough kustom:\n"
                . "- Industri: {$industry}\n- Risk profile: {$riskProfile}\n- Jumlah fase: {$questionCount}\n\n"
                . "Buat SOP walkthrough realistis dengan:\n"
                . "1. Setiap step = fase SOP dengan checklist items\n"
                . "2. Phase: Detection, Assessment, Containment, Notification, Recovery\n"
                . "3. 3-5 checklist items per step\n"
                . "4. critical: true untuk item yang HARUS dicentang\n"
                . "5. success_criteria: kondisi keberhasilan fase\n"
                . "6. time_limit dalam detik (300-600)\n"
                . "7. Bahasa Indonesia\n"
                . "Output JSON mentah saja.";

            $result = $ai->ask($systemPrompt, $userPrompt, 4000);
        }

        if (!$result) {
            return response()->json(['message' => 'AI gagal generate skenario'], 500);
        }

        return response()->json(['data' => $result]);
    }

    // =============================================
    // SIMULATION — AI Performance Analysis
    // =============================================
    public function simulationAnalysis(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'simulation_analysis');
        if ($creditErr) return $creditErr;

        $sim = BreachSimulation::findOrFail($id);
        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $inputData = [
            'scenario_title' => $sim->scenario_title,
            'scenario_type' => $sim->scenario_type,
            'overall_score' => $sim->overall_score,
            'score_breakdown' => $sim->score_breakdown,
            'findings' => $sim->findings,
            'started_at' => $sim->started_at,
            'ended_at' => $sim->ended_at,
        ];

        $systemPrompt = "Kamu adalah cybersecurity trainer dan DPO senior. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info|table|code\",\"title\":\"...\",\"content\":\"...\",\"items\":[],\"table_data\":[{\"col1\":\"v1\",\"col2\":\"v2\"}],\"headers\":[\"col1\",\"col2\"]}],\"closing\":\"...\"}";

        $userPrompt = "Analisis performa drill simulasi berikut:\n" . json_encode($inputData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Berikan:\n"
            . "1. Evaluasi kinerja overall (skor, rating, area kuat)\n"
            . "2. Kelemahan dan blind spots yang terdeteksi\n"
            . "3. Rekomendasi pelatihan spesifik per kelemahan\n"
            . "4. Comparison dengan standar UU PDP\n"
            . "5. Tips untuk drill berikutnya\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        $response = $ai->ask($systemPrompt, $userPrompt, 2500);

        return $this->saveAndRespond($request, 'simulation_analysis', $response, $inputData, $id, 'BreachSimulation');
    }

    // =============================================
    // DATA DISCOVERY — AI PII Classification
    // =============================================
    public function dataDiscoveryClassification(Request $request, string $id)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'discovery_classification');
        if ($creditErr) return $creditErr;

        $system = DB::table('information_systems')->where('id', $id)->where('org_id', $request->user()->org_id)->first();
        if (!$system) return response()->json(['message' => 'System not found'], 404);

        $ai = (new AiService())->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $scanResults = is_string($system->scan_results) ? json_decode($system->scan_results, true) : ($system->scan_results ?? []);

        $inputData = [
            'system_name' => $system->name,
            'source_type' => $system->source_type,
            'tables_count' => count($scanResults['tables'] ?? []),
        ];

        $context = TenantContextService::buildContext($request->user()->org_id);

        // Build column summary for AI
        $columnSummary = '';
        foreach (($scanResults['tables'] ?? []) as $table) {
            $cols = collect($table['columns'] ?? [])->pluck('name')->implode(', ');
            $columnSummary .= "Table: {$table['name']} — Columns: {$cols}\n";
        }

        $systemPrompt = "Kamu adalah ahli data governance dan perlindungan data pribadi UU PDP. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info|table|code\",\"title\":\"...\",\"content\":\"...\",\"items\":[],\"table_data\":[{\"col1\":\"v1\",\"col2\":\"v2\"}],\"headers\":[\"col1\",\"col2\"]}],\"closing\":\"...\"}\n"
            . "Konteks Tenant:\n$context";

        $userPrompt = "Klasifikasikan kolom-kolom database berikut berdasarkan UU PDP Indonesia:\n\n"
            . "Sistem: {$system->name} ({$system->source_type})\n\n"
            . $columnSummary . "\n"
            . "Berikan:\n"
            . "1. Klasifikasi setiap kolom yang terdeteksi sebagai PII (Data Pribadi Umum / Spesifik)\n"
            . "2. Rekomendasi enkripsi untuk kolom sensitif\n"
            . "3. Rekomendasi masa retensi per kategori data\n"
            . "4. Warning untuk kolom yang mungkin melanggar prinsip minimisasi data\n"
            . "5. Saran tindakan perbaikan\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        $response = $ai->ask($systemPrompt, $userPrompt, 8000);

        return $this->saveAndRespond($request, 'discovery_classification', $response, $inputData, $id, 'DataDiscoverySystem');
    }

    // =============================================
    // CREDIT MANAGEMENT ENDPOINTS
    // =============================================

    public function creditUsage(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        if ($user->role === 'superadmin') {
            if ($request->has('org_id')) {
                $orgId = $request->org_id;
            } else {
                return response()->json(['data' => CreditService::getAllTenantsUsage()]);
            }
        }

        // Root / system users sometimes have no tenant org — avoid the null
        // path into CreditService (which would error on the org lookup).
        if ($orgId === null || $orgId === '') {
            return response()->json(['data' => [
                'monthly_limit' => 0,
                'remaining' => 0,
                'purchased' => 0,
                'used_this_month' => 0,
                'reset_at' => null,
                'breakdown' => [],
                'recent_logs' => [],
            ]]);
        }

        CreditService::resetIfNeeded($orgId);
        return response()->json(['data' => CreditService::getUsage($orgId)]);
    }

    public function creditTopup(Request $request)
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        $request->validate([
            'org_id' => 'required|uuid',
            'amount' => 'required|integer|min:1|max:10000',
            'type' => 'nullable|string', // 'monthly' or 'purchased'
        ]);

        $org = Organization::findOrFail($request->org_id);
        $type = $request->type ?? 'purchased';

        if ($type === 'monthly') {
            $org->update(['ai_credits_monthly' => $request->amount]);
        } else {
            $org->increment('ai_credits_purchased', $request->amount);
        }

        return response()->json([
            'message' => "Credit {$type} updated for {$org->name}",
            'data' => [
                'monthly_limit' => $org->fresh()->ai_credits_monthly,
                'remaining' => $org->fresh()->ai_credits_remaining,
                'purchased' => $org->fresh()->ai_credits_purchased,
            ],
        ]);
    }

    // =============================================
    // CONTRACT REVIEW — AI Privacy Contract Analyzer
    // =============================================
    public function contractReview(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'contract_review');
        if ($creditErr) return $creditErr;

        $request->validate([
            'contract_text' => 'required|string|min:50',
            'contract_type' => 'nullable|string',
        ]);

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $contractType = $request->contract_type ?? 'vendor';

        $systemPrompt = "Kamu adalah Data Protection Officer ahli UU PDP Indonesia (UU No. 27/2022). "
            . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
            . "Format output:\n"
            . json_encode([
                'overall_rating' => 'baik/perlu_perbaikan/buruk',
                'risk_score' => '0-100 (integer)',
                'findings' => [['clause' => '...', 'issue' => '...', 'risk_level' => 'high/medium/low', 'recommendation' => '...', 'uu_pdp_reference' => 'Pasal X']],
                'missing_clauses' => ['klausul yang seharusnya ada tapi tidak ditemukan'],
                'summary' => 'ringkasan keseluruhan analisis (2-3 kalimat)',
                'compliance_checklist' => [
                    'klausul_tujuan_pemrosesan' => 'boolean',
                    'hak_subjek_data' => 'boolean',
                    'kewajiban_pengendali' => 'boolean',
                    'transfer_lintas_negara' => 'boolean',
                    'masa_retensi' => 'boolean',
                    'mekanisme_pemusnahan' => 'boolean',
                    'klausul_kerahasiaan' => 'boolean',
                    'klausul_pelanggaran_data' => 'boolean',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $userPrompt = "Analisis kontrak/perjanjian berikut dari perspektif perlindungan data pribadi UU PDP.\n\n"
            . "Tipe Kontrak: {$contractType}\n\n"
            . "=== ISI KONTRAK ===\n"
            . mb_substr($request->contract_text, 0, 8000)
            . "\n=== END ===\n\n"
            . "Berikan analisis LENGKAP dalam format JSON yang diminta. "
            . "Identifikasi semua temuan, klausul yang hilang, dan skor risiko 0-100. "
            . "Jawab HANYA JSON valid.";

        $response = $ai->ask($systemPrompt, $userPrompt, 4000);

        $inputData = [
            'contract_type' => $contractType,
            'text_length' => strlen($request->contract_text),
        ];

        // Persist to contract_reviews table
        try {
            DB::table('contract_reviews')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'org_id' => $request->user()->org_id,
                'title' => 'Contract Review — ' . ucfirst($contractType),
                'contract_type' => $contractType,
                'contract_text' => mb_substr($request->contract_text, 0, 20000),
                'review_result' => json_encode($response),
                'risk_score' => $response['risk_score'] ?? 0,
                'overall_rating' => $response['overall_rating'] ?? null,
                'status' => 'completed',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::warning('contract_reviews save failed: ' . $e->getMessage());
        }

        return $this->saveAndRespond($request, 'contract_review', $response, $inputData);
    }

    // =============================================
    // CONTRACT UPLOAD — Extract text from PDF/DOCX then review
    // =============================================
    public function contractUpload(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'contract_review');
        if ($creditErr) return $creditErr;

        $request->validate([
            'file' => 'required|file|mimes:pdf,docx,doc|max:10240', // max 10MB
            'contract_type' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());

        // Tenant-aware storage (S3/MinIO/GCS when configured, else local).
        $org = Organization::findOrFail($request->user()->org_id);
        $ts = app(TenantStorageService::class);
        $stored = $ts->storeTenantPrivateFile($org, $file, 'contract-uploads');
        $storedPath = $stored['path'];
        [$fullPath, $cleanup] = $ts->getLocalPathForProcessing($org, $storedPath);

        // Extract text based on file type
        $extractedText = '';

        try {
            if ($ext === 'pdf') {
                $parser = new \Smalot\PdfParser\Parser();
                $pdf = $parser->parseFile($fullPath);
                $extractedText = $pdf->getText();
            } elseif (in_array($ext, ['docx', 'doc'])) {
                $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);
                $text = '';
                foreach ($phpWord->getSections() as $section) {
                    foreach ($section->getElements() as $element) {
                        if (method_exists($element, 'getText')) {
                            $text .= $element->getText() . "\n";
                        } elseif (method_exists($element, 'getElements')) {
                            foreach ($element->getElements() as $child) {
                                if (method_exists($child, 'getText')) {
                                    $text .= $child->getText() . "\n";
                                }
                            }
                        }
                    }
                }
                $extractedText = $text;
            }
        } catch (\Exception $e) {
            $cleanup();
            return response()->json([
                'message' => 'Gagal mengekstrak teks dari file: ' . $e->getMessage(),
            ], 422);
        }
        $cleanup();

        $extractedText = trim($extractedText);
        if (mb_strlen($extractedText) < 50) {
            return response()->json([
                'message' => 'Teks yang diekstrak terlalu pendek atau file tidak mengandung teks yang dapat dibaca.',
                'extracted_length' => mb_strlen($extractedText),
            ], 422);
        }

        // Run AI review using the same logic as contractReview
        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $contractType = $request->contract_type ?? 'other';

        $systemPrompt = "Kamu adalah Data Protection Officer ahli UU PDP Indonesia (UU No. 27/2022). "
            . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
            . "Format output:\n"
            . json_encode([
                'overall_rating' => 'baik/perlu_perbaikan/buruk',
                'risk_score' => '0-100 (integer)',
                'findings' => [['clause' => '...', 'issue' => '...', 'risk_level' => 'high/medium/low', 'recommendation' => '...', 'uu_pdp_reference' => 'Pasal X']],
                'missing_clauses' => ['klausul yang seharusnya ada tapi tidak ditemukan'],
                'summary' => 'ringkasan keseluruhan analisis (2-3 kalimat)',
                'compliance_checklist' => [
                    'klausul_tujuan_pemrosesan' => 'boolean',
                    'hak_subjek_data' => 'boolean',
                    'kewajiban_pengendali' => 'boolean',
                    'transfer_lintas_negara' => 'boolean',
                    'masa_retensi' => 'boolean',
                    'mekanisme_pemusnahan' => 'boolean',
                    'klausul_kerahasiaan' => 'boolean',
                    'klausul_pelanggaran_data' => 'boolean',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $userPrompt = "Analisis kontrak/perjanjian berikut dari perspektif perlindungan data pribadi UU PDP.\n\n"
            . "Tipe Kontrak: {$contractType}\n"
            . "Nama File: {$file->getClientOriginalName()}\n\n"
            . "=== ISI KONTRAK ===\n"
            . mb_substr($extractedText, 0, 8000)
            . "\n=== END ===\n\n"
            . "Berikan analisis LENGKAP dalam format JSON yang diminta. "
            . "Identifikasi semua temuan, klausul yang hilang, dan skor risiko 0-100. "
            . "Jawab HANYA JSON valid.";

        $response = $ai->ask($systemPrompt, $userPrompt, 4000);

        $inputData = [
            'contract_type' => $contractType,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'text_length' => mb_strlen($extractedText),
        ];

        // Persist to contract_reviews table
        try {
            DB::table('contract_reviews')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'org_id' => $request->user()->org_id,
                'title' => $file->getClientOriginalName(),
                'contract_type' => $contractType,
                'file_path' => $storedPath,
                'file_name' => $file->getClientOriginalName(),
                'review_result' => json_encode($response),
                'risk_score' => $response['risk_score'] ?? 0,
                'overall_rating' => $response['overall_rating'] ?? null,
                'status' => 'completed',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::warning('contract_reviews save failed: ' . $e->getMessage());
        }

        return $this->saveAndRespond($request, 'contract_review', $response, $inputData);
    }

    // =============================================
    // POLICY REVIEW — AI SOP/Policy Compliance Analyzer
    // =============================================
    public function policyReview(Request $request)
    {
        if (!$this->checkAiLicense($request)) return $this->denyBasic();
        $creditErr = $this->checkCredit($request, 'policy_review');
        if ($creditErr) return $creditErr;

        // Accept either text or file
        $policyText = $request->input('policy_text', '');
        $docType = $request->input('doc_type', 'kebijakan_privasi');
        $title = $request->input('title', 'Untitled Policy');
        $storedPath = null;
        $fileName = null;

        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'required|file|mimes:pdf,docx,doc|max:10240',
            ]);

            $file = $request->file('file');
            $ext = strtolower($file->getClientOriginalExtension());
            $fileName = $file->getClientOriginalName();

            $org = Organization::findOrFail($request->user()->org_id);
            $ts = app(TenantStorageService::class);
            $stored = $ts->storeTenantPrivateFile($org, $file, 'policy-uploads');
            $storedPath = $stored['path'];
            [$fullPath, $cleanup] = $ts->getLocalPathForProcessing($org, $storedPath);

            try {
                if ($ext === 'pdf') {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($fullPath);
                    $policyText = $pdf->getText();
                } elseif (in_array($ext, ['docx', 'doc'])) {
                    $phpWord = \PhpOffice\PhpWord\IOFactory::load($fullPath);
                    $text = '';
                    foreach ($phpWord->getSections() as $section) {
                        foreach ($section->getElements() as $element) {
                            if (method_exists($element, 'getText')) {
                                $text .= $element->getText() . "\n";
                            } elseif (method_exists($element, 'getElements')) {
                                foreach ($element->getElements() as $child) {
                                    if (method_exists($child, 'getText')) {
                                        $text .= $child->getText() . "\n";
                                    }
                                }
                            }
                        }
                    }
                    $policyText = $text;
                }
            } catch (\Exception $e) {
                $cleanup();
                return response()->json(['message' => 'Gagal mengekstrak teks: ' . $e->getMessage()], 422);
            }
            $cleanup();
        }

        $policyText = trim($policyText);
        if (mb_strlen($policyText) < 50) {
            return response()->json(['message' => 'Teks kebijakan minimal harus 50 karakter.'], 422);
        }

        $ai = (new AiService($request->user()->org_id))->setLocale($request->user()->locale ?? 'id');
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        $context = TenantContextService::buildContext($request->user()->org_id);

        $docTypeLabels = [
            'kebijakan_privasi' => 'Kebijakan Privasi (Privacy Policy)',
            'sop_data_handling' => 'SOP Penanganan Data Pribadi',
            'sop_breach_response' => 'SOP Respon Pelanggaran Data',
            'peraturan_perusahaan' => 'Peraturan Perusahaan',
            'sop_dsr' => 'SOP Pemenuhan Hak Subjek Data',
            'sop_retensi' => 'SOP Retensi & Pemusnahan Data',
            'other' => 'Dokumen Lainnya',
        ];
        $docLabel = $docTypeLabels[$docType] ?? $docType;

        $systemPrompt = "Kamu adalah auditor kepatuhan senior UU PDP Indonesia (UU No. 27/2022). "
            . "Tugasmu mengaudit kebijakan/SOP internal perusahaan terhadap kepatuhan UU PDP.\n"
            . "Konteks Tenant:\n$context\n\n"
            . "Output WAJIB berupa JSON valid. Format:\n"
            . json_encode([
                'overall_score' => '0-100 (integer)',
                'compliance_level' => 'compliant/partial/non_compliant',
                'summary' => 'ringkasan keseluruhan 2-3 kalimat',
                'sections' => [[
                    'section_title' => 'Nama bagian/pasal dokumen',
                    'status' => 'comply/partial/non_comply/missing',
                    'score' => '0-100',
                    'gap_description' => 'Penjelasan gap yang ditemukan',
                    'recommendation' => 'Saran perbaikan konkret',
                    'uu_pdp_reference' => 'Pasal UU PDP yang relevan',
                ]],
                'missing_elements' => ['elemen penting yang belum ada dalam dokumen'],
                'strengths' => ['hal-hal yang sudah baik'],
                'priority_actions' => [['action' => '...', 'priority' => 'high/medium/low', 'deadline_suggestion' => '...']],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $userPrompt = "Audit dokumen internal berikut terhadap kepatuhan UU PDP Indonesia:\n\n"
            . "Judul: {$title}\n"
            . "Tipe Dokumen: {$docLabel}\n"
            . ($fileName ? "File: {$fileName}\n" : "")
            . "\n=== ISI DOKUMEN ===\n"
            . mb_substr($policyText, 0, 8000)
            . "\n=== END ===\n\n"
            . "Berikan analisis LENGKAP per-section: status comply/partial/non_comply, gap, rekomendasi. "
            . "Sertakan skor keseluruhan 0-100 dan list elemen yang hilang. "
            . "Jawab HANYA JSON valid.";

        $response = $ai->ask($systemPrompt, $userPrompt, 5000);

        $inputData = [
            'title' => $title,
            'doc_type' => $docType,
            'file_name' => $fileName,
            'file_path' => $storedPath,
            'text_length' => mb_strlen($policyText),
        ];

        // Save to policy_reviews table if it exists
        try {
            DB::table('policy_reviews')->insert([
                'id' => \Illuminate\Support\Str::uuid(),
                'org_id' => $request->user()->org_id,
                'title' => $title,
                'doc_type' => $docType,
                'file_path' => $storedPath,
                'review_result' => json_encode($response),
                'risk_score' => $response['overall_score'] ?? 0,
                'status' => 'completed',
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Table may not exist yet, proceed anyway
            \Log::warning('policy_reviews table not available: ' . $e->getMessage());
        }

        return $this->saveAndRespond($request, 'policy_review', $response, $inputData);
    }
}
