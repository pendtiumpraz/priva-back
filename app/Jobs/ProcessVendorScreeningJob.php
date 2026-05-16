<?php

namespace App\Jobs;

use App\Models\Vendor;
use App\Models\VendorScreening;
use App\Services\CurrentOrgContext;
use App\Services\VendorScreening\VendorScreeningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * TPRM Phase 3.5 — Background screening job.
 *
 * Controller dispatch job ini saat user klik "Run Screening". Job:
 *   1. Set CurrentOrgContext supaya BelongsToOrg scoped query bekerja
 *   2. Find vendor + verify org_id match
 *   3. Update VendorScreening status -> running
 *   4. Call VendorScreeningService::run() — long-running (10-30 detik)
 *   5. Status -> completed atau failed otomatis di service
 *
 * FE polling status via GET /api/vendor-risk/{id}/screenings/{sid} sampai
 * status != 'pending'/'running'.
 *
 * Tries: 1 — kalau gagal, biarkan; user bisa re-run manual.
 * Timeout: 180 detik supaya cocok dengan AI_TIMEOUT default.
 */
class ProcessVendorScreeningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public string $screeningId,
        public string $vendorId,
        public string $orgId,
        public array $sources,
        public ?string $triggeredByUserId = null,
        public ?string $contextPreset = null,
    ) {}

    public function handle(VendorScreeningService $service, CurrentOrgContext $orgContext): void
    {
        // Set tenant context untuk worker (worker tidak punya HTTP request,
        // jadi middleware tidak set ini).
        $orgContext->set($this->orgId);

        try {
            $vendor = Vendor::query()
                ->withoutGlobalScope('org')
                ->where('id', $this->vendorId)
                ->where('org_id', $this->orgId)
                ->first();

            if (! $vendor) {
                $this->markFailed('Vendor tidak ditemukan.');
                return;
            }

            // Service akan update VendorScreening status di dalam run()
            // — kita cuma trigger dengan ID yang sama (re-use existing row).
            // VendorScreeningService::run() bikin row baru by default; di Phase 3.5
            // kita mau re-use row yang sudah dibuat controller untuk konsistensi
            // dengan FE polling. Service signature tetap, kita tinggal pakai
            // result-nya dan update row existing.
            $newScreening = $service->run($vendor, $this->sources, $this->triggeredByUserId, $this->contextPreset);

            // Service create row baru; copy hasil ke row pending yang sudah ada
            $pending = VendorScreening::query()
                ->withoutGlobalScope('org')
                ->where('id', $this->screeningId)
                ->first();

            if ($pending) {
                $pending->forceFill([
                    'status' => $newScreening->status,
                    'overall_risk' => $newScreening->overall_risk,
                    'risk_score' => $newScreening->risk_score,
                    'findings' => $newScreening->findings,
                    'red_flags' => $newScreening->red_flags,
                    'summary' => $newScreening->summary,
                    'recommendation' => $newScreening->recommendation,
                    'search_results_raw' => $newScreening->search_results_raw,
                    'privacy_policy_excerpt' => $newScreening->privacy_policy_excerpt,
                    'documents_summary' => $newScreening->documents_summary,
                    'sanctions_hits' => $newScreening->sanctions_hits,
                    'search_provider' => $newScreening->search_provider,
                    'ai_model' => $newScreening->ai_model,
                    'tokens_used' => $newScreening->tokens_used,
                    'error_message' => $newScreening->error_message,
                    'started_at' => $newScreening->started_at,
                    'completed_at' => $newScreening->completed_at,
                ])->save();

                // Delete row duplikat yang service bikin
                $newScreening->forceDelete();
            }
        } catch (\Throwable $e) {
            Log::error('ProcessVendorScreeningJob failed: '.$e->getMessage(), [
                'screening_id' => $this->screeningId,
                'vendor_id' => $this->vendorId,
            ]);
            $this->markFailed($e->getMessage());
        } finally {
            $orgContext->set(null);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->markFailed($exception->getMessage());
    }

    private function markFailed(string $message): void
    {
        try {
            VendorScreening::query()
                ->withoutGlobalScope('org')
                ->where('id', $this->screeningId)
                ->update([
                    'status' => VendorScreening::STATUS_FAILED,
                    'error_message' => mb_substr($message, 0, 1000),
                    'completed_at' => now(),
                ]);
        } catch (\Throwable $e) {
            Log::error('Failed to mark screening failed: '.$e->getMessage());
        }
    }
}
