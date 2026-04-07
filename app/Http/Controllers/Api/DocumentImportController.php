<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportDocumentJob;
use App\Models\DocumentImport;
use App\Models\DocumentImportBatch;
use App\Models\Organization;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;

class DocumentImportController extends Controller
{
    protected TenantStorageService $storageService;

    public function __construct(TenantStorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    /**
     * Upload a single document for AI import.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:51200|mimes:docx,xlsx,xls,csv,pdf', // 50MB max
            'target_module' => 'required|in:ropa,dpia',
        ]);

        $user = $request->user();
        $org = Organization::findOrFail($user->org_id);
        $file = $request->file('file');

        // Store using tenant storage
        $storagePath = $this->storageService->storeTenantFile($org, $file, 'imports/' . date('Y'));

        // Get extension
        $ext = strtolower($file->getClientOriginalExtension());

        $import = DocumentImport::create([
            'org_id' => $org->id,
            'uploaded_by' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'file_type' => $ext,
            'file_size' => $file->getSize(),
            'target_module' => $request->target_module,
            'status' => 'queued',
            'progress' => 0,
            'status_message' => 'Dokumen diunggah. Menunggu antrian proses...',
        ]);

        // Dispatch job
        ImportDocumentJob::dispatch($import->id);

        try {
            \App\Models\AuditLog::log('document_import', $import->id, 'document_uploaded', [
                'filename' => $import->original_filename,
                'file_type' => $ext,
                'file_size' => $file->getSize(),
                'target_module' => $request->target_module,
            ], 'manual');
        } catch (\Exception $e) {}

        return response()->json([
            'message' => 'Dokumen berhasil diunggah dan masuk antrian proses.',
            'data' => $import,
        ], 201);
    }

    /**
     * Batch upload multiple documents.
     */
    public function batchUpload(Request $request)
    {
        $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'file|max:51200|mimes:docx,xlsx,xls,csv,pdf',
            'target_module' => 'required|in:ropa,dpia',
            'batch_name' => 'required|string|max:255',
        ]);

        $user = $request->user();
        $org = Organization::findOrFail($user->org_id);

        // Create batch
        $batch = DocumentImportBatch::create([
            'org_id' => $org->id,
            'created_by' => $user->id,
            'name' => $request->batch_name,
            'target_module' => $request->target_module,
            'total_files' => count($request->file('files')),
        ]);

        $imports = [];
        foreach ($request->file('files') as $file) {
            $storagePath = $this->storageService->storeTenantFile($org, $file, 'imports/' . date('Y'));
            $ext = strtolower($file->getClientOriginalExtension());

            $import = DocumentImport::create([
                'org_id' => $org->id,
                'uploaded_by' => $user->id,
                'original_filename' => $file->getClientOriginalName(),
                'storage_path' => $storagePath,
                'file_type' => $ext,
                'file_size' => $file->getSize(),
                'target_module' => $request->target_module,
                'batch_id' => $batch->id,
                'status' => 'queued',
                'progress' => 0,
                'status_message' => 'Menunggu antrian proses...',
            ]);

            ImportDocumentJob::dispatch($import->id);
            $imports[] = $import;
        }

        return response()->json([
            'message' => count($imports) . ' dokumen berhasil diunggah ke batch.',
            'data' => [
                'batch' => $batch,
                'imports' => $imports,
            ],
        ], 201);
    }

    /**
     * List all imports for the tenant.
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = DocumentImport::where('org_id', $orgId)
            ->with('uploader:id,name');

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->target_module) {
            $query->where('target_module', $request->target_module);
        }
        if ($request->batch_id) {
            $query->where('batch_id', $request->batch_id);
        }

        $imports = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json($imports);
    }

    /**
     * Get a single import detail (including mapped fields).
     */
    public function show(string $id, Request $request)
    {
        $import = DocumentImport::where('org_id', $request->user()->org_id)
            ->with('uploader:id,name')
            ->findOrFail($id);

        return response()->json(['data' => $import]);
    }

    /**
     * Approve AI mapping → create ROPA/DPIA record.
     */
    public function approve(string $id, Request $request)
    {
        $import = DocumentImport::where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        if ($import->status !== 'review') {
            return response()->json(['message' => 'Import ini tidak dalam status review.'], 400);
        }

        // Apply any user edits before approval
        if ($request->has('mapped_fields')) {
            $import->update(['mapped_fields' => $request->mapped_fields]);
        }

        $recordId = ImportDocumentJob::createRecordFromMapping($import);

        if (!$recordId) {
            return response()->json(['message' => 'Gagal membuat record.'], 500);
        }

        return response()->json([
            'message' => 'Record berhasil dibuat dari dokumen!',
            'data' => [
                'import' => $import->fresh(),
                'created_record_id' => $recordId,
            ],
        ]);
    }

    /**
     * Edit mapped fields before approval.
     */
    public function editMapping(string $id, Request $request)
    {
        $request->validate([
            'mapped_fields' => 'required|array',
        ]);

        $import = DocumentImport::where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        if (!in_array($import->status, ['review', 'mapping'])) {
            return response()->json(['message' => 'Mapping hanya bisa diedit saat status review.'], 400);
        }

        $import->update(['mapped_fields' => $request->mapped_fields]);

        return response()->json([
            'message' => 'Mapping berhasil diperbarui.',
            'data' => $import,
        ]);
    }

    /**
     * Delete/cancel an import.
     */
    public function destroy(string $id, Request $request)
    {
        $import = DocumentImport::where('org_id', $request->user()->org_id)
            ->findOrFail($id);

        // Delete file from storage
        $org = Organization::findOrFail($import->org_id);
        $this->storageService->deleteTenantFile($org, $import->storage_path);

        $import->delete();

        return response()->json(['message' => 'Import dibatalkan dan file dihapus.']);
    }

    /**
     * List batches.
     */
    public function batches(Request $request)
    {
        $batches = DocumentImportBatch::where('org_id', $request->user()->org_id)
            ->with('creator:id,name')
            ->withCount('imports')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($batches);
    }

    /**
     * Get batch detail with all imports.
     */
    public function batchDetail(string $id, Request $request)
    {
        $batch = DocumentImportBatch::where('org_id', $request->user()->org_id)
            ->with(['imports.uploader:id,name', 'creator:id,name'])
            ->findOrFail($id);

        return response()->json(['data' => $batch]);
    }
}
