<?php

namespace App\Jobs;

use App\Models\AiResult;
use App\Models\Dpia;
use App\Models\Ropa;
use App\Services\AiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sprint C6: Batch AI review for RoPA / DPIA.
 * Processes one record at a time via the queue and writes to ai_results.
 */
class BatchAiReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 2;

    public function __construct(
        public string $orgId,
        public string $userId,
        public string $module,
        public string $recordId,
        public string $batchId,
        public ?string $locale = 'id',
    ) {}

    public function handle(): void
    {
        $ai = (new AiService($this->orgId))->setLocale($this->locale ?? 'id');
        if (! $ai->isAvailable()) {
            Log::warning("BatchAiReviewJob: AI not available for org {$this->orgId}");

            return;
        }

        try {
            if ($this->module === 'ropa') {
                $record = Ropa::where('org_id', $this->orgId)->find($this->recordId);
                if (! $record) {
                    return;
                }
                $response = $ai->ropaAnalysis(array_merge($record->toArray(), [
                    'wizard_data' => $record->wizard_data ?? [],
                ]));
            } elseif ($this->module === 'dpia') {
                $record = Dpia::where('org_id', $this->orgId)->find($this->recordId);
                if (! $record) {
                    return;
                }
                $response = $ai->dpiaRiskScoring($record->toArray(), $record->risk_assessment ?? []);
            } else {
                return;
            }

            AiResult::create([
                'org_id' => $this->orgId,
                'user_id' => $this->userId,
                'feature' => $this->module === 'ropa' ? 'analysis_ropa' : 'analysis_dpia',
                'input_summary' => json_encode([
                    'batch_id' => $this->batchId,
                    'record_id' => $this->recordId,
                    'registration_number' => $record->registration_number ?? null,
                ]),
                'result_data' => $response ?? ['error' => 'No AI response'],
                'credits_used' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::error("BatchAiReviewJob failure: {$e->getMessage()}", [
                'batch_id' => $this->batchId, 'record_id' => $this->recordId,
            ]);
        }
    }
}
