<?php

namespace App\Console\Commands;

use App\Jobs\EmbedRecordJob;
use App\Models\BreachIncident;
use App\Models\Dpia;
use App\Models\KnowledgeBaseSection;
use App\Models\Ropa;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Backfill vector embeddings untuk record yang sudah ada di database.
 *
 * Dipakai sekali setelah RAG feature di-enable supaya record lama
 * ikut ter-index. Record baru ditangani oleh observer (RopaEmbeddingObserver
 * dll.) yang dispatch EmbedRecordJob otomatis di event `saved`.
 *
 * Contoh:
 *   php artisan embeddings:backfill all
 *   php artisan embeddings:backfill ropa --org=01HXY... --force
 *   php artisan embeddings:backfill kb --chunk=50
 */
class EmbeddingsBackfillCommand extends Command
{
    protected $signature = 'embeddings:backfill '
        .'{module : Module name (ropa|dpia|breach|vendor|kb|all)} '
        .'{--org= : Filter by org_id} '
        .'{--chunk=100 : DB chunk size} '
        .'{--force : Re-embed even if exists}';

    protected $description = 'Backfill vector embeddings untuk existing records';

    /** Modul yang didukung. */
    private const SUPPORTED_MODULES = ['ropa', 'dpia', 'breach', 'vendor', 'kb', 'all'];

    /** @var int total record di-iterate */
    private int $totalProcessed = 0;

    /** @var int total job dispatched ke queue */
    private int $totalDispatched = 0;

    /** @var int total record di-skip (empty content, dll.) */
    private int $totalSkipped = 0;

    public function handle(): int
    {
        $module = strtolower((string) $this->argument('module'));
        if (! in_array($module, self::SUPPORTED_MODULES, true)) {
            $this->error("Module tidak dikenal: {$module}");
            $this->line('Pilihan: '.implode(', ', self::SUPPORTED_MODULES));

            return self::INVALID;
        }

        if (config('ai_embedding.enabled') === false) {
            $this->info('AI embedding tidak aktif (config ai_embedding.enabled=false). Tidak ada yang dikerjakan.');

            return self::SUCCESS;
        }

        // RAG butuh Postgres + pgvector. Driver lain (sqlite/mysql) tidak punya
        // tipe `vector` jadi insert pasti gagal — bail out di awal.
        $driver = DB::getDriverName();
        if ($driver !== 'pgsql') {
            $this->error('RAG butuh Postgres dengan pgvector. Driver saat ini: '.$driver);

            return self::FAILURE;
        }

        $orgFilter = $this->option('org');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $force = (bool) $this->option('force');

        $this->info('Backfill embeddings — module='.$module
            .($orgFilter ? " org={$orgFilter}" : '')
            .' chunk='.$chunkSize
            .($force ? ' force=yes' : ''));

        $modules = $module === 'all'
            ? ['ropa', 'dpia', 'breach', 'vendor', 'kb']
            : [$module];

        foreach ($modules as $m) {
            $this->newLine();
            $this->line("→ {$m}");
            match ($m) {
                'ropa' => $this->backfillRopa($orgFilter, $chunkSize, $force),
                'dpia' => $this->backfillDpia($orgFilter, $chunkSize, $force),
                'breach' => $this->backfillBreach($orgFilter, $chunkSize, $force),
                'vendor' => $this->backfillVendor($orgFilter, $chunkSize, $force),
                'kb' => $this->backfillKb($orgFilter, $chunkSize, $force),
            };
        }

        $this->newLine(2);
        $this->info('Selesai.');
        $this->line("Total processed : {$this->totalProcessed}");
        $this->line("Total dispatched: {$this->totalDispatched}");
        $this->line("Total skipped   : {$this->totalSkipped}");

        return self::SUCCESS;
    }

