<?php

namespace App\Console\Commands;

use App\Services\ConsentImporterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Bulk-import consent records from OneTrust CSV export.
 *
 * Usage:
 *   php artisan consent:import-onetrust /path/to/onetrust-export.csv \
 *     --collection=COLLECTION_UUID \
 *     --org=ORG_UUID \
 *     --purpose-map=/path/to/map.json \
 *     [--dry-run] [--batch=1000] [--resume=SESSION_ID]
 *
 * OneTrust CSV expected columns (typical export):
 *   ConsentReceiptId, DataSubjectIdentifier, ConsentTimestamp, PolicyVersion,
 *   Purposes, IPAddress, UserAgent, Status
 *
 * Purpose map JSON example:
 *   {
 *     "Marketing": "uuid-of-marketing-item-in-privasimu",
 *     "Analytics": "uuid-of-analytics-item",
 *     "Functional": "uuid-of-functional-item"
 *   }
 *
 * Safety:
 *   - --dry-run prints stats tanpa write apa-apa
 *   - Idempotent via checkpoint — bisa di-resume kalau crash
 *   - Per-row error logged, gak block import lainnya
 *   - Max 100 errors di response, sisanya di laravel.log
 */
class ConsentImportOneTrust extends Command
{
    protected $signature = 'consent:import-onetrust
        {file : Path ke CSV export OneTrust}
        {--org= : Organization UUID (tenant)}
        {--collection= : Target ConsentCollectionPoint UUID}
        {--purpose-map= : Path ke JSON map vendor purpose → Privasimu item_id}
        {--dry-run : Validate tanpa write}
        {--batch=1000 : Batch insert size}
        {--resume= : Session UUID untuk resume crash}
        {--default-version=1.0 : Fallback policy version kalau row kosong}';

    protected $description = 'Bulk import consent records dari OneTrust CSV export ke Privasimu';

    public function handle(ConsentImporterService $importer): int
    {
        $file = $this->argument('file');
        $orgId = $this->option('org');
        $cpId = $this->option('collection');
        $mapPath = $this->option('purpose-map');

        if (!$file || !File::exists($file)) {
            $this->error('File tidak ditemukan: ' . $file);
            return self::FAILURE;
        }
        if (!$orgId || !$cpId) {
            $this->error('--org dan --collection wajib');
            return self::FAILURE;
        }
        if (!$mapPath || !File::exists($mapPath)) {
            $this->error('--purpose-map wajib (file JSON)');
            return self::FAILURE;
        }

        $purposeMap = json_decode(File::get($mapPath), true);
        if (!is_array($purposeMap) || empty($purposeMap)) {
            $this->error('Purpose map JSON kosong/invalid');
            return self::FAILURE;
        }

        $this->info('OneTrust → Privasimu Consent Import');
        $this->info('  File         : ' . $file);
        $this->info('  Org          : ' . $orgId);
        $this->info('  Collection   : ' . $cpId);
        $this->info('  Purpose map  : ' . count($purposeMap) . ' purposes mapped');
        $this->info('  Mode         : ' . ($this->option('dry-run') ? 'DRY RUN' : 'LIVE'));
        if ($this->option('resume')) $this->info('  Resume       : ' . $this->option('resume'));
        $this->newLine();

        $config = [
            'org_id' => $orgId,
            'collection_id' => $cpId,
            'purpose_map' => $purposeMap,
            'dry_run' => (bool) $this->option('dry-run'),
            'batch_size' => (int) $this->option('batch'),
            'resume_session' => $this->option('resume'),
            'default_policy_version' => $this->option('default-version'),
            'vendor' => 'onetrust',
        ];

        $bar = null;
        $progress = function ($stats, $line) use (&$bar) {
            if (!$bar) { $bar = $this->output->createProgressBar($stats['total_lines']); $bar->start(); }
            $bar->setProgress($stats['imported'] + $stats['skipped'] + $stats['rejected']);
        };

        $startTime = microtime(true);
        $result = $importer->import($file, $config, [self::class, 'mapRow'], $progress);
        if ($bar) { $bar->finish(); $this->newLine(2); }
        $elapsed = round(microtime(true) - $startTime, 1);

        $this->info("=== Import Result (took {$elapsed}s) ===");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total lines',      $result['total_lines']],
                ['Imported',         $result['imported']],
                ['Skipped (no map)', $result['skipped']],
                ['Rejected',         $result['rejected']],
                ['Session ID',       $result['session_id']],
            ]
        );

        if (!empty($result['errors'])) {
            $this->newLine();
            $this->warn('First errors:');
            foreach (array_slice($result['errors'], 0, 5) as $err) {
                $this->line('  - ' . $err);
            }
            if (count($result['errors']) > 5) {
                $this->line('  (+' . (count($result['errors']) - 5) . ' more — see laravel.log)');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Map OneTrust CSV row → Privasimu consent_log shape.
     * Returns null if row should be skipped.
     */
    public static function mapRow(array $row, array $config): ?array
    {
        // OneTrust field names — case-insensitive lookup
        $get = function (string ...$keys) use ($row) {
            foreach ($keys as $k) {
                foreach ($row as $rk => $rv) {
                    if (strtolower(trim($rk)) === strtolower($k)) return $rv;
                }
            }
            return null;
        };

        $userId = $get('DataSubjectIdentifier', 'DataSubjectId', 'SubjectIdentifier', 'UserId', 'Email');
        if (!$userId) return null;

        $timestamp = $get('ConsentTimestamp', 'Timestamp', 'CreatedAt');
        $createdAt = $timestamp ? \Carbon\Carbon::parse($timestamp) : now();

        $status = $get('Status', 'ConsentStatus');
        $isWithdrawn = $status && in_array(strtolower($status), ['withdrawn', 'revoked', 'denied'], true);

        // Parse "Purposes" — OneTrust format: "Marketing:granted;Analytics:denied;Functional:granted"
        $purposesRaw = $get('Purposes', 'Categories', 'ConsentedItems');
        $consentedItems = [];

        if ($isWithdrawn) {
            // All map values → false (consent withdrawn)
            foreach ($config['purpose_map'] as $purpose => $itemId) {
                $consentedItems[$itemId] = false;
            }
        } elseif ($purposesRaw) {
            // Try semicolon-separated "Name:status" first
            $pairs = preg_split('/[;,]/', $purposesRaw);
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                if (strpos($pair, ':') !== false) {
                    [$name, $stateRaw] = explode(':', $pair, 2);
                    $name = trim($name);
                    $state = strtolower(trim($stateRaw));
                    $itemId = $config['purpose_map'][$name] ?? null;
                    if ($itemId) {
                        $consentedItems[$itemId] = in_array($state, ['granted', 'true', 'yes', '1', 'opt-in'], true);
                    }
                } else {
                    // Just purpose name → assume granted
                    $itemId = $config['purpose_map'][$pair] ?? null;
                    if ($itemId) $consentedItems[$itemId] = true;
                }
            }
            // Fill unmapped purposes as false (default deny)
            foreach ($config['purpose_map'] as $purpose => $itemId) {
                if (!isset($consentedItems[$itemId])) $consentedItems[$itemId] = false;
            }
        } else {
            return null; // No purpose data → skip
        }

        return [
            'user_identifier' => $userId,
            'consented_items' => $consentedItems,
            'policy_version' => $get('PolicyVersion', 'Version'),
            'ip_address' => $get('IPAddress', 'IP'),
            'user_agent' => 'imported:onetrust' . ($get('UserAgent') ? ' (' . substr((string) $get('UserAgent'), 0, 200) . ')' : ''),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
