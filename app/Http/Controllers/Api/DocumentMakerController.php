<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateDocumentRequest;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Services\AiService;
use App\Services\DocumentMakerService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Document Maker — Policy & Contract draft generator.
 *
 * Endpoints:
 *   GET    /api/document-maker?kind=policy|contract
 *   POST   /api/document-maker/{kind}/generate     (kind: policy|contract)
 *   GET    /api/document-maker/{id}
 *   GET    /api/document-maker/{id}/download.docx
 *   GET    /api/document-maker/{id}/download.pdf
 *   DELETE /api/document-maker/{id}                (soft delete)
 */
class DocumentMakerController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $kind = $request->query('kind');

        $q = GeneratedDocument::query()->where('org_id', $orgId);
        if (in_array($kind, [GeneratedDocument::KIND_POLICY, GeneratedDocument::KIND_CONTRACT], true)) {
            $q->where('kind', $kind);
        }

        $rows = $q->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'kind', 'document_type', 'title', 'ai_provider', 'ai_model', 'created_at']);

        return response()->json(['data' => $rows]);
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
