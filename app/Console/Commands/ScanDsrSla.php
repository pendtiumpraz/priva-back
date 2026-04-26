<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\DsrRequest;
use App\Services\DsrEventBroadcaster;
use Illuminate\Console\Command;

/**
 * Hourly SLA scan for DSRs.
 *
 *  - "warning" tier:  deadline within next 24h / 12h / 1h (one-shot per tier)
 *  - "breach" tier:   deadline already passed AND status not in terminal states
 *
 * One-shot tracking via metadata flags stamped onto the DSR's audit log
 * (sla_warned_24h, sla_warned_12h, sla_warned_1h, sla_breached). Cheaper than
 * adding columns; queryable enough for periodic dedupe.
 *
 * Run via:
 *   dsr:scan-sla
 * Schedule: hourly (registered in routes/console.php).
 */
class ScanDsrSla extends Command
{
    protected $signature = 'dsr:scan-sla {--dry-run : Print findings without dispatching events}';
    protected $description = 'Scan DSR records for SLA warnings + breaches and broadcast events';

    private const TERMINAL_STATUSES = ['completed', 'rejected', 'cancelled', 'closed'];

    private const WARNING_TIERS = [
        '24h' => 24,
        '12h' => 12,
        '1h' => 1,
    ];

    public function handle(DsrEventBroadcaster $broadcaster): int
    {
        $now = now();
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['warned' => 0, 'breached' => 0, 'skipped' => 0];

        // Load all active DSRs with a deadline. SLA-eligible window: deadline
        // within next 24h or already past + still active.
        $window = $now->copy()->addHours(24);
        $active = DsrRequest::whereNotNull('deadline_at')
            ->whereNotIn('status', self::TERMINAL_STATUSES)
            ->where('deadline_at', '<=', $window)
            ->get();

        $this->info("Scanning {$active->count()} candidate DSR(s)…");

        foreach ($active as $dsr) {
            $hoursLeft = $now->diffInHours($dsr->deadline_at, false); // negative if past

            // Past deadline → breach (one-shot)
            if ($hoursLeft < 0) {
                if ($this->wasFlagged($dsr, 'sla_breached')) {
                    $stats['skipped']++;
                    continue;
                }
                if (!$dryRun) {
                    $broadcaster->emit(DsrEventBroadcaster::EVENT_SLA_BREACH, $dsr, [
                        'hours_overdue' => abs($hoursLeft),
                    ]);
                    $this->stampFlag($dsr, 'sla_breached');
                }
                $stats['breached']++;
                $this->line("  🚨 BREACH {$dsr->request_id} (deadline " . $dsr->deadline_at->toIso8601String() . ")");
                continue;
            }

            // Approaching: pick tightest tier still applicable.
            foreach (self::WARNING_TIERS as $label => $tierHours) {
                if ($hoursLeft <= $tierHours) {
                    $flag = "sla_warned_{$label}";
                    if ($this->wasFlagged($dsr, $flag)) {
                        $stats['skipped']++;
                        break;
                    }
                    if (!$dryRun) {
                        $broadcaster->emit(DsrEventBroadcaster::EVENT_SLA_WARNING, $dsr, [
                            'tier' => $label,
                            'hours_left' => $hoursLeft,
                        ]);
                        $this->stampFlag($dsr, $flag);
                    }
                    $stats['warned']++;
                    $this->line("  ⚠ WARN {$dsr->request_id} ({$label}, {$hoursLeft}h left)");
                    break; // one tier per scan run
                }
            }
        }

        $this->newLine();
        $this->info("Done. warned={$stats['warned']} breached={$stats['breached']} skipped={$stats['skipped']}"
            . ($dryRun ? ' [DRY RUN]' : ''));

        return self::SUCCESS;
    }

    private function wasFlagged(DsrRequest $dsr, string $flag): bool
    {
        return AuditLog::where('module', 'dsr')
            ->where('record_id', $dsr->id)
            ->where('action', "dsr.{$flag}")
            ->exists();
    }

    private function stampFlag(DsrRequest $dsr, string $flag): void
    {
        AuditLog::create([
            'org_id' => $dsr->org_id,
            'user_id' => null,
            'module' => 'dsr',
            'record_id' => $dsr->id,
            'action' => "dsr.{$flag}",
            'details' => [
                'deadline_at' => optional($dsr->deadline_at)->toIso8601String(),
                'scanned_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
