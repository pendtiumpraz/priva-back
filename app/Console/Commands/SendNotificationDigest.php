<?php

namespace App\Console\Commands;

use App\Mail\NotificationDigestMail;
use App\Models\NotificationPreference;
use App\Models\SecurityAlert;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily / weekly digest sender.
 *
 * Iterates users who opted-in (any kind×module with channel=email +
 * digest ∈ {daily, weekly}), groups their unread notifications from the
 * relevant window, and sends one consolidated email per user.
 *
 * Run via:
 *   notifications:digest daily   (default — run daily at 08:00)
 *   notifications:digest weekly  (run on Monday 08:00)
 */
class SendNotificationDigest extends Command
{
    protected $signature = 'notifications:digest {frequency=daily : daily|weekly}';
    protected $description = 'Send notification digest email to users who opted for batched delivery';

    public function handle(): int
    {
        if (!NotificationService::isEnabled()) {
            $this->info('Notifications globally disabled — skip digest.');
            return self::SUCCESS;
        }

        $frequency = $this->argument('frequency');
        if (!in_array($frequency, ['daily', 'weekly'], true)) {
            $this->error('Invalid frequency. Use: daily|weekly');
            return self::FAILURE;
        }

        $windowStart = $frequency === 'weekly'
            ? Carbon::now()->subDays(7)
            : Carbon::now()->subDay();

        // Users who have at least one email preference with this digest value.
        $userIds = NotificationPreference::where('channel', 'email')
            ->where('digest', $frequency)
            ->where('enabled', true)
            ->distinct()
            ->pluck('user_id');

        $totalSent = 0;
        foreach ($userIds as $uid) {
            $user = User::find($uid);
            if (!$user || !$user->email) continue;

            // Collect user's notifications in the window that match enabled email prefs.
            $notifs = SecurityAlert::where(function ($q) use ($user) {
                    $q->where('recipient_id', $user->id)
                      ->orWhere(function ($inner) use ($user) {
                          $inner->where('recipient_role', $user->role)
                                ->where(function ($orgQ) use ($user) {
                                    $orgQ->where('org_id', $user->org_id)->orWhereNull('org_id');
                                });
                      });
                })
                ->where('created_at', '>=', $windowStart)
                ->orderBy('priority', 'desc')
                ->orderBy('created_at', 'desc')
                ->limit(100)
                ->get();

            // Filter to only those the user has email-enabled.
            $filtered = $notifs->filter(function ($n) use ($user) {
                return NotificationPreference::isEnabled(
                    $user->id,
                    $n->kind ?? 'alert',
                    $n->module ?? '*',
                    'email'
                );
            });

            if ($filtered->isEmpty()) continue;

            try {
                \Mail::to($user->email)->queue(
                    new NotificationDigestMail($filtered, $user->name ?? 'there', $frequency)
                );
                $totalSent++;
                $this->line("  sent to {$user->email} (" . $filtered->count() . ' items)');
            } catch (\Throwable $e) {
                $this->warn("  {$user->email}: ERROR — " . $e->getMessage());
            }
        }

        $this->info("Digest {$frequency} done. Sent {$totalSent} emails.");
        return self::SUCCESS;
    }
}
