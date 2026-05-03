<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateDocumentRequest;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Services\AiService;
use App\Services\DocumentMakerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Document Maker — Policy & Contract draft generator.
 *
 * Endpoints:
 *   GET    /api/document-maker?kind=policy|contract&trash=0|1&page=N&per_page=15
 *   POST   /api/document-maker/{kind}/generate     (kind: policy|contract)
 *   GET    /api/document-maker/{id}
 *   PUT    /api/document-maker/{id}                (manual edit, no AI)
 *   GET    /api/document-maker/{id}/download.docx
 *   GET    /api/document-maker/{id}/download.pdf
 *   DELETE /api/document-maker/{id}                (soft delete)
 *   POST   /api/document-maker/{id}/restore        (recover from trash)
 *   DELETE /api/document-maker/{id}/force          (permanent delete from trash)
 */
class DocumentMakerController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $kind = $request->query('kind');
        $trash = $request->boolean('trash');
        $perPage = (int) ($request->query('per_page') ?? 15);
        if ($perPage < 1) {
            $perPage = 15;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $q = GeneratedDocument::query()->where('org_id', $orgId);
        if ($trash) {
            $q->onlyTrashed();
        }
        if (in_array($kind, [GeneratedDocument::KIND_POLICY, GeneratedDocument::KIND_CONTRACT], true)) {
            $q->where('kind', $kind);
        }

        // Backwards compat: when paginate-relevant flags are absent, return the
        // legacy `{ data: [...] }` shape so existing callers (policy-review &
        // contract-review pages) keep working without changes.
        $legacyShape = ! $request->has('page') && ! $request->has('per_page') && ! $request->has('trash');

        if ($legacyShape) {
            $rows = $q->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'kind', 'document_type', 'title', 'ai_provider', 'ai_model', 'created_at', 'updated_at']);

            return response()->json(['data' => $rows]);
        }

        $docs = $q->orderByDesc('created_at')
            ->paginate($perPage, ['id', 'kind', 'document_type', 'title', 'ai_provider', 'ai_model', 'created_at', 'updated_at', 'deleted_at']);

        return response()->json($docs);
    }

    public function generate(GenerateDocumentRequest $request, string $kind)
    {
        if (! in_array($kind, [GeneratedDocument::KIND_POLICY, GeneratedDocument::KIND_CONTRACT], true)) {
            return response()->json(['message' => 'Invalid kind'], 422);
        }

        $orgId = $request->user()->org_id;
        $userId = $request->user()->id;
        if (! $orgId) {
            return response()->json(['message' => 'Org context missing'], 403);
        }

        $payload = $request->validated();
        $wizardInputs = $payload['wizard_inputs'];
        if (empty($wizardInputs['language'])) {
            $wizardInputs['language'] = $request->user()->locale ?? 'id';
        }

        try {
            $ai = new AiService($orgId, 'chat');
            $service = new DocumentMakerService($ai);
            $doc = $service->generate(
                $orgId,
                $userId,
                $kind,
                (string) $payload['document_type'],
                (string) $payload['title'],
                $wizardInputs,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (\Throwable $e) {
            \Log::error('DocumentMaker.generate failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return response()->json(['message' => 'Failed to generate document.'], 500);
        }

        AuditLog::log('document_maker', $doc->id, 'create', [
            'kind' => $doc->kind,
            'document_type' => $doc->document_type,
            'title' => $doc->title,
        ]);

        return response()->json(['data' => $doc]);
    }

    public function show(Request $request, string $id)
    {
        $doc = GeneratedDocument::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $doc) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $doc]);
    }

    public function destroy(Request $request, string $id)
    {
        $doc = GeneratedDocument::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $doc) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $doc->delete();
        AuditLog::log('document_maker', $doc->id, 'delete');

        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Manual edit of a generated document — no AI re-generation.
     * Allows updating the title and/or ai_output sections JSON in place.
     * Always preserves and stamps metadata.last_edited_at / last_edited_by.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'ai_output' => 'nullable|array',
            'ai_output.title' => 'nullable|string',
            'ai_output.metadata' => 'nullable|array',
            'ai_output.sections' => 'nullable|array',
            'ai_output.sections.*.type' => 'required_with:ai_output.sections|string|in:heading_1,heading_2,heading_3,paragraph,list,table,signature_block',
        ]);

        $doc = GeneratedDocument::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $doc) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($request->has('title')) {
            $doc->title = $request->input('title');
        }

        if ($request->has('ai_output')) {
            $newOutput = $request->input('ai_output');
            if (! is_array($newOutput)) {
                $newOutput = [];
            }
            // Preserve existing metadata if caller did not supply it.
            $existingMeta = is_array($doc->ai_output['metadata'] ?? null) ? $doc->ai_output['metadata'] : [];
            $incomingMeta = is_array($newOutput['metadata'] ?? null) ? $newOutput['metadata'] : [];
            $newOutput['metadata'] = array_merge($existingMeta, $incomingMeta, [
                'last_edited_at' => now()->toIso8601String(),
                'last_edited_by' => $request->user()->id,
            ]);
            $doc->ai_output = $newOutput;
        }

        $doc->save();

        AuditLog::log('document_maker', $doc->id, 'update', [
            'title_changed' => $request->has('title'),
            'content_changed' => $request->has('ai_output'),
        ]);

        return response()->json(['data' => $doc]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $doc = GeneratedDocument::onlyTrashed()
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $doc) {
            return response()->json(['message' => 'Not found in trash'], 404);
        }

        $doc->restore();
        AuditLog::log('document_maker', $doc->id, 'restore');

        return response()->json(['message' => 'Restored', 'data' => $doc]);
    }

    public function forceDelete(Request $request, string $id): JsonResponse
    {
        $doc = GeneratedDocument::onlyTrashed()
            ->where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $doc) {
            return response()->json(['message' => 'Not found in trash'], 404);
        }

        $doc->forceDelete();
        AuditLog::log('document_maker', $id, 'force_delete');

        return response()->json(['message' => 'Permanently deleted']);
    }

    public function downloadDocx(Request $request, string $id)
    {
        $doc = GeneratedDocument::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $doc) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $service = new DocumentMakerService(new AiService($doc->org_id, 'chat'));
            $path = $service->renderDocx($doc);
        } catch (\Throwable $e) {
            \Log::error('DocumentMaker.renderDocx failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to render DOCX.'], 500);
        }

        AuditLog::log('document_maker', $doc->id, 'download.docx');

        $filename = Str::slug($doc->title ?: 'document', '_').'.docx';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    public function downloadPdf(Request $request, string $id)
    {
        $doc = GeneratedDocument::where('id', $id)
            ->where('org_id', $request->user()->org_id)
            ->first();
        if (! $doc) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $service = new DocumentMakerService(new AiService($doc->org_id, 'chat'));
            $path = $service->renderPdf($doc);
        } catch (\Throwable $e) {
            \Log::error('DocumentMaker.renderPdf failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to render PDF.'], 500);
        }

        AuditLog::log('document_maker', $doc->id, 'download.pdf');

        $filename = Str::slug($doc->title ?: 'document', '_').'.pdf';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }
}
