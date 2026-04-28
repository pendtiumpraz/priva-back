<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PushExtractToCrmJob;
use App\Models\ConsentLog;
use App\Models\ExtractRun;
use App\Services\TenantContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRM Extractor — pull identifiable consent_logs filtered by purpose,
 * date, source_form, country. Output: CSV download or async push to
 * HubSpot/Salesforce/Mailchimp/webhook (latter three are stubs in this
 * phase; CSV is the only fully-implemented target).
 */
class ConsentExtractController extends Controller
{
    public function __construct(private TenantContextService $tenant) {}

    /**
     * Preview count + sample without committing — used by the wizard
     * "matching records: 1,234" indicator.
     */
    public function preview(Request $request)
    {
        $orgId = $this->tenant->currentOrgId();
        if (! $orgId) {
            return response()->json(['error' => 'No org context'], 403);
        }

        $filters = $this->validateFilters($request);
        $q = $this->buildQuery($orgId, $filters);

        $count = (clone $q)->count();
        $sample = (clone $q)->orderByDesc('created_at')->limit(5)->get([
            'id', 'email', 'name', 'phone', 'source_form', 'purpose_keys', 'created_at',
        ]);

        return response()->json([
            'data' => [
                'count' => $count,
                'sample' => $sample,
                'filters_applied' => $filters,
            ],
        ]);
    }

    /**
     * Execute extract. Returns CSV stream OR creates an ExtractRun record
     * and returns its id for async CRM push.
     */
    public function run(Request $request)
    {
        $orgId = $this->tenant->currentOrgId();
        if (! $orgId) {
            return response()->json(['error' => 'No org context'], 403);
        }

        $filters = $this->validateFilters($request);
        $target = $request->input('output_target', 'csv');
        if (! in_array($target, ExtractRun::TARGETS, true)) {
            return response()->json(['error' => 'Invalid output_target'], 422);
        }

        $q = $this->buildQuery($orgId, $filters);
        $count = (clone $q)->count();

        $run = ExtractRun::create([
            'org_id' => $orgId,
            'initiated_by_user_id' => Auth::id(),
            'source' => 'consent_logs',
            'filters' => $filters,
            'output_target' => $target,
            'output_target_ref' => $request->input('output_target_ref'),
            'record_count' => $count,
            'status' => $target === 'csv' ? ExtractRun::STATUS_DONE : ExtractRun::STATUS_PENDING,
            'started_at' => now(),
            'finished_at' => $target === 'csv' ? now() : null,
        ]);

        if ($target === 'csv') {
            return $this->streamCsv($q, $run);
        }

        // Async target — dispatch CRM push job. The job re-loads filters from
        // the run row, resolves credentials per org+provider, calls the
        // connector, and writes back success/failure counts + status.
        PushExtractToCrmJob::dispatch($run->id)->afterCommit();

        return response()->json([
            'data' => [
                'run_id' => $run->id,
                'status' => $run->status,
                'count' => $count,
                'note' => 'Push job dispatched. Poll /api/consent-extract/runs for status.',
            ],
        ], 202);
    }

    /**
     * List past extract runs (audit trail).
     */
    public function index(Request $request)
    {
        $orgId = $this->tenant->currentOrgId();
        $perPage = min(50, max(10, (int) $request->input('per_page', 20)));

        $page = ExtractRun::query()
            ->where('org_id', $orgId)
            ->with('initiator:id,name,email')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($page);
    }

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'collection_id' => 'nullable|string|max:200',
            'purpose_keys' => 'nullable|array',
            'purpose_keys.*' => 'string|max:60',
            'source_form' => 'nullable|string|max:40',
            'country' => 'nullable|string|size:2',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);
    }

    private function buildQuery(string $orgId, array $filters)
    {
        $q = ConsentLog::query()->where('org_id', $orgId);

        if (! empty($filters['collection_id'])) {
            $q->where('collection_id', $filters['collection_id']);
        }
        if (! empty($filters['source_form'])) {
            $q->where('source_form', $filters['source_form']);
        }
        if (! empty($filters['country'])) {
            $q->where('ip_country', strtoupper($filters['country']));
        }
        if (! empty($filters['date_from'])) {
            $q->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('created_at', '<=', $filters['date_to']);
        }
        if (! empty($filters['purpose_keys'])) {
            // Filter rows where ALL requested purpose keys are present in
            // the denormalized purpose_keys array.
            foreach ($filters['purpose_keys'] as $key) {
                // DB-portable: LIKE on JSON-encoded array
                $q->where('purpose_keys', 'like', '%"'.addslashes($key).'"%');
            }
        }
        // Only return rows with email (identifiable)
        $q->whereNotNull('email');

        return $q;
    }

    private function streamCsv($query, ExtractRun $run): StreamedResponse
    {
        $filename = sprintf('consent-extract-%s-%s.csv', $run->id, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'email', 'name', 'phone', 'source_form', 'purposes', 'country', 'captured_at']);
            $query->orderBy('created_at')->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    $purposes = is_array($r->purpose_keys) ? implode('|', $r->purpose_keys) : '';
                    fputcsv($out, [
                        $r->id,
                        $r->email,
                        $r->name,
                        $r->phone,
                        $r->source_form,
                        $purposes,
                        $r->ip_country,
                        $r->created_at?->toIso8601String(),
                    ]);
                }
            });
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
