<?php

namespace App\Services\Crm;

use App\Models\CrmCredential;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mailchimp List/Audience batch members upsert.
 *
 * Auth: api_key like "xxxx-usZ" — DC suffix derives the API host.
 * list_or_object_ref: list/audience id.
 *
 * Endpoint: POST /3.0/lists/{list_id}
 * Body: { members: [{ email_address, status, merge_fields, tags }], update_existing: true }
 */
class MailchimpConnector implements CrmConnectorContract
{
    private const BATCH_SIZE = 500; // Mailchimp recommends ≤500

    public function push(array $records, CrmCredential $credential): array
    {
        $apiKey = $credential->api_key;
        $listId = $credential->list_or_object_ref;
        if (! $apiKey || ! $listId) {
            return ['success' => 0, 'failure' => count($records), 'errors' => ['Mailchimp api_key or list_id missing'], 'refs' => []];
        }
        $dc = $this->extractDc($apiKey);
        if (! $dc) {
            return ['success' => 0, 'failure' => count($records), 'errors' => ['Mailchimp api_key missing data-center suffix'], 'refs' => []];
        }
        $endpoint = "https://{$dc}.api.mailchimp.com/3.0/lists/{$listId}";

        $success = 0;
        $failure = 0;
        $errors = [];
        $refs = [];

        foreach (array_chunk($records, self::BATCH_SIZE) as $batch) {
            $members = array_map(fn ($r) => $this->mapRecord($r), $batch);

            try {
                $res = Http::withBasicAuth('anystring', $apiKey)
                    ->acceptJson()
                    ->retry(3, 1500, fn ($e) => $e->response?->status() === 429)
                    ->post($endpoint, [
                        'members' => $members,
                        'update_existing' => true,
                    ]);

                if ($res->successful()) {
                    $j = $res->json();
                    $success += (int) ($j['total_created'] ?? 0) + (int) ($j['total_updated'] ?? 0);
                    $errCount = count((array) ($j['errors'] ?? []));
                    $failure += $errCount;
                    foreach ((array) ($j['errors'] ?? []) as $e) {
                        $errors[] = 'Mailchimp: '.($e['error'] ?? 'unknown').' for '.($e['email_address'] ?? '');
                    }
                    foreach ((array) ($j['new_members'] ?? []) as $m) {
                        if (! empty($m['id'])) $refs[] = (string) $m['id'];
                    }
                } else {
                    $failure += count($batch);
                    $errors[] = 'Mailchimp '.$res->status().': '.substr((string) $res->body(), 0, 240);
                }
            } catch (\Throwable $e) {
                $failure += count($batch);
                $errors[] = 'Mailchimp exception: '.$e->getMessage();
                Log::warning('MailchimpConnector push failed', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => $success, 'failure' => $failure, 'errors' => $errors, 'refs' => $refs];
    }

    public function probe(CrmCredential $credential): array
    {
        $apiKey = $credential->api_key;
        $dc = $this->extractDc($apiKey ?? '');
        if (! $dc || ! $apiKey) {
            throw new \RuntimeException('Mailchimp api_key invalid (missing dc suffix)');
        }
        $res = Http::withBasicAuth('anystring', $apiKey)
            ->acceptJson()
            ->get("https://{$dc}.api.mailchimp.com/3.0/ping");
        if (! $res->successful()) {
            throw new \RuntimeException('Mailchimp probe failed: '.$res->status());
        }
        return ['ok' => true, 'status' => $res->status()];
    }

    private function extractDc(string $apiKey): ?string
    {
        // Mailchimp keys end with "-us21" / "-eu1" etc.
        if (preg_match('/-([a-z]{2}\d+)$/', $apiKey, $m)) {
            return $m[1];
        }
        return null;
    }

    private function mapRecord(array $r): array
    {
        $name = (string) ($r['name'] ?? '');
        $parts = preg_split('/\s+/', trim($name), 2);
        $purposes = is_array($r['purposes'] ?? null) ? $r['purposes'] : [];

        return [
            'email_address' => strtolower((string) ($r['email'] ?? '')),
            'status' => 'subscribed', // implies opt-in via Privasimu's record
            'merge_fields' => array_filter([
                'FNAME' => $parts[0] ?? '',
                'LNAME' => $parts[1] ?? '',
                'PHONE' => $r['phone'] ?? '',
                'PRVSRC' => $r['source_form'] ?? '',
            ]),
            'tags' => array_values(array_filter(array_map(fn ($p) => 'privasimu:'.$p, $purposes))),
        ];
    }
}
