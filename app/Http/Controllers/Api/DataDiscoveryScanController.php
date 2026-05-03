<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataDiscoveryScan\GenerateScanRequest;
use App\Models\AuditLog;
use App\Models\DataDiscoveryScanPlan;
use App\Models\DataDiscoveryScanPlanSystem;
use App\Models\DataDiscoveryScanResult;
use App\Models\DsrRequest;
use App\Models\DsrRequestScope;
use App\Services\DataDiscoveryAppExecutor;
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
            targetSystemIds: $req->input('target_system_ids') ?: null,
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
    // GET /plans (?trash=1 untuk lihat soft-deleted)
    // =========================================================================
    public function index(Request $req): JsonResponse
    {
        $user = $req->user();
        $trash = $req->boolean('trash');

        $query = DataDiscoveryScanPlan::forOrg($user->org_id);
        if ($trash) {
            $query->onlyTrashed();
        }

        $plans = $query->orderByDesc('created_at')->paginate((int) ($req->per_page ?? 20));

        // Map paginator data → serializePlan untuk konsistensi shape.
        $plans->getCollection()->transform(fn ($p) => $this->serializePlan($p));

        return response()->json($plans);
    }

    // =========================================================================
    // DELETE /plans/{id} — soft delete
    // =========================================================================
    public function destroy(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        $plan = DataDiscoveryScanPlan::forOrg($user->org_id)->find($id);
        if (! $plan) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $plan->delete();

        $this->writeAudit('data_discovery.scan_plan.delete', $plan->id, $user, [
            'soft' => true,
        ]);

        return response()->json(['message' => 'Plan archived', 'id' => $plan->id]);
    }

    // =========================================================================
    // POST /plans/{id}/restore
    // =========================================================================
    public function restore(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        $plan = DataDiscoveryScanPlan::forOrg($user->org_id)
            ->onlyTrashed()
            ->find($id);
        if (! $plan) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $plan->restore();

        $this->writeAudit('data_discovery.scan_plan.restore', $plan->id, $user, []);

        return response()->json([
            'message' => 'Plan restored',
            'plan' => $this->serializePlan($plan),
        ]);
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
        // Expose generated SQL queries (sql + ai_explanation) supaya frontend
        // bisa render debug view — important untuk diagnose 0-hit cases.
        $tableQueries = is_array($ps->table_queries) ? $ps->table_queries : [];

        return [
            'id' => $ps->id,
            'information_system_id' => $ps->information_system_id,
            'app_name' => $ps->app_name,
            'table_count' => count($tableQueries),
            'table_queries' => array_map(fn ($q) => [
                'table' => $q['table'] ?? null,
                'sql' => $q['sql'] ?? null,
                'confidence' => $q['confidence'] ?? null,
                'ai_explanation' => $q['ai_explanation'] ?? null,
            ], $tableQueries),
            'status' => $ps->status,
            'hit_count' => (int) $ps->hit_count,
            'error' => $ps->error,
            'child_ai_job_id' => $ps->child_ai_job_id,
        ];
    }

    // =========================================================================
    // POST /plans/{id}/execute
    //
    // Synchronous execution — loop plan_systems → DataDiscoveryAppExecutor
    // (read-only via DatabaseScanner). No background queue. PHP timeout
    // bumped to 600s untuk handle scan banyak app. Frontend tinggal redirect
    // ke results page setelah response done.
    // =========================================================================
    public function execute(Request $req, string $id, DataDiscoveryAppExecutor $executor): JsonResponse
    {
        $user = $req->user();
        $plan = DataDiscoveryScanPlan::forOrg($user->org_id)
            ->with(['planSystems' => fn ($q) => $q->orderBy('app_name')])
            ->find($id);
        if (! $plan) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if ($plan->total_systems === 0) {
            return response()->json([
                'error' => 'Plan has no systems to execute. Re-generate after registering at least one InformationSystem with a scan.',
            ], 422);
        }

        // Bump PHP timeout — scan banyak app bisa makan beberapa menit.
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');

        $plan->update([
            'status' => DataDiscoveryScanPlan::STATUS_EXECUTING,
            'progress' => 0,
        ]);

        $this->writeAudit('data_discovery.scan.execute', $plan->id, $user, [
            'mode' => 'sync',
            'systems' => $plan->planSystems->count(),
        ]);

        $totalSystems = $plan->planSystems->count();
        $totalHits = 0;
        $skippedSystems = 0;
        $failedSystems = 0;

        foreach ($plan->planSystems as $i => $planSys) {
            // Skip ones already done (idempotent re-run).
            if ($planSys->status === DataDiscoveryScanPlanSystem::STATUS_DONE) {
                $totalHits += (int) $planSys->hit_count;

                continue;
            }

            $res = $executor->execute($planSys->id);
            $totalHits += (int) ($res['hits'] ?? 0);
            $resStatus = $res['status'] ?? null;
            if ($resStatus === 'skipped') {
                $skippedSystems++;
            } elseif ($resStatus === 'failed') {
                $failedSystems++;
            }

            // Update plan progress incrementally — even though sync, this lets
            // the UI show partial state if user reloads mid-execution.
            $plan->update([
                'progress' => (int) (($i + 1) / max(1, $totalSystems) * 100),
                'total_hits' => $totalHits,
            ]);
        }

        // Plan = failed hanya kalau SEMUA system failed (real SQL bugs everywhere).
        // Sebagian skipped + sebagian sukses tetap completed — skipped itu infra
        // issue (host blocked, source unsupported), bukan bug Privasimu.
        $plan->refresh()->update([
            'status' => $failedSystems === $totalSystems
                ? DataDiscoveryScanPlan::STATUS_FAILED
                : DataDiscoveryScanPlan::STATUS_COMPLETED,
            'progress' => 100,
            'total_hits' => $totalHits,
        ]);

        return response()->json([
            'plan_id' => $plan->id,
            'status' => $plan->fresh()->status,
            'total_hits' => $totalHits,
            'skipped_systems' => $skippedSystems,
            'failed_systems' => $failedSystems,
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
