<?php

namespace App\Console\Commands;

use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Manually-triggerable release announcement. Meant to be called from
 * CI/CD pipeline after a successful deploy, so every root account gets
 * a notification with the version, changelog, and which side (be/fe).
 *
 * Usage:
 *   php artisan notifications:announce-release --version=1.4.2 --side=backend --changelog=https://...
 */
class AnnounceRelease extends Command
{
    protected $signature = 'notifications:announce-release
        {--release= : release version tag, e.g. 1.4.2 (avoids Laravel --version flag clash)}
        {--side=backend : backend|frontend|both}
        {--changelog= : URL to changelog / release notes}
        {--highlights= : short one-line summary of what shipped}';

    protected $description = 'Announce a release to root users via notification';

    public function handle(): int
    {
        if (!NotificationService::isEnabled()) {
            $this->info('Notifications disabled — skip.');
            return self::SUCCESS;
        }

        $version = $this->option('release') ?: now()->format('Y.m.d-His');
        $side = $this->option('side') ?: 'backend';
        $changelog = $this->option('changelog');
        $highlights = $this->option('highlights') ?: 'Deploy berhasil.';

        $sideLabel = match($side) {
            'backend' => 'Backend',
            'frontend' => 'Frontend',
            'both' => 'Backend + Frontend',
            default => ucfirst($side),
        };

        NotificationService::dispatch(
            kind: 'info',
            severity: 'low',
            module: 'system',
            type: 'system.release',
            recipient: 'role:root',
            orgId: null,
            title: "🚀 Release {$sideLabel} {$version}",
            body: $highlights . ($changelog ? ' — lihat changelog untuk detail.' : ''),
            actionUrl: $changelog,
            metadata: [
                'version' => $version,
                'side' => $side,
                'changelog_url' => $changelog,
                'deployed_at' => now()->toIso8601String(),
            ]
        );

        $this->info("Release announcement sent to all root users (version {$version}, side {$side}).");
        return self::SUCCESS;
    }
}
