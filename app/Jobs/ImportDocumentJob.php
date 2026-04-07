<?php

namespace App\Jobs;

use App\Models\DocumentImport;
use App\Models\Organization;
use App\Models\Ropa;
use App\Services\AiFieldMappingService;
use App\Services\DocumentParserService;
use App\Services\TenantStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30; // seconds between retries

    public function __construct(
        public string $importId
    ) {
        $this->queue = 'document-imports';
    }

    public function handle(
        TenantStorageService $storageService,
        DocumentParserService $parserService,
        AiFieldMappingService $mappingService,
    ): void {
        $import = DocumentImport::findOrFail($this->importId);
        $org = Organization::findOrFail($import->org_id);

        try {
            // === Step 1: Parse document ===
            $this->updateStatus($import, 'parsing', 10, 'Mengekstrak konten dokumen...');

            // Get file from tenant storage to a temp path
            $disk = $storageService->getDisk($org);
            $fileContents = $disk->get($import->storage_path);

            if (!$fileContents) {
                throw new \RuntimeException("File tidak ditemukan di storage: {$import->storage_path}");
            }

            $tempPath = storage_path("app/temp/{$import->id}.{$import->file_type}");
            if (!is_dir(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }
            file_put_contents($tempPath, $fileContents);

            $extractedData = $parserService->parse($tempPath, $import->file_type);

            // Save extracted data
            $import->update([
                'extracted_data' => $extractedData,
                'progress' => 30,
                'status' => 'analyzing',
                'status_message' => 'Dokumen berhasil diekstrak. Mengirim ke AI untuk analisis...',
            ]);

            // Clean up temp file
            @unlink($tempPath);

            // === Step 2: AI Field Mapping ===
            $this->updateStatus($import, 'analyzing', 40, 'AI sedang menganalisis dan mapping field...');

            $mappingResult = $mappingService->map($extractedData, $import->target_module, $import->org_id);

            $import->update([
                'mapped_fields' => $mappingResult['mapped_fields'] ?? [],
                'confidence_scores' => $mappingResult['confidence_scores'] ?? [],
                'progress' => 80,
                'status' => 'review',
                'status_message' => 'Analisis selesai. Menunggu review dan approval.',
            ]);

            // Deduct AI credit
            try {
                if ($org->ai_credits_remaining !== null) {
                    $org->decrement('ai_credits_remaining', 2); // 2 credits per document analysis
                    \App\Models\AiCreditLog::create([
                        'org_id' => $org->id,
                        'user_id' => $import->uploaded_by,
                        'action_type' => 'document_analysis',
                        'credits_used' => 2,
                        'status' => 'success',
                        'metadata' => ['import_id' => $import->id, 'filename' => $import->original_filename],
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning("Credit deduction failed for import {$import->id}: {$e->getMessage()}");
            }

        } catch (\Exception $e) {
            $import->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'retry_count' => $import->retry_count + 1,
                'status_message' => 'Gagal: ' . $e->getMessage(),
            ]);

            // Recalculate batch if part of one
            if ($import->batch_id) {
                $import->batch?->recalculate();
            }

            // Re-throw so Laravel can handle retry
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            \Log::error("ImportDocumentJob failed permanently for {$import->id}: {$e->getMessage()}");
        }
    }

    /**
     * Create ROPA record from approved mapping.
     * Called externally after user approves the mapping.
     */
    public static function createRecordFromMapping(DocumentImport $import): ?string
    {
        if (!$import->mapped_fields || $import->status !== 'review') {
            return null;
        }

        $org = Organization::findOrFail($import->org_id);
        $mappedFields = $import->mapped_fields;

        if ($import->target_module === 'ropa') {
            // Build wizard_data from mapped sections
            $wizardData = [];
            foreach ($mappedFields as $sectionKey => $fields) {
                $wizardData[$sectionKey] = $fields;
            }

            // Extract top-level ROPA fields from wizard data
            $ropa = Ropa::create([
                'org_id' => $org->id,
                'registration_number' => 'ROPA-' . date('Y') . '-' . str_pad(
                    Ropa::where('org_id', $org->id)->count() + 1, 4, '0', STR_PAD_LEFT
                ),
                'processing_activity' => $wizardData['detail_pemrosesan']['processing_activity'] ?? 'Imported from document',
                'entity' => $wizardData['detail_pemrosesan']['entity'] ?? $org->name,
                'division' => $wizardData['detail_pemrosesan']['division'] ?? null,
                'work_unit' => $wizardData['detail_pemrosesan']['work_unit'] ?? null,
                'description' => $wizardData['detail_pemrosesan']['description'] ?? null,
                'risk_level' => $wizardData['detail_pemrosesan']['risk_level'] ?? 'medium',
                'kategori_pemrosesan' => $wizardData['dpo_team']['kategori_pemrosesan'] ?? null,
                'purpose' => $wizardData['informasi_pemrosesan']['purpose'] ?? null,
                'legal_basis' => $wizardData['informasi_pemrosesan']['legal_basis'] ?? null,
                'data_categories' => is_array($wizardData['pengumpulan_data']['jenis_data'] ?? null) ? $wizardData['pengumpulan_data']['jenis_data'] : null,
                'data_subjects' => is_array($wizardData['pengumpulan_data']['kategori_subjek'] ?? null) ? $wizardData['pengumpulan_data']['kategori_subjek'] : null,
                'retention_period' => $wizardData['retensi_keamanan']['retention_period'] ?? null,
                'security_measures' => $wizardData['retensi_keamanan']['langkah_keamanan'] ?? null,
                'wizard_data' => $wizardData,
                'status' => 'draft',
                'created_by' => $import->uploaded_by,
            ]);

            // Calculate and save progress
            $ropa->progress = $ropa->calculateProgress();
            $ropa->save();

            // Update import record
            $import->update([
                'status' => 'completed',
                'progress' => 100,
                'created_record_id' => $ropa->id,
                'status_message' => "ROPA {$ropa->registration_number} berhasil dibuat.",
            ]);

            // Recalculate batch
            if ($import->batch_id) {
                $import->batch?->recalculate();
            }

            return $ropa->id;
        }

        // DPIA support can be added later
        return null;
    }

    private function updateStatus(DocumentImport $import, string $status, int $progress, string $message): void
    {
        $import->update([
            'status' => $status,
            'progress' => $progress,
            'status_message' => $message,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $import = DocumentImport::find($this->importId);
        if ($import) {
            $import->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'status_message' => 'Gagal setelah semua percobaan: ' . $exception->getMessage(),
            ]);

            if ($import->batch_id) {
                $import->batch?->recalculate();
            }
        }
    }
}
