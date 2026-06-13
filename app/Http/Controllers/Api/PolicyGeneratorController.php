<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GeneratePolicyRequest;
use App\Models\AiResult;
use App\Models\AuditLog;
use App\Models\GeneratedPolicy;
use App\Models\License;
use App\Models\Organization;
use App\Services\AiService;
use App\Services\CreditService;
use App\Services\PolicyAutofillService;
use App\Services\PolicyGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Policy Generator — drafts a UU PDP privacy policy from wizard input.
 *
 * Sibling of Policy Review (audit). Gated like every other AI feature
 * (deny basic + credit-metered). Generation logic lives in
 * PolicyGeneratorService; this controller owns gating, credit metering,
 * audit logging and the legal-safety acknowledgement gate.
 *
 * Endpoints:
 *   POST   /api/ai-features/policy/generate
 *   GET    /api/policy-generations
 *   GET    /api/policy-generations/{id}
 *   GET    /api/policy-generations/{id}/download.docx
 *   DELETE /api/policy-generations/{id}
 */
class PolicyGeneratorController extends Controller
{
    private const FEATURE = 'policy_generator';

    private const MODULE = 'policy-generator';

    public function generate(GeneratePolicyRequest $request): JsonResponse
    {
        $license = $this->checkAiLicense($request);
        if (! $license) {
            return $this->denyBasic();
        }
        if ($creditErr = $this->checkCredit($request, self::FEATURE)) {
            return $creditErr;
        }

        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;
        if (! $orgId) {
            return response()->json(['message' => 'Org context missing'], 403);
        }

        $payload = $request->validated();
        $language = strtolower($payload['language'] ?? ($request->user()->locale ?? 'id'));
        $audience = $payload['audience'] ?? GeneratedPolicy::AUDIENCE_CUSTOMER;
        $documentType = $payload['document_type'] ?? 'privacy_policy';

        $ai = (new AiService($orgId, 'chat'))->setLocale($language);
        if (! $ai->isAvailable()) {
            CreditService::logFailed($orgId, $userId, self::FEATURE, 'AI provider unavailable', self::MODULE);

            return response()->json(['message' => 'API key belum dikonfigurasi'], 503);
        }

        try {
            $policy = (new PolicyGeneratorService($ai))->generate(
                $orgId,
                $userId,
                $audience,
                $documentType,
                $language,
                (string) $payload['title'],
                $payload['wizard_inputs'],
            );
        } catch (\RuntimeException $e) {
            CreditService::logFailed($orgId, $userId, self::FEATURE, $e->getMessage(), self::MODULE);

            return response()->json(['message' => $e->getMessage()], 502);
        } catch (\Throwable $e) {
            CreditService::logFailed($orgId, $userId, self::FEATURE, 'unexpected error: '.$e->getMessage(), self::MODULE);
            Log::error('PolicyGenerator.generate failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json(['message' => 'Failed to generate policy.'], 500);
        }

        // Legal-safety audit trail: record generation, the acknowledgement, and
        // the per-clause source map (each AI clause tied to its UU PDP Pasal).
        // audit_logs.module uses the underscore feature name (cf. 'document_maker').
        AuditLog::log(self::FEATURE, $policy->id, 'generated', [
            'document_type' => $policy->document_type,
            'audience' => $policy->audience,
            'language' => $policy->language,
            'legal_acknowledged' => $request->boolean('legal_acknowledgement'),
            'acknowledged_at' => now()->toIso8601String(),
            'disclaimer_version' => $policy->ai_metadata['disclaimer_version'] ?? null,
            'coverage_covered' => $policy->ai_metadata['coverage']['covered_count'] ?? null,
            'needs_manual_review' => $policy->ai_metadata['needs_manual_review'] ?? [],
            'clause_sources' => $policy->ai_metadata['clause_sources'] ?? [],
            'provider' => $policy->ai_provider,
            'model' => $policy->ai_model,
        ]);

        // Reflect the metered cost on the domain row for convenience.
        $cost = (int) ceil(CreditService::getCost(self::FEATURE));
        if ($cost > 0) {
            $policy->update(['credits_used' => $cost]);
        }

        // Version-diff baseline (AI Agent tier only): snapshot the source-module
        // fingerprint so a later change (e.g. RoPA edited) can flag the policy stale.
        if ($license->package_type === 'ai_agent') {
            $meta = is_array($policy->ai_metadata) ? $policy->ai_metadata : [];
            $meta['source_fingerprint'] = (new PolicyAutofillService)->sourceFingerprint($orgId, $audience);
            $policy->update(['ai_metadata' => $meta]);
        }

        return $this->saveAndRespond(
            $request,
            self::FEATURE,
            is_array($policy->ai_output) ? $policy->ai_output : [],
            [
                'title' => $policy->title,
                'document_type' => $policy->document_type,
                'audience' => $policy->audience,
                'language' => $policy->language,
            ],
            $policy->id,
            self::FEATURE,
            [
                'policy_id' => $policy->id,
                'coverage' => $policy->ai_metadata['coverage'] ?? null,
            ],
        );
    }

    /**
     * Auto-Fill: pre-fill wizard inputs from the tenant's existing modules
     * (RoPA/LIA/Consent/TPRM/TIA/DSR/Org), source-tagged per field, for the user
     * to review before generating. Deterministic (no LLM call) → no credit charge.
     * Gated to AI tier (deny basic) like other AI features.
     */
    public function autofill(Request $request): JsonResponse
    {
        if (! $this->checkAiLicense($request)) {
            return $this->denyBasic();
        }

        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return response()->json(['message' => 'Org context missing'], 403);
        }

        $audience = $request->input('audience', GeneratedPolicy::AUDIENCE_CUSTOMER);
        $allowed = [
            GeneratedPolicy::AUDIENCE_CUSTOMER,
            GeneratedPolicy::AUDIENCE_EMPLOYEE,
            GeneratedPolicy::AUDIENCE_JOB_APPLICANT,
            GeneratedPolicy::AUDIENCE_EXTERNAL,
        ];
        if (! in_array($audience, $allowed, true)) {
            $audience = GeneratedPolicy::AUDIENCE_CUSTOMER;
        }

        $prefill = (new PolicyAutofillService)->prefill($orgId, $audience);

        return response()->json(['data' => $prefill]);
    }

