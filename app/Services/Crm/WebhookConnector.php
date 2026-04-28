<?php

namespace App\Services\Crm;

use App\Models\CrmCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Generic webhook connector. POSTs JSON {records: [...]} to endpoint_url.
 * Optional HMAC signature with api_secret.
 */
class WebhookConnector implements CrmConnectorContract
{
    private const BATCH_SIZE = 200;

    public function push(array $records, CrmCredential $credential): array
    {
        $url = $credential->endpoint_url;
        if (! $url) {
            return ['success' => 0, 'failure' => count($records), 'errors' => ['Webhook endpoint_url missing'], 'refs' => []];
        }

        $success = 0;
        $failure = 0;
        $errors = [];
        $refs = [];

        foreach (array_chunk($records, self::BATCH_SIZE) as $batch) {
            $body = ['records' => $batch, 'sent_at' => now()->toIso8601String()];
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Privasimu-Nexus-Extractor/1.0',
            ];
            if ($secret = $credential->api_secret) {
                $sig = hash_hmac('sha256', (string) $payload, $secret);
                $headers['X-Privasimu-Signature'] = 'sha256='.$sig;
            }

            try {
                $res = Http::withHeaders($headers)
                    ->retry(3, 2000, fn ($e) => in_array($e->response?->status(), [429, 502, 503, 504]))
                    ->withBody((string) $payload, 'application/json')
                    ->post($url);

                if ($res->successful()) {
                    $success += count($batch);
                } else {
                    $failure += count($batch);
                    $errors[] = 'Webhook '.$res->status().': '.substr((string) $res->body(), 0, 240);
                }
            } catch (\Throwable $e) {
                $failure += count($batch);
                $errors[] = 'Webhook exception: '.$e->getMessage();
                Log::warning('WebhookConnector push failed', ['error' => $e->getMessage(), 'url' => $url]);
            }
        }

        return ['success' => $success, 'failure' => $failure, 'errors' => $errors, 'refs' => $refs];
    }

    public function probe(CrmCredential $credential): array
    {
        $url = $credential->endpoint_url;
        if (! $url) {
            throw new \RuntimeException('Webhook endpoint_url missing');
        }
        $res = Http::timeout(5)->head($url);
        return ['ok' => $res->successful(), 'status' => $res->status()];
    }
}
