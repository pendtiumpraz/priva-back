<?php

namespace App\Jobs;

use App\Models\CrmCredential;
use App\Models\ExtractRun;
use App\Services\Consent\ConsentBulkGate;
use App\Services\Consent\ExtractQuery;
use App\Services\Crm\CrmConnectorFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Async push from a queued ExtractRun → CRM target.
 *
 * Steps:
 *   1. Mark run as running.
 *   2. Resolve org's active credential for the target provider (or use the
 *      ad-hoc webhook URL passed via output_target_ref for webhook target).
 *   3. Re-run the consent_logs query from filters.
 *   4. Map records → connector contract shape.
 *   5. Connector.push(records, credential) — connector handles batching.
 *   6. Update run with success/failure counts + status + result_meta.
 */
class PushExtractToCrmJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes for big batches

    public int $tries = 1;     // we retry per-batch inside the connector

    public function __construct(public string $extractRunId) {}

    public function handle(): void
    {
        $run = ExtractRun::find($this->extractRunId);
        if (! $run) {
            return;
        }

        $run->update(['status' => ExtractRun::STATUS_RUNNING, 'started_at' => $run->started_at ?? now()]);

        try {
            $credential = $this->resolveCredential($run);
            if (! $credential) {
                $run->update([
                    'status' => ExtractRun::STATUS_FAILED,
                    'error_summary' => 'No active CRM credential found for provider '.$run->output_target,
                    'finished_at' => now(),
                ]);
                return;
            }

            $records = $this->loadRecords($run);

            $connector = CrmConnectorFactory::make($credential);
            $result = $connector->push($records, $credential);

            $credential->update(['last_used_at' => now()]);

            $status = $result['failure'] === 0
                ? ExtractRun::STATUS_DONE
                : ($result['success'] === 0 ? ExtractRun::STATUS_FAILED : ExtractRun::STATUS_PARTIAL);

            $run->update([
                'status' => $status,
                'success_count' => $result['success'],
                'failure_count' => $result['failure'],
                'error_summary' => empty($result['errors']) ? null : implode("\n", array_slice($result['errors'], 0, 10)),
                'result_meta' => [
                    'refs' => array_slice($result['refs'], 0, 100),
                    'errors_total' => count($result['errors']),
                ],
                'finished_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => ExtractRun::STATUS_FAILED,
                'error_summary' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            throw $e;
        }
    }

    private function resolveCredential(ExtractRun $run): ?CrmCredential
    {
        // Webhook can use an ad-hoc URL passed via output_target_ref (no stored cred).
        if ($run->output_target === CrmCredential::PROVIDER_WEBHOOK && $run->output_target_ref) {
            $cred = new CrmCredential();
            $cred->fill([
                'org_id' => $run->org_id,
                'provider' => CrmCredential::PROVIDER_WEBHOOK,
                'endpoint_url' => $run->output_target_ref,
                'is_active' => true,
            ]);
            return $cred;
        }

        return CrmCredential::query()
            ->where('org_id', $run->org_id)
            ->where('provider', $run->output_target)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * Kueri disusun ExtractQuery — sama persis dengan yang dipakai pratinjau
     * dan unduhan CSV. Dulu job ini menyalin penapisnya sendiri; penapis yang
     * menyimpang berarti orang yang dilihat operator di layar bukan orang yang
     * benar-benar terdorong ke CRM.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadRecords(ExtractRun $run): array
    {
        $filters = $run->filters ?? [];
        $q = app(ExtractQuery::class)->build((string) $run->org_id, $filters);

        // Penimbangan diulang DI SINI, bukan diwariskan dari saat tombol
        // ditekan. Job ini bisa berjalan beberapa menit — bahkan berjam-jam —
        // setelahnya, dan seseorang bisa saja menarik persetujuannya di sela
        // itu. Yang menentukan adalah keadaan pada saat data benar-benar
        // dikirim.
        $gate = app(ConsentBulkGate::class);
        $verdict = $gate->timbang((string) $run->org_id, $q, $filters['segment'] ?? null);
        $gate->catatYangDitahan((string) $run->org_id, $verdict, $run->id);

        if ($verdict->adaPenjagaan()) {
            $run->update(['gate_summary' => $verdict->ringkasan()]);
        }

        // Resolve item UUIDs → titles so the CRM receives readable purpose
        // names, not raw IDs (purpose_keys stores item UUIDs).
        $collectionIds = (clone $q)->distinct()->pluck('collection_id')->all();
        $titleById = \App\Models\ConsentItem::titleMap($collectionIds);

        $records = [];
        $q->orderBy('created_at')->chunk(1000, function ($rows) use (&$records, $titleById, $verdict) {
            foreach ($rows as $r) {
                if (! $verdict->izinkan($r->collection_id, $r->email)) {
                    continue;
                }
                $records[] = [
                    'email' => (string) $r->email,
                    'name' => (string) ($r->name ?? ''),
                    'phone' => (string) ($r->phone ?? ''),
                    'purposes' => is_array($r->purpose_keys)
                        ? array_map(fn ($k) => $titleById[$k] ?? $k, $r->purpose_keys)
                        : [],
                    'captured_at' => $r->created_at?->toIso8601String() ?? '',
                    'source_form' => (string) ($r->source_form ?? ''),
                    'country' => (string) ($r->ip_country ?? ''),
                ];
            }
        });

        return $records;
    }
}
