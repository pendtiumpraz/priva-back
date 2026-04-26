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
 * Async DSR webhook delivery to klien's webhook_url.
 *
 * Signature: HMAC-SHA256 of body using app's embed_token as the key.
 * Klien verifies via:
 *   $expected = hash_hmac('sha256', $rawBody, $embedToken);
 *   hash_equals($expected, $request->header('X-Privasimu-Signature'));
 *
 * Headers:
 *   X-Privasimu-Event:        e.g. "dsr.verified"
 *   X-Privasimu-Delivery:     unique delivery UUID for idempotency on klien side
 *   X-Privasimu-Signature:    sha256=<hex>
 *   X-Privasimu-Timestamp:    epoch seconds (replay window check on klien side)
 *
 * Retries: 5 attempts, exponential backoff. After exhaustion, logged + dropped.
 */
class FireDsrWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [30, 120, 600, 1800, 3600];
    public $timeout = 15;

    public function __construct(
        public string $webhookUrl,
        public string $signingSecret,
        public string $event,
        public array $payload,
        public ?string $deliveryId = null,
    ) {
        $this->deliveryId = $deliveryId ?? (string) \Illuminate\Support\Str::uuid();
    }

    public function handle(): void
    {
        $body = json_encode([
            'event' => $this->event,
            'delivery_id' => $this->deliveryId,
            'timestamp' => now()->toIso8601String(),
            'data' => $this->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $signature = 'sha256=' . hash_hmac('sha256', $body, $this->signingSecret);

        try {
            $res = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Privasimu-Webhook/1.0',
                    'X-Privasimu-Event' => $this->event,
                    'X-Privasimu-Delivery' => $this->deliveryId,
                    'X-Privasimu-Signature' => $signature,
                    'X-Privasimu-Timestamp' => (string) time(),
                ])
                ->withBody($body, 'application/json')
                ->post($this->webhookUrl);

            if ($res->failed()) {
                Log::warning("DSR webhook {$this->event} non-2xx ({$res->status()}) → {$this->webhookUrl}");
                throw new \RuntimeException("Webhook returned {$res->status()}");
            }
        } catch (\Throwable $e) {
            Log::warning("DSR webhook fire failed for {$this->event}: " . $e->getMessage());
            throw $e; // queue retry
        }
    }

    /**
     * Final failure — dead-letter logging (Phase 2: write to dsr_webhook_failures table).
     */
    public function failed(\Throwable $e): void
    {
        Log::error('DSR webhook permanently failed', [
            'event' => $this->event,
            'delivery_id' => $this->deliveryId,
            'url' => $this->webhookUrl,
            'reason' => $e->getMessage(),
        ]);
    }
}
