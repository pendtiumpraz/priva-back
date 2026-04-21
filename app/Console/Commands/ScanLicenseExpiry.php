<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily scan of tenant licenses; fires notifications to all superadmins
 * when a license enters a warning window (30/14/7/3/1 days / expired).
 *
 * Kind/severity escalate as the deadline nears — see the window map below.
 *
 * Each notification carries org admin contact + a wa.me deep-link so
 * superadmin can follow-up in one click, OR bulk-export the list via the
 * /notifications page for finance/sales.
 */
class ScanLicenseExpiry extends Command
{
    protected $signature = 'notifications:scan-license-expiry';
    protected $description = 'Scan licenses for upcoming expiry and notify superadmins';

    /** Days-out → [kind, severity, rule_key] */
    private const WINDOWS = [
        30 => ['info',    'low',      'license.expiring.30d'],
        14 => ['warning', 'medium',   'license.expiring.14d'],
        7  => ['warning', 'high',     'license.expiring.7d'],
        3  => ['alert',   'critical', 'license.expiring.3d'],
        1  => ['alert',   'critical', 'license.expiring.1d'],
        0  => ['alert',   'critical', 'license.expired'],
    ];

    public function handle(): int
    {
        // Honor root kill switches.
        if (!\App\Services\NotificationService::isEnabled() || !\App\Services\NotificationService::isSchedulerEnabled()) {
            $this->info('Notifications disabled by platform config — skip.');
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $count = 0;

        $licenses = License::whereNotNull('expires_at')->get();
        foreach ($licenses as $license) {
            $expiresAt = $license->trustedExpiresAt();
            if (!$expiresAt) continue;

            $daysLeft = (int) round($now->diffInDays($expiresAt, false));

            // Pick the window that matches today exactly — avoids firing
            // the same notification every day for 30 consecutive days.
            [$kind, $severity, $ruleKey] = match (true) {
                $daysLeft < 0 => self::WINDOWS[0],
                $daysLeft === 1 => self::WINDOWS[1],
                $daysLeft === 3 => self::WINDOWS[3],
                $daysLeft === 7 => self::WINDOWS[7],
                $daysLeft === 14 => self::WINDOWS[14],
                $daysLeft === 30 => self::WINDOWS[30],
                default => [null, null, null],
            };
            if (!$kind) continue;

            // Dedup: skip if we already fired this rule today for this license.
            $alreadyFired = \App\Models\SecurityAlert::where('type', $ruleKey)
                ->where('record_id', $license->id)
                ->whereDate('created_at', $now->toDateString())
                ->exists();
            if ($alreadyFired) continue;

            $org = $license->organization;
            $orgName = $org?->name ?? 'Unknown Org';

            $admin = User::where('org_id', $license->org_id)
                ->where('role', 'admin')
                ->orderBy('created_at')
                ->first();

            $waMessage = "Halo" . ($admin?->name ? " {$admin->name}" : '') .
                ", mengingatkan license Privasimu Nexus untuk {$orgName} " .
                ($daysLeft < 0
                    ? "telah berakhir pada {$expiresAt->format('d M Y')} (" . abs($daysLeft) . " hari lalu)."
                    : "akan berakhir pada {$expiresAt->format('d M Y')} ({$daysLeft} hari lagi).") .
                " Silakan hubungi kami untuk perpanjangan.";

            $waUrl = NotificationService::buildWaUrl($admin?->phone, $waMessage);

            $title = $daysLeft < 0
                ? "❌ License expired: {$orgName}"
                : "⏳ License {$orgName} · H-{$daysLeft}";

            $body = "Org: {$orgName} | Admin: " . ($admin?->name ?? '-') .
                " · " . ($admin?->email ?? '-') . " · " . ($admin?->phone ?? 'no WA');

            NotificationService::dispatch(
                kind: $kind,
                severity: $severity,
                module: 'license',
                type: $ruleKey,
                recipient: 'role:superadmin',
                orgId: null, // platform-level notification, not org-scoped
                title: $title,
                body: $body,
                actionUrl: $waUrl ?? ('/admin/tenants/' . $license->org_id),
                metadata: [
                    'license_id' => $license->id,
                    'record_id' => $license->id,
                    'org_id' => $license->org_id,
                    'org_name' => $orgName,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'days_left' => $daysLeft,
                    'admin_name' => $admin?->name,
                    'admin_email' => $admin?->email,
                    'admin_phone' => $admin?->phone,
                    'wa_url' => $waUrl,
                ]
            );

            $count++;
        }

        $this->info("License expiry scan done — {$count} notifications created.");
        return self::SUCCESS;
    }
}