    /** List the current org's generated policies (most recent first). */
    public function index(Request $request): JsonResponse
    {
        $rows = GeneratedPolicy::where('org_id', $request->user()->org_id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'audience', 'language', 'document_type', 'status', 'title', 'created_at', 'updated_at']);

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $policy = GeneratedPolicy::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $policy) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $policy]);
    }

    public function downloadDocx(Request $request, string $id)
    {
        $policy = GeneratedPolicy::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $policy) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $path = (new PolicyGeneratorService(new AiService($policy->org_id, 'chat')))->renderDocx($policy);
        } catch (\Throwable $e) {
            Log::error('PolicyGenerator.renderDocx failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to render DOCX.'], 500);
        }

        AuditLog::log(self::FEATURE, $policy->id, 'download.docx');

        $filename = Str::slug($policy->title ?: 'kebijakan-privasi', '_').'.docx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function downloadPdf(Request $request, string $id)
    {
        $policy = GeneratedPolicy::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $policy) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $path = (new PolicyGeneratorService(new AiService($policy->org_id, 'chat')))->renderPdf($policy);
        } catch (\Throwable $e) {
            Log::error('PolicyGenerator.renderPdf failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to render PDF.'], 500);
        }

        AuditLog::log(self::FEATURE, $policy->id, 'download.pdf');

        $filename = Str::slug($policy->title ?: 'kebijakan-privasi', '_').'.pdf';

        return response()->download($path, $filename, ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }

    /** White-labelled, self-contained HTML snippet for embedding on the tenant's website. */
    public function embed(Request $request, string $id)
    {
        $policy = GeneratedPolicy::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $policy) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $html = (new PolicyGeneratorService(new AiService($policy->org_id, 'chat')))->renderHtml($policy);
        } catch (\Throwable $e) {
            Log::error('PolicyGenerator.renderHtml failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to render HTML.'], 500);
        }

        AuditLog::log(self::FEATURE, $policy->id, 'embed.html');

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Version-diff (AI Agent tier): report whether the policy's source-module data
     * has changed since it was generated → re-review recommended.
     */
    public function staleness(Request $request, string $id): JsonResponse
    {
        $license = $this->checkAiLicense($request);
        if (! $license || $license->package_type !== 'ai_agent') {
            return response()->json([
                'message' => 'Version-diff hanya tersedia untuk paket AI Agent.',
                'upgrade_required' => true,
            ], 403);
        }

        $policy = GeneratedPolicy::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $policy) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $stored = $policy->ai_metadata['source_fingerprint'] ?? null;
        $current = (new PolicyAutofillService)->sourceFingerprint($policy->org_id, $policy->audience);
        $stale = $stored !== null && $stored !== $current;

        return response()->json(['data' => [
            'stale' => $stale,
            'has_baseline' => $stored !== null,
            'generated_fingerprint' => $stored,
            'current_fingerprint' => $current,
            'recommendation' => $stale
                ? 'Data sumber (mis. RoPA) berubah sejak policy dibuat. Disarankan generate ulang / re-review.'
                : 'Policy masih sinkron dengan data sumber.',
        ]]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $policy = GeneratedPolicy::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $policy) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $policy->delete();
        AuditLog::log(self::FEATURE, $policy->id, 'delete');

        return response()->json(['message' => 'Deleted']);
    }

    // =========================================================================
    // Gating + persistence helpers — mirror AiFeatureController's AI-feature
    // contract so the frontend response shape is identical. Kept local to keep
    // Policy Generator self-contained (no edits to the shared AiFeatureController).
    // =========================================================================

    private function checkAiLicense(Request $request): ?License
    {
        $user = $request->user();
        $license = License::where('org_id', $user->org_id)
            ->where('status', 'active')
            ->first();

        if (! $license || $license->package_type === 'basic') {
            return null;
        }

        return $license;
    }

    private function denyBasic(): JsonResponse
    {
        return response()->json([
            'message' => 'Fitur AI hanya tersedia untuk paket Pro AI dan Enterprise.',
            'upgrade_required' => true,
        ], 403);
    }

    private function checkCredit(Request $request, string $actionType): ?JsonResponse
    {
        $orgId = $request->user()->org_id;
        if (! $orgId) {
            return null; // superadmin bypass
        }

        CreditService::resetIfNeeded($orgId);

        if (! CreditService::hasCredit($orgId, $actionType)) {
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
     * Persist AiResult + deduct credit on success, returning the standard
     * AI-feature response shape (plus any $extra fields).
     */
    private function saveAndRespond(
        Request $request,
        string $featureType,
        array $response,
        array $inputData,
        string $recordId,
        string $recordType,
        array $extra = []
    ): JsonResponse {
        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;

        $saved = AiResult::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'feature_type' => $featureType,
            'record_id' => $recordId,
            'record_type' => $recordType,
            'input_data' => $inputData,
            'result_data' => $response,
        ]);

        $creditLog = $orgId
            ? CreditService::deduct($orgId, $userId, $featureType, self::MODULE, $recordId)
            : null;

        $org = $orgId ? Organization::find($orgId) : null;

        return response()->json(array_merge([
            'data' => $response,
            'type' => $featureType,
            'ai_result_id' => $saved->id,
            'saved' => true,
            'credits_used' => $creditLog?->credits_used ?? 0,
            'credits_remaining' => $org ? ($org->ai_credits_remaining + $org->ai_credits_purchased) : null,
        ], $extra));
    }
}
