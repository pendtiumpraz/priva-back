<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataDiscoveryScan\GenerateScanRequest;
use App\Jobs\ProcessAiJob;
use App\Models\AiJob;
use App\Models\AuditLog;
use App\Models\DataDiscoveryScanPlan;
use App\Models\DataDiscoveryScanPlanSystem;
use App\Models\DataDiscoveryScanResult;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use App\Models\Organization;
use App\Services\DataDiscoveryScanGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Person Scan endpoints.
 *
 * Endpoints:
 *   POST   /api/data-discovery/scan/generate          — store
 *   GET    /api/data-discovery/scan/plans             — index
 *   GET    /api/data-discovery/scan/plans/{id}        — show
 *   POST   /api/data-discovery/scan/plans/{id}/execute — execute (always
 *           dispatches against registered InformationSystem via DatabaseScanner)
 *   GET    /api/data-discovery/scan/plans/{id}/results — results
 *   POST   /api/data-discovery/scan-results/{id}/reveal — reveal (OnPrem only —
 *           OnPrem stores encrypted_row, SaaS stores masked_row only)
 *   POST   /api/data-discovery/scan/plans/{id}/to-dsr  — toDsr
 *
 * Multi-tenant: every read uses ::forOrg($user->org_id) — the plan model does
 * NOT use the BelongsToOrg trait so explicit scoping is mandatory.
 */
class DataDiscoveryScanController extends Controller
{
    public function __construct(
        private DataDiscoveryScanGeneratorService $generator,
    ) {}

    // =========================================================================
    // POST /generate
    // =========================================================================
    public function store(GenerateScanRequest $req): JsonResponse
    {
        $user = $req->user();
        if (! $user?->org_id) {
            return response()->json(['error' => 'User has no organization context'], 422);
        }

        $plan = $this->generator->generate(
            orgId: $user->org_id,
            userId: $user->id,
            identifiers: [
                'email' => $req->input('email'),
                'name' => $req->input('name'),
                'nik' => $req->input('nik'),
                'phone' => $req->input('phone'),
                'dob' => $req->input('dob'),
            ],
        );

        return response()->json([
            'plan' => $this->serializePlan($plan),
        ], 201);
    }

