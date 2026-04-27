<?php

namespace App\Console\Commands;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use App\Models\CookieLog;
use App\Services\Consent\IpGeoResolver;
use App\Services\Consent\UserAgentParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-shot migration: move historical consent_logs rows whose collection.kind
 * is 'cookie_banner' into the new cookie_logs table. Runs idempotently — checks
 * for existing visitor_id+captured_at duplicates before insert.
 *
 *   php artisan consent:migrate-legacy-cookie-logs --batch=500
 *   php artisan consent:migrate-legacy-cookie-logs --dry
 */
class MigrateLegacyCookieLogsCommand extends Command
{
    protected $signature = 'consent:migrate-legacy-cookie-logs
                            {--batch=500 : Rows per batch}
                            {--dry : Count only, do not write}';

    protected $description = 'Move consent_logs rows from cookie_banner collections into cookie_logs';

    public function handle(): int
    {
        $batch = (int) $this->option('batch');
        $dry = (bool) $this->option('dry');

        $cookieCollectionIds = ConsentCollectionPoint::query()
            ->where('kind', ConsentCollectionPoint::KIND_COOKIE)
            ->pluck('id')
            ->all();

        if (empty($cookieCollectionIds)) {
            $this->info('No cookie_banner collections found — nothing to migrate.');
            return self::SUCCESS;
        }

        $total = ConsentLog::query()
            ->whereIn('collection_id', $cookieCollectionIds)
            ->count();

        if ($total === 0) {
            $this->info('No consent_logs rows tied to cookie_banner collections — nothing to migrate.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} consent_logs rows to migrate to cookie_logs.");
        if ($dry) {
            return self::SUCCESS;
        }

        $migrated = 0;
        $skipped = 0;

        ConsentLog::query()
            ->whereIn('collection_id', $cookieCollectionIds)
            ->orderBy('created_at')
            ->chunkById($batch, function ($rows) use (&$migrated, &$skipped) {
                foreach ($rows as $r) {
                    // Dedupe: same visitor + same created_at second already migrated
                    $exists = CookieLog::query()
                        ->where('org_id', $r->org_id)
                        ->where('visitor_id', $r->user_identifier)
                        ->where('captured_at', $r->created_at)
                        ->exists();
                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $ua = UserAgentParser::parse($r->user_agent);
                    $geo = IpGeoResolver::resolve($r->ip_address);
                    $choices = is_array($r->consented_items) ? $r->consented_items : [];

                    CookieLog::create([
                        'id' => (string) Str::uuid(),
                        'org_id' => $r->org_id,
                        'collection_id' => $r->collection_id,
                        'visitor_id' => $r->user_identifier ?: 'legacy-'.substr((string) $r->id, 0, 8),
                        'session_id' => null,
                        'ip_address' => $r->ip_address,
                        'ip_country' => $geo['country'],
                        'ip_city' => $geo['city'],
                        'user_agent' => $r->user_agent,
                        'browser_name' => $ua['browser_name'],
                        'browser_version' => $ua['browser_version'],
                        'os_name' => $ua['os_name'],
                        'device_type' => $ua['device_type'],
                        'choices' => $choices,
                        'policy_version' => $r->policy_version,
                        'captured_at' => $r->created_at,
                        'expires_at' => $r->created_at?->copy()->addDays((int) config('privasimu.cookie_log_retention_days', 90)),
                    ]);
                    $migrated++;
                }
            });

        $this->info("Migrated: {$migrated}");
        $this->info("Skipped (already migrated): {$skipped}");

        // Note: leaves originals in consent_logs intact (table has no deleted_at).
        // Admin can run hard cleanup later via:
        //   DELETE FROM consent_logs WHERE collection_id IN (cookie_banner ids);
        // after verifying cookie_logs is populated correctly.
        $this->info('Originals retained in consent_logs (no soft-delete column on that table).');
        $this->warn('To remove duplicates after verifying, manually run:');
        $this->line('  DELETE FROM consent_logs WHERE collection_id IN (...cookie_banner_ids...);');

        return self::SUCCESS;
    }
}
