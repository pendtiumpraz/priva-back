<?php

namespace App\Jobs;

use App\Models\ConsentLog;
use App\Services\CrmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Push a consent capture to an external CRM (Salesforce/HubSpot/etc).
 * Runs off the hot path since CRM round-trips can be several seconds.
 */
class PushConsentToCrmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900];
    public $timeout = 30;

    public function __construct(
        public string $providerId,
        public array $providerConfig,
        public string $consentLogId,
    ) {}

    public function handle(): void
    {
        $log = ConsentLog::find($this->consentLogId);
        if (!$log) return; // log was deleted before we got here — nothing to do

        try {
            CrmService::pushConsent($this->providerId, $this->providerConfig, $log);
        } catch (\Throwable $e) {
            Log::warning("CRM push ({$this->providerId}) failed for consent {$log->id}: " . $e->getMessage());
            throw $e;
        }
    }
}
