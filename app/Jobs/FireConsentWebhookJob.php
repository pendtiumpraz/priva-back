<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fire the tenant-configured webhook after a consent capture. Runs outside
 * the hot path so the public /consent endpoint returns in <50ms regardless
 * of the tenant's receiver speed.
 *
 * Retries: 3 attempts with exponential backoff (queue driver level).
 *
 * TANDA TANGAN. Badan permintaan ditandatangani HMAC-SHA256 dengan
 * `embed_token` titik pengumpulan sebagai kunci — kontrak yang sama dengan
 * webhook DSR (FireDsrWebhookJob):
 *
 *   $expected = 'sha256='.hash_hmac('sha256', $rawBody, $embedToken);
 *   hash_equals($expected, $request->header('X-Privasimu-Signature'));
 *
 * Dulu job ini mengirim payload TANPA tanda tangan, padahal panduan integrasi
 * di dashboard menyuruh penerima menolak permintaan yang tanda tangannya tidak
 * cocok — penerima yang mengikuti panduan menolak SEMUA kiriman. Bentuk badan
 * (payload datar) tidak berubah; yang bertambah hanya header:
 *
 *   X-Privasimu-Event:      mis. "consent.captured"
 *   X-Privasimu-Delivery:   UUID kiriman — sama di setiap percobaan ulang
 *   X-Privasimu-Signature:  sha256=<hex>
 *   X-Privasimu-Timestamp:  detik epoch (jendela replay di sisi penerima)
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
        public ?string $signingSecret = null,
        public ?string $deliveryId = null,
    ) {
        // Ditetapkan saat dispatch, bukan saat handle(): percobaan ulang harus
        // membawa id yang sama supaya penerima bisa membuang duplikat.
        $this->deliveryId = $deliveryId ?? (string) Str::uuid();
    }

    public function handle(): void
    {
        $body = (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = [
            'User-Agent' => 'Privasimu-Webhook/1.0',
            'X-Privasimu-Event' => (string) ($this->payload['event'] ?? 'consent.captured'),
            'X-Privasimu-Delivery' => (string) $this->deliveryId,
            'X-Privasimu-Timestamp' => (string) time(),
        ];
        if ($this->signingSecret !== null && $this->signingSecret !== '') {
            $headers['X-Privasimu-Signature'] = 'sha256='.hash_hmac('sha256', $body, $this->signingSecret);
        }

        try {
            // withBody: byte yang dikirim PERSIS byte yang ditandatangani.
            $res = Http::timeout(5)
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($this->webhookUrl);
            if ($res->failed()) {
                Log::warning("Consent webhook {$this->collectionCode} non-2xx: {$res->status()}");
                throw new \RuntimeException("Webhook returned {$res->status()}");
            }
        } catch (\Throwable $e) {
            Log::warning("Consent webhook fire failed for {$this->collectionCode}: ".$e->getMessage());
            throw $e; // let queue retry
        }
    }
}
