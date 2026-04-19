<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fire the tenant-configured webhook after a consent capture. Runs outside
 * the hot path so the public /consent endpoint returns in <50ms regardless
 * of the tenant's receiver speed.
 *
 * Retries: 3 attempts with exponential backoff (queue driver level).
 */
class FireConsentWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 120, 600];
    public $timeout = 10;

    public function __construct(
        public string $webhookUrl,
        public string $collectionCode,
        public array $payload,
    ) {}

    public function handle(): void
    {
        try {
            $res = Http::timeout(5)->post($this->webhookUrl, $this->payload);
            if ($res->failed()) {
                Log::warning("Consent webhook {$this->collectionCode} non-2xx: {$res->status()}");
                throw new \RuntimeException("Webhook returned {$res->status()}");
            }
        } catch (\Throwable $e) {
            Log::warning("Consent webhook fire failed for {$this->collectionCode}: " . $e->getMessage());
            throw $e; // let queue retry
        }
    }
}
