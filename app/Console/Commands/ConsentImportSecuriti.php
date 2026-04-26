<?php

namespace App\Console\Commands;

use App\Services\ConsentImporterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Bulk-import consent records from Securiti.ai CSV/JSON export.
 *
 * Usage sama dengan import-onetrust, beda di mapping schema.
 *
 * Securiti.ai CSV expected columns:
 *   consent_id, subject_id, timestamp, consent_status,
 *   preferences (JSON string), policy_version, channel, ip, user_agent
 *
 * Difference dari OneTrust:
 *   - "preferences" = JSON object (`{"marketing": true, "analytics": false}`)
 *   - "channel" = web/email/sms (kita capture sebagai user_agent prefix)
 */
class ConsentImportSecuriti extends Command
{
    protected $signature = 'consent:import-securiti
        {file : Path ke CSV export Securiti.ai}
        {--org= : Organization UUID}
        {--collection= : Target ConsentCollectionPoint UUID}
        {--purpose-map= : Path ke JSON map vendor key → Privasimu item_id}
        {--dry-run : Validate tanpa write}
        {--batch=1000 : Batch insert size}
        {--resume= : Session UUID untuk resume crash}
        {--default-version=1.0 : Fallback policy version}';

    protected $description = 'Bulk import consent dari Securiti.ai CSV export ke Privasimu';

    public function handle(ConsentImporterService $importer): int
    {
        $file = $this->argument('file');
        $orgId = $this->option('org');
        $cpId = $this->option('collection');
        $mapPath = $this->option('purpose-map');

        if (!$file || !File::exists($file)) { $this->error("File not found: $file"); return self::FAILURE; }
        if (!$orgId || !$cpId) { $this->error('--org dan --collection wajib'); return self::FAILURE; }
        if (!$mapPath || !File::exists($mapPath)) { $this->error('--purpose-map wajib'); return self::FAILURE; }

        $purposeMap = json_decode(File::get($mapPath), true);
        if (!is_array($purposeMap) || empty($purposeMap)) { $this->error('Purpose map invalid'); return self::FAILURE; }

        $this->info('Securiti.ai → Privasimu Consent Import');
        $this->info('  File         : ' . $file);
        $this->info('  Org          : ' . $orgId);
        $this->info('  Collection   : ' . $cpId);
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
            'vendor' => 'securiti',
        ];

        $bar = null;
        $progress = function ($stats) use (&$bar) {
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
                ['Total lines',  $result['total_lines']],
                ['Imported',     $result['imported']],
                ['Skipped',      $result['skipped']],
                ['Rejected',     $result['rejected']],
                ['Session ID',   $result['session_id']],
            ]
        );

        if (!empty($result['errors'])) {
            $this->newLine();
            $this->warn('First errors:');
            foreach (array_slice($result['errors'], 0, 5) as $err) $this->line('  - ' . $err);
            if (count($result['errors']) > 5) $this->line('  (+' . (count($result['errors']) - 5) . ' more)');
        }

        return self::SUCCESS;
    }

    public static function mapRow(array $row, array $config): ?array
    {
        $get = function (string ...$keys) use ($row) {
            foreach ($keys as $k) {
                foreach ($row as $rk => $rv) {
                    if (strtolower(trim($rk)) === strtolower($k)) return $rv;
                }
            }
            return null;
        };

        $userId = $get('subject_id', 'data_subject_id', 'user_id', 'email');
        if (!$userId) return null;

        $timestamp = $get('timestamp', 'created_at', 'consent_timestamp');
        $createdAt = $timestamp ? \Carbon\Carbon::parse($timestamp) : now();

        $status = $get('consent_status', 'status');
        $isWithdrawn = $status && in_array(strtolower($status), ['withdrawn', 'revoked', 'denied', 'rejected'], true);

        $consentedItems = [];
        if ($isWithdrawn) {
            foreach ($config['purpose_map'] as $key => $itemId) $consentedItems[$itemId] = false;
        } else {
            // Securiti: "preferences" = JSON object {"marketing": true, "analytics": false}
            $prefsRaw = $get('preferences', 'consents', 'consent_preferences');
            if (!$prefsRaw) return null;

            $prefs = is_string($prefsRaw) ? json_decode($prefsRaw, true) : (array) $prefsRaw;
            if (!is_array($prefs)) return null;

            foreach ($prefs as $key => $value) {
                $itemId = $config['purpose_map'][$key] ?? $config['purpose_map'][strtolower($key)] ?? null;
                if ($itemId) {
                    $consentedItems[$itemId] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
            }
            // Fill unmapped as false
            foreach ($config['purpose_map'] as $key => $itemId) {
                if (!isset($consentedItems[$itemId])) $consentedItems[$itemId] = false;
            }
        }

        $channel = $get('channel') ?: 'web';

        return [
            'user_identifier' => $userId,
            'consented_items' => $consentedItems,
            'policy_version' => $get('policy_version', 'version'),
            'ip_address' => $get('ip', 'ip_address'),
            'user_agent' => "imported:securiti:{$channel}" . ($get('user_agent') ? ' (' . substr((string) $get('user_agent'), 0, 200) . ')' : ''),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
