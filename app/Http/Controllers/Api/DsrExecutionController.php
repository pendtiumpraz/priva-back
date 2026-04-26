<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DsrExecution;
use App\Models\DsrRequest;
use App\Models\Organization;
use App\Services\DsrCertificateService;
use App\Services\DsrEventBroadcaster;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DSR Execution log — admin klien upload bukti per shard, mark status.
 *
 * Routes (mounted in api.php):
 *   GET  /api/dsr/{id}/executions
 *   PATCH /api/dsr/{id}/executions/{exec_id}     — update status/rows/notes
 *   POST /api/dsr/{id}/executions/{exec_id}/evidence — upload bukti file
 *   GET  /api/dsr/{id}/executions/{exec_id}/evidence — stream file
 *   POST /api/dsr/{id}/certificates/regenerate    — re-run cert generation
 *   GET  /api/dsr/{id}/certificates/{kind}/download — download cert
 */
class DsrExecutionController extends Controller
{
    public function __construct(
        private TenantStorageService $storage,
        private DsrCertificateService $certService,
        private DsrEventBroadcaster $events,
    ) {}

    public function index(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        $executions = $dsr->executions()->with('informationSystem')->orderBy('created_at')->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'information_system_id' => $e->information_system_id,
                'system_name' => optional($e->informationSystem)->name,
                'shard_name' => $e->shard_name,
                'request_type' => $e->request_type,
                'status' => $e->status,
                'rows_affected' => $e->rows_affected,
                'executed_at' => $e->executed_at,
                'executed_by_email' => $e->executed_by_email,
                'has_evidence' => (bool) $e->evidence_file_id,
                'evidence_file_id' => $e->evidence_file_id,
                'notes' => $e->notes,
                'failure_reason' => $e->failure_reason,
            ]);

        return response()->json([
            'data' => $executions,
            'summary' => [
                'total' => $dsr->executions()->count(),
                'pending' => $dsr->executions()->where('status', 'pending')->count(),
                'executed' => $dsr->executions()->where('status', 'executed')->count(),
                'skipped' => $dsr->executions()->where('status', 'skipped')->count(),
                'failed' => $dsr->executions()->where('status', 'failed')->count(),
                'all_complete' => $dsr->allExecutionsComplete(),
            ],
        ]);
    }

    public function update(Request $request, string $id, string $execId)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);
        $exec = DsrExecution::where('dsr_request_id', $dsr->id)->findOrFail($execId);

        $data = $request->validate([
            'status' => 'required|in:pending,executed,failed,skipped',
            'rows_affected' => 'nullable|integer|min:0',
            'executed_by_email' => 'nullable|email',
            'notes' => 'nullable|string|max:2000',
            'failure_reason' => 'nullable|string|max:2000',
        ]);

        $previous = $exec->status;
        $exec->fill($data);
        if (in_array($data['status'], ['executed', 'skipped', 'failed'], true)) {
            $exec->executed_at = $exec->executed_at ?: now();
            $exec->executed_by_email = $data['executed_by_email'] ?: ($exec->executed_by_email ?: $user->email);
        }
        $exec->save();

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.execution_status_change',
            'details' => [
                'execution_id' => $exec->id,
                'shard' => $exec->shard_name,
                'request_type' => $exec->request_type,
                'from' => $previous,
                'to' => $exec->status,
                'rows_affected' => $exec->rows_affected,
            ],
        ]);

        // Per-shard progress (low-noise) — only emit when transitioning to a final state
        if (in_array($exec->status, ['executed', 'failed', 'skipped'], true) && $previous !== $exec->status) {
            $this->events->emit(DsrEventBroadcaster::EVENT_EXECUTION_PROGRESS, $dsr->fresh(), [
                'execution_id' => $exec->id,
                'shard' => $exec->shard_name,
                'request_type' => $exec->request_type,
                'status' => $exec->status,
                'rows_affected' => $exec->rows_affected,
                'notes' => "Shard {$exec->shard_name} → {$exec->status}",
            ]);
        }

        $autoCompleted = $this->maybeCompleteDsr($dsr);

        return response()->json([
            'message' => 'Execution status updated.',
            'execution' => $exec->fresh(),
            'dsr_completed' => $autoCompleted,
        ]);
    }

    public function uploadEvidence(Request $request, string $id, string $execId)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);
        $exec = DsrExecution::where('dsr_request_id', $dsr->id)->findOrFail($execId);

        $request->validate([
            'file' => 'required|file|mimes:pdf,png,jpg,jpeg,txt,csv,log|max:10240',
        ]);

        $org = Organization::findOrFail($user->org_id);
        $stored = $this->storage->storeTenantPrivateFile(
            $org,
            $request->file('file'),
            "dsr/{$dsr->id}/evidence/{$exec->id}"
        );

        $doc = Document::create([
            'org_id' => $org->id,
            'kind' => 'dsr.execution_evidence',
            'source_type' => 'dsr_execution',
            'source_id' => $exec->id,
            'name' => $request->file('file')->getClientOriginalName(),
            'mime_type' => $request->file('file')->getClientMimeType(),
            'size_bytes' => $request->file('file')->getSize(),
            'storage_path' => $stored['path'],
            'storage_driver' => $stored['driver'],
            'uploaded_by' => $user->id,
        ]);

        $exec->update(['evidence_file_id' => $doc->id]);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.evidence_upload',
            'details' => [
                'execution_id' => $exec->id,
                'document_id' => $doc->id,
                'name' => $doc->name,
                'size' => $doc->size_bytes,
            ],
        ]);

        return response()->json([
            'message' => 'Evidence uploaded.',
            'document' => $doc,
        ], 201);
    }

    public function streamEvidence(Request $request, string $id, string $execId): StreamedResponse
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);
        $exec = DsrExecution::where('dsr_request_id', $dsr->id)->findOrFail($execId);
        if (!$exec->evidence_file_id) abort(404, 'No evidence uploaded yet.');

        $doc = Document::where('org_id', $user->org_id)->findOrFail($exec->evidence_file_id);
        $org = Organization::findOrFail($user->org_id);
        $disk = $this->storage->getDisk($org);
        if (!$disk->exists($doc->storage_path)) abort(404, 'File missing from storage.');

        return $disk->download($doc->storage_path, $doc->name);
    }

    public function regenerateCertificates(Request $request, string $id)
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        if (!$dsr->allExecutionsComplete()) {
            return response()->json([
                'error' => 'Belum semua execution complete. Tidak bisa generate certificate.',
            ], 422);
        }

        [$subjectId, $internalId] = $this->certService->generateBoth($dsr);

        return response()->json([
            'message' => 'Certificates regenerated.',
            'subject_certificate_doc_id' => $subjectId,
            'internal_certificate_doc_id' => $internalId,
        ]);
    }

    public function downloadCertificate(Request $request, string $id, string $kind): StreamedResponse
    {
        $user = $request->user();
        $dsr = DsrRequest::where('org_id', $user->org_id)->findOrFail($id);

        $docId = match ($kind) {
            'subject' => $dsr->subject_certificate_doc_id,
            'internal' => $dsr->internal_certificate_doc_id,
            default => abort(404, 'Unknown certificate kind.'),
        };
        if (!$docId) abort(404, 'Certificate belum di-generate. Trigger regenerate dulu.');

        $doc = Document::where('org_id', $user->org_id)->findOrFail($docId);
        $org = Organization::findOrFail($user->org_id);
        $disk = $this->storage->getDisk($org);
        if (!$disk->exists($doc->storage_path)) abort(404, 'File missing from storage.');

        return $disk->download($doc->storage_path, $doc->name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * If all executions are final + counted-as-complete, finalize DSR + generate certs.
     * Idempotent: if status already completed, skip cert regen.
     */
    private function maybeCompleteDsr(DsrRequest $dsr): bool
    {
        if (!$dsr->allExecutionsComplete()) return false;
        if ($dsr->status === 'completed') return true;

        $dsr->update([
            'status' => 'completed',
            'closed_at' => $dsr->closed_at ?: now(),
            'responded_at' => $dsr->responded_at ?: now(),
        ]);

        try {
            $this->certService->generateBoth($dsr->fresh());
        } catch (\Throwable $e) {
            // Don't block status transition on cert failure — DPO can retry via /certificates/regenerate
            \Log::warning("DSR cert generation failed for {$dsr->id}: " . $e->getMessage());
        }

        AuditLog::create([
            'org_id' => $dsr->org_id,
            'module' => 'dsr', 'record_id' => $dsr->id,
            'action' => 'dsr.auto_completed',
            'details' => ['trigger' => 'all_executions_complete'],
        ]);

        $this->events->emit(DsrEventBroadcaster::EVENT_COMPLETED, $dsr->fresh());

        return true;
    }
}
