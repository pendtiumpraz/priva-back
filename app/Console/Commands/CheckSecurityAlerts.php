<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Organization;
use App\Services\AlertEngineService;

class CheckSecurityAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'security:check-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run the alert engine to generate security alerts for all organizations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Security Alert Engine Check...');

        // In a real multi-tenant scenario, we might want to chunk this
        $orgs = Organization::all();
        $totalAlerts = 0;

        foreach ($orgs as $org) {
            $this->line("Checking organization: {$org->id}");
            
            // 1. Alert Engine
            $alertService = new AlertEngineService();
            $alerts = $alertService->runAllRules($org->id);
            $totalAlerts += count($alerts);

            // 2. Automation Engine (Action execution based on rules)
            $automationService = new \App\Services\AutomationEngineService();
            $automationService->runAutomations($org->id);
        }

        $this->info("Completed. {$totalAlerts} new alerts generated across " . count($orgs) . " organizations.");
    }
}