    private function backfillRopa(?string $orgId, int $chunkSize, bool $force): void
    {
        $query = Ropa::query();
        $this->applyOrgFilter($query, $orgId);
        $total = (clone $query)->count();
        if ($total === 0) {
            $this->line('  Tidak ada record.');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($rows) use ($bar, $force) {
            foreach ($rows as $ropa) {
                $this->totalProcessed++;

                $wizardName = data_get($ropa->wizard_data, 'detail_pemrosesan.nama_pemrosesan');
                $parts = array_filter([
                    (string) $ropa->registration_number,
                    (string) $ropa->processing_activity,
                    (string) ($ropa->description ?: $wizardName ?: ''),
                ], fn ($p) => $p !== '');
                $content = trim(implode(' | ', $parts));

                if ($content === '') {
                    $this->totalSkipped++;
                    $bar->advance();

                    continue;
                }

                EmbedRecordJob::dispatch(
                    (string) $ropa->org_id,
                    'ropa',
                    (string) $ropa->id,
                    $content,
                    [
                        'registration_number' => $ropa->registration_number,
                        'risk_level' => $ropa->risk_level,
                        'status' => $ropa->status,
                        'force' => $force,
                    ],
                );
                $this->totalDispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function backfillDpia(?string $orgId, int $chunkSize, bool $force): void
    {
        $query = Dpia::query();
        $this->applyOrgFilter($query, $orgId);
        $total = (clone $query)->count();
        if ($total === 0) {
            $this->line('  Tidak ada record.');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($rows) use ($bar, $force) {
            foreach ($rows as $dpia) {
                $this->totalProcessed++;

                $namaPemrosesan = data_get($dpia->wizard_data, 'informasi_dpia.nama_pemrosesan')
                    ?? data_get($dpia->wizard_data, 'detail_pemrosesan.nama_pemrosesan')
                    ?? '';

                // Ringkasan risk_events: ambil dari wizard_data.potensi_risiko[*].risk_events[].name/description
                $riskSummary = $this->summarizeDpiaRiskEvents($dpia->wizard_data);

                $parts = array_filter([
                    (string) $dpia->registration_number,
                    (string) $namaPemrosesan,
                    $riskSummary,
                ], fn ($p) => $p !== '');
                $content = trim(implode(' | ', $parts));

                if ($content === '') {
                    $this->totalSkipped++;
                    $bar->advance();

                    continue;
                }

                EmbedRecordJob::dispatch(
                    (string) $dpia->org_id,
                    'dpia',
                    (string) $dpia->id,
                    $content,
                    [
                        'registration_number' => $dpia->registration_number,
                        'risk_level' => $dpia->risk_level,
                        'status' => $dpia->status,
                        'force' => $force,
                    ],
                );
                $this->totalDispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function backfillBreach(?string $orgId, int $chunkSize, bool $force): void
    {
        $query = BreachIncident::query();
        $this->applyOrgFilter($query, $orgId);
        $total = (clone $query)->count();
        if ($total === 0) {
            $this->line('  Tidak ada record.');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($rows) use ($bar, $force) {
            foreach ($rows as $breach) {
                $this->totalProcessed++;

                $parts = array_filter([
                    (string) $breach->incident_code,
                    (string) $breach->title,
                    (string) $breach->description, // auto-dekripsi via cast EncryptedString
                ], fn ($p) => $p !== '');
                $content = trim(implode(' | ', $parts));

                if ($content === '') {
                    $this->totalSkipped++;
                    $bar->advance();

                    continue;
                }

                EmbedRecordJob::dispatch(
                    (string) $breach->org_id,
                    'breach',
                    (string) $breach->id,
                    $content,
                    [
                        'incident_code' => $breach->incident_code,
                        'severity' => $breach->severity,
                        'status' => $breach->status,
                        'force' => $force,
                    ],
                );
                $this->totalDispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function backfillVendor(?string $orgId, int $chunkSize, bool $force): void
    {
        $query = Vendor::query();
        $this->applyOrgFilter($query, $orgId);
        $total = (clone $query)->count();
        if ($total === 0) {
            $this->line('  Tidak ada record.');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($rows) use ($bar, $force) {
            foreach ($rows as $vendor) {
                $this->totalProcessed++;

                // Risk summary: ambil dari latest VendorAssessment kalau ada.
                $riskSummary = $this->summarizeVendorRisk($vendor);

                $parts = array_filter([
                    (string) $vendor->name,
                    (string) ($vendor->description ?? ''),
                    $riskSummary,
                ], fn ($p) => $p !== '');
                $content = trim(implode(' | ', $parts));

                if ($content === '') {
                    $this->totalSkipped++;
                    $bar->advance();

                    continue;
                }

                EmbedRecordJob::dispatch(
                    (string) $vendor->org_id,
                    'vendor',
                    (string) $vendor->id,
                    $content,
                    [
                        'name' => $vendor->name,
                        'risk_level' => $vendor->risk_level,
                        'dpa_status' => $vendor->dpa_status,
                        'force' => $force,
                    ],
                );
                $this->totalDispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function backfillKb(?string $orgId, int $chunkSize, bool $force): void
    {
        $query = KnowledgeBaseSection::query()->where('is_active', true);

        // KB sections punya org_id nullable (system-wide rows = null). Kalau
        // user kasih --org, ambil rows untuk org itu PLUS rows shared (null).
        if ($orgId !== null) {
            $query->where(function ($q) use ($orgId) {
                $q->where('org_id', $orgId)->orWhereNull('org_id');
            });
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->line('  Tidak ada record.');

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($rows) use ($bar, $force, $orgId) {
            foreach ($rows as $kb) {
                $this->totalProcessed++;

                $parts = array_filter([
                    (string) $kb->title,
                    (string) ($kb->content ?: $kb->summary ?: ''),
                ], fn ($p) => $p !== '');
                $content = trim(implode("\n", $parts));

                if ($content === '') {
                    $this->totalSkipped++;
                    $bar->advance();

                    continue;
                }

                // Shared KB sections (org_id NULL) butuh fallback org_id supaya
                // vector_embeddings.org_id (NOT NULL) tetap valid. Pakai org
                // dari --org bila ada, kalau tidak ada kita skip — system-wide
                // KB harus di-embed via job khusus dengan org system.
                $targetOrg = $kb->org_id ?? $orgId;
                if ($targetOrg === null) {
                    $this->totalSkipped++;
                    $bar->advance();

                    continue;
                }

                EmbedRecordJob::dispatch(
                    (string) $targetOrg,
                    'kb',
                    (string) $kb->id,
                    $content,
                    [
                        'title' => $kb->title,
                        'module_key' => $kb->module_key,
                        'category' => $kb->category,
                        'shared' => $kb->org_id === null,
                        'force' => $force,
                    ],
                );
                $this->totalDispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    private function applyOrgFilter(Builder $query, ?string $orgId): void
    {
        if ($orgId !== null && $orgId !== '') {
            $query->where('org_id', $orgId);
        }
    }

    /**
     * Ringkas risk_events di wizard_data DPIA jadi satu string pendek.
     * Struktur: wizard_data.potensi_risiko[category].risk_events[]
     */
    private function summarizeDpiaRiskEvents(?array $wizardData): string
    {
        if (! is_array($wizardData)) {
            return '';
        }
        $potensi = $wizardData['potensi_risiko'] ?? [];
        if (! is_array($potensi)) {
            return '';
        }

        $parts = [];
        foreach ($potensi as $category => $bucket) {
            if (! is_array($bucket)) {
                continue;
            }
            $events = $bucket['risk_events'] ?? [];
            if (! is_array($events)) {
                continue;
            }
            foreach ($events as $ev) {
                if (! is_array($ev)) {
                    continue;
                }
                $label = trim((string) ($ev['name'] ?? $ev['description'] ?? $ev['title'] ?? ''));
                if ($label !== '') {
                    $parts[] = $label;
                }
            }
            // Cegah string membesar tak terkendali — cukup 20 event terbaik.
            if (count($parts) >= 20) {
                break;
            }
        }

        return $parts === [] ? '' : 'Risiko: '.implode('; ', array_slice($parts, 0, 20));
    }

    /**
     * Ringkas risk profile vendor: ambil risk_level + recommendation singkat
     * dari latest VendorAssessment kalau ada.
     */
    private function summarizeVendorRisk(Vendor $vendor): string
    {
        $parts = [];
        if ($vendor->risk_level) {
            $parts[] = 'Risk: '.$vendor->risk_level;
        }
        if ($vendor->risk_score !== null) {
            $parts[] = 'Score: '.$vendor->risk_score;
        }

        $latest = VendorAssessment::query()
            ->where('vendor_id', $vendor->id)
            ->orderByDesc('created_at')
            ->first();

        if ($latest) {
            if (! empty($latest->recommendations)) {
                $rec = is_array($latest->recommendations)
                    ? implode(' ', array_map(
                        fn ($r) => is_string($r) ? $r : (string) data_get($r, 'text', ''),
                        $latest->recommendations
                    ))
                    : (string) $latest->recommendations;
                $rec = trim($rec);
                if ($rec !== '') {
                    $parts[] = 'Rec: '.mb_substr($rec, 0, 300);
                }
            }
        }

        return $parts === [] ? '' : implode(' | ', $parts);
    }
}
