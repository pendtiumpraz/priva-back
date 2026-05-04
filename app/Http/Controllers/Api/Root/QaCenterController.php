<?php

namespace App\Http\Controllers\Api\Root;

use App\Http\Controllers\Controller;
use App\Models\QaBugReport;
use App\Models\QaBugScreenshot;
use App\Models\QaTestCase;
use App\Models\QaTestResult;
use App\Models\QaTestRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * QA Center — root-only endpoints buat QA test catalog + runs + bugs.
 *
 * Akses gate: middleware checks role=root (lihat routes/api.php).
 *
 * Hierarchy:
 *   QaTestRun (cycle per release)
 *     └── QaTestResult (status per case per role)
 *           └── QaBugReport (kalau status=fail)
 *                 └── QaBugScreenshot (image attachments)
 *
 * Catalog (QaTestCase) seeded dari kode existing — bukan tenant-scoped,
 * jadi 1 catalog dipakai semua run.
 */
class QaCenterController extends Controller
{
    // Role gate enforced via 'role.root_only' middleware on the route group
    // (lihat routes/api.php). Laravel 12 base Controller gak expose method
    // middleware() di constructor, jadi gating dilakukan route-level.

    // =========================================================================
    // Test Cases (catalog)
    // =========================================================================

    public function listCases(Request $request): JsonResponse
    {
        $q = QaTestCase::query();
        if ($module = $request->query('module')) {
            $q->where('module', $module);
        }
        if ($feature = $request->query('feature')) {
            $q->where('feature', $feature);
        }
        if ($request->has('active_only') && $request->boolean('active_only')) {
            $q->where('is_active', true);
        }
        if ($search = $request->query('q')) {
            $q->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('interaction', 'like', "%{$search}%");
            });
        }

        $cases = $q->orderBy('module')->orderBy('feature')->orderBy('sort_order')->get();

        return response()->json(['data' => $cases]);
    }

    public function showCase(string $id): JsonResponse
    {
        $case = QaTestCase::findOrFail($id);

        return response()->json(['data' => $case]);
    }

    public function createCase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => 'required|string|max:64',
            'feature' => 'required|string|max:128',
            'interaction' => 'required|string|max:128',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'expected_behavior' => 'nullable|string',
            'applicable_roles' => 'nullable|array',
            'license_packages' => 'nullable|array',
            'sort_order' => 'nullable|integer',
        ]);
        $data['is_built_in'] = false;
        $data['is_active'] = true;
        $data['created_by'] = $request->user()->id;

        $case = QaTestCase::create($data);

        return response()->json(['data' => $case], 201);
    }

    public function updateCase(Request $request, string $id): JsonResponse
    {
        $case = QaTestCase::findOrFail($id);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'expected_behavior' => 'nullable|string',
            'applicable_roles' => 'nullable|array',
            'license_packages' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer',
        ]);
        $case->update($data);

        return response()->json(['data' => $case]);
    }

    public function deleteCase(string $id): JsonResponse
    {
        $case = QaTestCase::findOrFail($id);
        if ($case->is_built_in) {
            return response()->json(['message' => 'Built-in test case tidak bisa di-hapus, set is_active=false saja.'], 422);
        }
        $case->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function modulesSummary(): JsonResponse
    {
        $rows = QaTestCase::where('is_active', true)
            ->select('module', DB::raw('count(*) as case_count'))
            ->groupBy('module')
            ->orderBy('module')
            ->get();

        return response()->json(['data' => $rows]);
    }

    // =========================================================================
    // Test Runs (cycles)
    // =========================================================================

    public function listRuns(Request $request): JsonResponse
    {
        $q = QaTestRun::query();
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($request->boolean('trash')) {
            $q->onlyTrashed();
        }
        $runs = $q->orderByDesc('started_at')->orderByDesc('created_at')->get();

        return response()->json(['data' => $runs]);
    }

    public function showRun(string $id): JsonResponse
    {
        $run = QaTestRun::withTrashed()->findOrFail($id);

        $stats = QaTestResult::where('test_run_id', $id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $bugCount = QaBugReport::whereIn('test_result_id',
            QaTestResult::where('test_run_id', $id)->pluck('id')
        )->count();

        return response()->json([
            'data' => $run,
            'stats' => $stats,
            'bug_count' => $bugCount,
        ]);
    }

    public function createRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'version' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
            'auto_seed_results' => 'nullable|boolean',
        ]);

        $autoSeed = (bool) ($data['auto_seed_results'] ?? true);
        unset($data['auto_seed_results']);

        $data['status'] = 'active';
        $data['started_at'] = now();
        $data['created_by'] = $request->user()->id;

        $run = QaTestRun::create($data);

        if ($autoSeed) {
            $this->seedResultsForRun($run);
        }

        return response()->json(['data' => $run], 201);
    }

    public function updateRun(Request $request, string $id): JsonResponse
    {
        $run = QaTestRun::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'version' => 'nullable|string|max:64',
            'status' => 'sometimes|in:active,closed,archived',
            'notes' => 'nullable|string',
        ]);
        if (($data['status'] ?? null) === 'closed' && ! $run->closed_at) {
            $data['closed_at'] = now();
        }
        $run->update($data);

        return response()->json(['data' => $run]);
    }

    public function deleteRun(string $id): JsonResponse
    {
        $run = QaTestRun::findOrFail($id);
        $run->delete();

        return response()->json(['message' => 'Moved to trash']);
    }

    public function restoreRun(string $id): JsonResponse
    {
        $run = QaTestRun::onlyTrashed()->findOrFail($id);
        $run->restore();

        return response()->json(['message' => 'Restored', 'data' => $run]);
    }

    public function forceDeleteRun(string $id): JsonResponse
    {
        $run = QaTestRun::onlyTrashed()->findOrFail($id);
        $run->forceDelete();

        return response()->json(['message' => 'Permanently deleted']);
    }

    /**
     * Seed test_results untuk run baru — 1 row per (active test_case × role).
     */
    private function seedResultsForRun(QaTestRun $run): void
    {
        $cases = QaTestCase::where('is_active', true)->get();
        $rows = [];
        foreach ($cases as $case) {
            $roles = is_array($case->applicable_roles) && count($case->applicable_roles)
                ? $case->applicable_roles
                : ['any'];
            foreach ($roles as $role) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'test_run_id' => $run->id,
                    'test_case_id' => $case->id,
                    'role' => substr((string) $role, 0, 64),
                    'status' => 'not_tested',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('qa_test_results')->insert($chunk);
        }
    }

    // =========================================================================
    // Test Results (per run)
    // =========================================================================

    public function listResults(Request $request, string $runId): JsonResponse
    {
        $q = QaTestResult::where('test_run_id', $runId)
            ->with(['testCase:id,module,feature,interaction,title,description,expected_behavior']);
        if ($module = $request->query('module')) {
            $q->whereHas('testCase', fn ($w) => $w->where('module', $module));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($role = $request->query('role')) {
            $q->where('role', $role);
        }
        $results = $q->orderBy('test_case_id')->orderBy('role')->paginate((int) ($request->query('per_page') ?? 100));

        return response()->json($results);
    }

    public function updateResult(Request $request, string $resultId): JsonResponse
    {
        $result = QaTestResult::findOrFail($resultId);
        $data = $request->validate([
            'status' => 'sometimes|in:not_tested,pass,fail,blocked,skip,na',
            'tester_name' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);
        if (isset($data['status']) && in_array($data['status'], ['pass', 'fail', 'blocked', 'skip'], true)) {
            $data['tested_at'] = now();
        }
        $result->update($data);

        return response()->json(['data' => $result->fresh('bugs', 'testCase')]);
    }

    // =========================================================================
    // Bug Reports
    // =========================================================================

    public function listBugs(Request $request): JsonResponse
    {
        $q = QaBugReport::with([
            'screenshots',
            'testResult.testCase:id,module,feature,interaction,title',
            'testResult.testRun:id,name,version',
        ]);
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($severity = $request->query('severity')) {
            $q->where('severity', $severity);
        }
        if ($runId = $request->query('test_run_id')) {
            $q->whereHas('testResult', fn ($w) => $w->where('test_run_id', $runId));
        }
        if ($module = $request->query('module')) {
            $q->whereHas('testResult.testCase', fn ($w) => $w->where('module', $module));
        }
        if ($search = $request->query('q')) {
            $q->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        $bugs = $q->orderByDesc('reported_at')->orderByDesc('created_at')->paginate((int) ($request->query('per_page') ?? 50));

        return response()->json($bugs);
    }

    public function showBug(string $id): JsonResponse
    {
        $bug = QaBugReport::with([
            'screenshots',
            'testResult.testCase',
            'testResult.testRun',
        ])->findOrFail($id);

        return response()->json(['data' => $bug]);
    }

    public function createBug(Request $request): JsonResponse
    {
        $data = $request->validate([
            'test_result_id' => 'required|uuid|exists:qa_test_results,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'severity' => 'sometimes|in:low,medium,high,critical',
            'reporter_name' => 'nullable|string|max:255',
        ]);
        $data['reported_at'] = now();
        $data['status'] = 'open';

        $bug = QaBugReport::create($data);

        // Auto-set linked result to fail kalau belum.
        $result = QaTestResult::find($bug->test_result_id);
        if ($result && $result->status !== 'fail') {
            $result->update(['status' => 'fail', 'tested_at' => $result->tested_at ?? now()]);
        }

        return response()->json(['data' => $bug->load('screenshots')], 201);
    }

    public function updateBug(Request $request, string $id): JsonResponse
    {
        $bug = QaBugReport::findOrFail($id);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'severity' => 'sometimes|in:low,medium,high,critical',
            'status' => 'sometimes|in:open,in_progress,fixed,wontfix,verified',
            'assigned_to_name' => 'nullable|string|max:255',
            'resolver_name' => 'nullable|string|max:255',
            'verified_by_name' => 'nullable|string|max:255',
            'resolution_notes' => 'nullable|string',
        ]);

        // Auto-stamp dates pada transisi state.
        if (isset($data['status'])) {
            if (in_array($data['status'], ['fixed', 'wontfix'], true) && ! $bug->resolved_at) {
                $data['resolved_at'] = now();
            }
            if ($data['status'] === 'verified' && ! $bug->verified_at) {
                $data['verified_at'] = now();
            }
        }

        $bug->update($data);

        return response()->json(['data' => $bug->fresh('screenshots')]);
    }

    public function deleteBug(string $id): JsonResponse
    {
        $bug = QaBugReport::findOrFail($id);
        $bug->delete();

        return response()->json(['message' => 'Deleted']);
    }

    // =========================================================================
    // Bug Screenshots (image upload)
    // =========================================================================

    public function uploadScreenshot(Request $request, string $bugId): JsonResponse
    {
        $bug = QaBugReport::findOrFail($bugId);

        $request->validate([
            'image' => 'required|image|mimes:jpg,jpeg,png,gif,webp|max:8192',
            'uploaded_by_name' => 'nullable|string|max:255',
        ]);

        $file = $request->file('image');
        $folder = "qa-screenshots/{$bug->id}";
        $stored = $file->store($folder, 'local');

        $shot = QaBugScreenshot::create([
            'bug_report_id' => $bug->id,
            'file_path' => $stored,
            'file_name' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by_name' => $request->input('uploaded_by_name'),
            'uploaded_at' => now(),
        ]);

        return response()->json(['data' => $shot], 201);
    }

    public function downloadScreenshot(string $id)
    {
        $shot = QaBugScreenshot::findOrFail($id);
        if (! Storage::disk('local')->exists($shot->file_path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download(
            Storage::disk('local')->path($shot->file_path),
            $shot->file_name ?? 'screenshot'
        );
    }

    public function deleteScreenshot(string $id): JsonResponse
    {
        $shot = QaBugScreenshot::findOrFail($id);
        try {
            Storage::disk('local')->delete($shot->file_path);
        } catch (\Throwable $e) {
            \Log::warning('QA screenshot delete file failed: '.$e->getMessage());
        }
        $shot->delete();

        return response()->json(['message' => 'Deleted']);
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboard(Request $request): JsonResponse
    {
        $runId = $request->query('test_run_id');
        $resultQuery = $runId ? QaTestResult::where('test_run_id', $runId) : QaTestResult::query();

        $statusCounts = (clone $resultQuery)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $byModule = (clone $resultQuery)
            ->join('qa_test_cases', 'qa_test_cases.id', '=', 'qa_test_results.test_case_id')
            ->selectRaw('qa_test_cases.module, qa_test_results.status, count(*) as cnt')
            ->groupBy('qa_test_cases.module', 'qa_test_results.status')
            ->get();

        $bugQuery = QaBugReport::query();
        if ($runId) {
            $bugQuery->whereHas('testResult', fn ($w) => $w->where('test_run_id', $runId));
        }
        $bugSeverity = (clone $bugQuery)
            ->selectRaw('severity, count(*) as cnt')
            ->groupBy('severity')
            ->pluck('cnt', 'severity');
        $bugStatus = (clone $bugQuery)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $totalCases = QaTestCase::where('is_active', true)->count();

        return response()->json([
            'total_active_cases' => $totalCases,
            'result_status_counts' => $statusCounts,
            'by_module' => $byModule,
            'bug_severity' => $bugSeverity,
            'bug_status' => $bugStatus,
        ]);
    }
}