    /**
     * Serialize plan model to the shape the frontend ScanPlan type expects.
     * Excludes identifier_hashes (server-only fingerprints).
     */
    private function serializePlan(DataDiscoveryScanPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'label' => $plan->label,
            'status' => $plan->status,
            'total_systems' => (int) $plan->total_systems,
            'total_tables' => (int) $plan->total_tables,
            'skipped_tables' => (int) $plan->skipped_tables,
            'total_hits' => (int) $plan->total_hits,
            'progress' => (int) ($plan->progress ?? 0),
            'parent_ai_job_id' => $plan->parent_ai_job_id,
            'identifiers_masked' => $plan->identifiers,
            'expires_at' => $plan->expires_at,
            'created_at' => $plan->created_at,
            // Mode flag for frontend — controls Reveal availability (OnPrem
            // stores encrypted_row, SaaS stores masked_row only).
            'deployment_mode' => config('ai.deployment_mode', 'saas'),
        ];
    }

    // =========================================================================
    // GET /plans
    // =========================================================================
    public function index(Request $req): JsonResponse
    {
        $user = $req->user();
        $plans = DataDiscoveryScanPlan::forOrg($user->org_id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($plans);
    }

    // =========================================================================
    // GET /plans/{id}
    // =========================================================================
    public function show(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        $plan = DataDiscoveryScanPlan::forOrg($user->org_id)
            ->with(['planSystems' => fn ($q) => $q->orderBy('app_name')])
            ->find($id);
        if (! $plan) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json([
            'plan' => $this->serializePlan($plan),
            'systems' => $plan->planSystems->map(fn ($ps) => $this->serializeSystem($ps))->values(),
        ]);
    }

    private function serializeSystem(DataDiscoveryScanPlanSystem $ps): array
    {
        return [
            'id' => $ps->id,
            'information_system_id' => $ps->information_system_id,
            'app_name' => $ps->app_name,
            'table_count' => is_array($ps->table_queries) ? count($ps->table_queries) : 0,
            'status' => $ps->status,
            'hit_count' => (int) $ps->hit_count,
            'error' => $ps->error,
            'child_ai_job_id' => $ps->child_ai_job_id,
        ];
    }

    // =========================================================================
    // POST /plans/{id}/execute
    //
    // Always dispatch background execution against registered InformationSystem
    // connections via DatabaseScanner (read-only, mirip pattern existing
    // specificSearchExecute). Mode hanya pengaruhi storage encrypted_row di
    // worker — Reveal flow only available di OnPrem (lihat AppExecutor).
    // =========================================================================
    public function execute(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        $plan = DataDiscoveryScanPlan::forOrg($user->org_id)->find($id);
        if (! $plan) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if ($plan->total_systems === 0) {
            return response()->json([
                'error' => 'Plan has no systems to execute. Re-generate after registering at least one InformationSystem with a scan.',
            ], 422);
        }
        if (! config('ai.jobs_enabled', true)) {
            return response()->json([
                'error' => 'AI background jobs disabled by admin — cannot dispatch scan.',
            ], 503);
        }

        // Spawn parent AiJob; ProcessAiJob fan-out child per plan_system via
        // DataDiscoveryExecuteOrchestrator. credits_used stays 0 (this isn't
        // AI inference — AI was the generator step).
        $parent = AiJob::create([
            'org_id' => $plan->org_id,
            'user_id' => $user->id,
            'type' => 'person_scan_execute',
            'module' => 'data_discovery',
            'subject_id' => $plan->id,
            'label' => $plan->label,
            'status' => AiJob::STATUS_PENDING,
            'progress' => 0,
            'payload' => ['plan_id' => $plan->id],
        ]);

        $org = Organization::find($plan->org_id);
        ProcessAiJob::dispatch($parent->id)->onQueue($this->queueFor($org));

        $plan->update([
            'parent_ai_job_id' => $parent->id,
            'status' => DataDiscoveryScanPlan::STATUS_EXECUTING,
        ]);

        $this->writeAudit('data_discovery.scan.execute', $plan->id, $user, [
            'parent_ai_job_id' => $parent->id,
        ]);

        return response()->json([
            'plan_id' => $plan->id,
            'parent_job_id' => $parent->id,
            'status' => $plan->status,
        ]);
    }

    // =========================================================================
    // GET /plans/{id}/results
    // =========================================================================
    public function results(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        $plan = DataDiscoveryScanPlan::forOrg($user->org_id)->find($id);
        if (! $plan) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $plan->load(['planSystems' => fn ($q) => $q->orderBy('app_name')]);

        $results = DataDiscoveryScanResult::forOrg($user->org_id)
            ->where('scan_plan_id', $plan->id)
            ->orderBy('plan_system_id')
            ->orderBy('table_name')
            ->limit(2000)
            ->get(['id', 'scan_plan_id', 'plan_system_id', 'information_system_id',
                'table_name', 'confidence', 'matched_columns', 'match_count',
                'row_pks', 'masked_row', 'revealed', 'revealed_at', 'created_at']);

        return response()->json([
            'plan' => $this->serializePlan($plan),
            'systems' => $plan->planSystems->map(fn ($ps) => $this->serializeSystem($ps))->values(),
            'results' => $results,
        ]);
    }

    // =========================================================================
    // POST /scan-results/{id}/reveal (OnPrem only)
    // =========================================================================
    public function reveal(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        if (config('ai.deployment_mode', 'saas') !== 'onprem') {
            return response()->json([
                'error' => 'Reveal action is only available in OnPrem deployment.',
            ], 400);
        }

        $result = DataDiscoveryScanResult::forOrg($user->org_id)->find($id);
        if (! $result) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if (! $result->encrypted_row) {
            return response()->json([
                'error' => 'No encrypted row available for this hit (SaaS-uploaded results never carry ciphertext).',
            ], 400);
        }

        try {
            $raw = json_decode(Crypt::decryptString($result->encrypted_row), true);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Decrypt failed: '.$e->getMessage()], 500);
        }

        $result->update([
            'revealed' => true,
            'revealed_by' => $user->id,
            'revealed_at' => now(),
        ]);

        $this->writeAudit('data_discovery.scan_result.reveal', $result->id, $user, [
            'plan_id' => $result->scan_plan_id,
            'table' => $result->table_name,
        ]);

        return response()->json([
            'id' => $result->id,
            'row' => $raw,
            'revealed_at' => $result->revealed_at,
        ]);
    }

    // =========================================================================
    // POST /plans/{id}/to-dsr
    // =========================================================================
    public function toDsr(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        $plan = DataDiscoveryScanPlan::forOrg($user->org_id)
            ->with('planSystems')
            ->find($id);
        if (! $plan) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $req->validate([
            'requester_email' => ['required', 'email', 'max:191'],
            'requester_name' => ['required', 'string', 'max:191'],
            'request_type' => ['nullable', 'string', 'in:deletion,erasure,access,correction,rectification,portability,withdraw_consent,objection,restriction,info'],
            'app_id' => ['nullable', 'string'],
        ]);

        $type = $req->input('request_type', 'deletion');

        $year = date('Y');
        $count = DsrRequest::where('org_id', $plan->org_id)
            ->whereYear('created_at', $year)
            ->count() + 1;
        $requestId = sprintf('DSR-%s-%03d', $year, $count);

        $dsr = DsrRequest::create([
            'org_id' => $plan->org_id,
            'app_id' => $req->input('app_id'),
            'request_id' => $requestId,
            'request_type' => $type,
            'requester_name' => $req->input('requester_name'),
            'requester_email' => $req->input('requester_email'),
            'subject_data' => [
                'source' => 'person_scan',
                'scan_plan_id' => $plan->id,
                'identifier_hashes' => $plan->identifier_hashes,
            ],
            'status' => 'pending_review',
            'verification_status' => 'verified', // DPO already verified via Person Scan
            'verified_at' => now(),
            'verification_method' => 'dpo_person_scan',
            'deadline_at' => now()->addHours(72),
            'created_by' => $user->id,
            'assigned_to' => $user->id,
        ]);

        // Pre-populate scopes from the plan's plan_systems (one per IS that
        // had a hit). DPO can refine in the Scope Picker afterwards.
        $hitSystemIds = DataDiscoveryScanResult::forOrg($plan->org_id)
            ->where('scan_plan_id', $plan->id)
            ->distinct()
            ->pluck('information_system_id');
        foreach ($hitSystemIds as $isId) {
            DsrRequestScope::firstOrCreate(
                [
                    'dsr_request_id' => $dsr->id,
                    'information_system_id' => $isId,
                ],
                [
                    'request_types' => [$type],
                    'sql_pack_status' => 'pending',
                ],
            );
        }

        $this->writeAudit('data_discovery.scan.to_dsr', $plan->id, $user, [
            'dsr_request_id' => $dsr->id,
            'dsr_code' => $dsr->request_id,
            'scoped_systems' => $hitSystemIds->count(),
            'request_type' => $type,
        ]);

        return response()->json([
            'plan_id' => $plan->id,
            'dsr_request_id' => $dsr->id,
            'dsr_code' => $dsr->request_id,
            'scoped_systems' => $hitSystemIds->count(),
        ], 201);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function queueFor(?Organization $org): string
    {
        $tier = $org?->tier ?? 'standard';

        return match ($tier) {
            'enterprise' => 'ai-jobs-priority',
            'pro' => 'ai-jobs',
            default => 'ai-jobs-low',
        };
    }

    private function writeAudit(string $action, string $recordId, $user, array $changes): void
    {
        try {
            AuditLog::create([
                'module' => 'data_discovery',
                'record_id' => $recordId,
                'action' => $action,
                'user_id' => $user?->id,
                'user_name' => $user?->name ?? 'System',
                'user_role' => $user?->role ?? 'system',
                'section' => 'person_scan',
                'changes' => $changes,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('AuditLog write failed', [
                'action' => $action,
                'record_id' => $recordId,
                'err' => $e->getMessage(),
            ]);
        }
    }
}
