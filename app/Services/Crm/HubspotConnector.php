<?php

namespace App\Services\Crm;

use App\Models\CrmCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HubSpot Contacts batch upsert via Private App token.
 *
 * Contract record shape mapped to HubSpot properties:
 *   email     → email (id)
 *   name      → firstname (split first word) + lastname (rest)
 *   phone     → phone
 *   purposes  → privasimu_consent_purposes (custom property, comma-separated)
 *   country   → country
 *   source_form → privasimu_source_form (custom property)
 *
 * Rate limit: HubSpot allows 100 req/10sec. We chunk to 100 records/req
 * (HubSpot batch upsert max).
 */
class HubspotConnector implements CrmConnectorContract
{
    private const BATCH_SIZE = 100;

    private const ENDPOINT = 'https://api.hubapi.com/crm/v3/objects/contacts/batch/upsert';

    public function push(array $records, CrmCredential $credential): array
    {
        $token = $credential->api_key;
        if (! $token) {
            return ['success' => 0, 'failure' => count($records), 'errors' => ['HubSpot api_key missing'], 'refs' => []];
        }

        $success = 0;
        $failure = 0;
        $errors = [];
        $refs = [];

        foreach (array_chunk($records, self::BATCH_SIZE) as $batch) {
            $payload = ['inputs' => array_map(fn ($r) => $this->mapRecord($r), $batch)];

            try {
                $res = Http::withToken($token)
                    ->acceptJson()
                    ->retry(3, 1500, fn ($e, $req) => $e->response?->status() === 429)
                    ->post(self::ENDPOINT, $payload);

                if ($res->successful()) {
                    $success += count($batch);
                    foreach ((array) $res->json('results', []) as $r) {
                        if (! empty($r['id'])) $refs[] = (string) $r['id'];
                    }
                } else {
                    $failure += count($batch);
                    $errors[] = 'HubSpot '.$res->status().': '.substr((string) $res->body(), 0, 240);
                }
            } catch (\Throwable $e) {
                $failure += count($batch);
                $errors[] = 'HubSpot exception: '.$e->getMessage();
                Log::warning('HubspotConnector push failed', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => $success, 'failure' => $failure, 'errors' => $errors, 'refs' => $refs];
    }

    public function probe(CrmCredential $credential): array
    {
        $token = $credential->api_key;
        if (! $token) {
            throw new \RuntimeException('HubSpot api_key missing');
        }
        $res = Http::withToken($token)->acceptJson()
            ->get('https://api.hubapi.com/crm/v3/objects/contacts', ['limit' => 1]);
        if (! $res->successful()) {
            throw new \RuntimeException('HubSpot probe failed: '.$res->status());
        }
        return ['ok' => true, 'status' => $res->status()];
    }

    private function mapRecord(array $r): array
    {
        $name = (string) ($r['name'] ?? '');
        $parts = preg_split('/\s+/', trim($name), 2);
        $first = $parts[0] ?? '';
        $last = $parts[1] ?? '';

        $purposes = $r['purposes'] ?? [];
        if (! is_array($purposes)) $purposes = [];

        return [
            'idProperty' => 'email',
            'id' => strtolower((string) ($r['email'] ?? '')),
            'properties' => array_filter([
                'email' => strtolower((string) ($r['email'] ?? '')),
                'firstname' => $first ?: null,
                'lastname' => $last ?: null,
                'phone' => $r['phone'] ?? null,
                'country' => $r['country'] ?? null,
                'privasimu_consent_purposes' => implode(',', $purposes),
                'privasimu_source_form' => $r['source_form'] ?? null,
                'privasimu_consent_captured_at' => $r['captured_at'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }
}
