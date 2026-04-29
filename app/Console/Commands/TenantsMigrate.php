<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\TenantDb\TenantDatabaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Run schema migrations against every isolated tenant DB. Use after a
 * deploy that adds new migrations — they need to be applied across all
 * tenant databases, not just landlord.
 *
 * Usage:
 *   php artisan tenants:migrate                    # run on every isolated tenant
 *   php artisan tenants:migrate --tenant=<org-id>  # one tenant only
 *   php artisan tenants:migrate --pretend          # show what would run
 *
 * Iterates serially. For large fleets we'd want parallel job dispatch;
 * for the FI launch (1-5 isolated tenants in flight) serial is fine and
 * makes failure handling simpler.
 */
class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate
        {--tenant= : Restrict to a single org id}
        {--pretend : Show queries without executing}';

    protected $description = 'Run pending migrations on every isolated tenant database';

    public function handle(TenantDatabaseService $dbService): int
    {
        $query = Organization::query()->where('tenant_db_state', 'isolated');
        if ($this->option('tenant')) {
            $query->where('id', $this->option('tenant'));
        }

        $orgs = $query->get();
        if ($orgs->isEmpty()) {
            $this->warn('No isolated tenants found.');
            return self::SUCCESS;
        }

        $this->info("Found {$orgs->count()} isolated tenant(s) to migrate.");

        $errors = 0;
        foreach ($orgs as $org) {
            $this->line("→ Migrating tenant {$org->id} ({$org->name})");

            $config = $dbService->decryptConfig($org->tenant_db_config);
            if (!$config) {
                $this->error("  ✗ failed to decrypt tenant_db_config — skipping");
                $errors++;
                continue;
            }

            $connectionName = "tenant_migrate_{$org->id}";
            Config::set("database.connections.{$connectionName}", $dbService->buildConnection($config['engine'] ?? 'pgsql', $config));
            DB::purge($connectionName);

            try {
                Artisan::call('migrate', [
                    '--database' => $connectionName,
                    '--force'    => true,
                    '--pretend'  => (bool) $this->option('pretend'),
                ], $this->output);
            } catch (\Throwable $e) {
                $this->error("  ✗ {$e->getMessage()}");
                $errors++;
            } finally {
                DB::purge($connectionName);
            }
        }

        if ($errors > 0) {
            $this->error("Completed with {$errors} error(s).");
            return self::FAILURE;
        }

        $this->info('All tenants migrated successfully.');
        return self::SUCCESS;
    }
}
