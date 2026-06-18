<?php

namespace App\Console\Commands;

use App\Models\Dpia;
use App\Models\SecurityAlert;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Scan deadline Risk Treatment Plan (RTP) lalu kirim notifikasi.
 *
 * RTP item disimpan sebagai JSON `mitigation_tracking` di tiap DPIA. Command ini
 * (terjadwal harian) memindai semua item yang:
 *   - punya due_date,
 *   - status bukan terminal/pause (verified/cancelled/on_hold),
 *   - jatuh tempo dalam <= N hari (default 7) ATAU sudah lewat (overdue),
 * lalu notify owner item (atau fallback DPO/admin tenant).
 *
 * Anti-spam: skip kalau item ini sudah dapat notifikasi deadline dalam 20 jam
 * terakhir (efektif maksimal 1 reminder/hari per item).
 */
class ScanRtpDeadlines extends Command
{
    protected $signature = 'notifications:scan-rtp-deadlines {--days=7 : Ambang "due soon" dalam hari}';

    protected $description = 'Kirim notifikasi untuk RTP item yang jatuh tempo / terlambat';

    public function handle(): int
    {
        $window = (int) $this->option('days');
        if ($window < 1) {
            $window = 7;
        }
        $today = now()->startOfDay();

        // CLI tanpa CurrentOrgContext → BelongsToOrg scope no-op (lintas semua org).
        $dpias = Dpia::query()->whereNotNull('mitigation_tracking')->get();

        $sent = 0;
        $skipped = 0;

        foreach ($dpias as $dpia) {
            $items = is_array($dpia->mitigation_tracking) ? $dpia->mitigation_tracking : [];
            foreach ($items as $item) {
                $due = $item['due_date'] ?? null;
                $status = $item['status'] ?? 'planned';
                $itemId = $item['id'] ?? null;

                if (! $due || ! $itemId) {
                    continue;
                }
                if (in_array($status, ['verified', 'cancelled', 'on_hold'], true)) {
                    continue;
                }

                try {
                    $dueDate = Carbon::parse($due)->startOfDay();
                } catch (\Throwable $e) {
                    continue;
                }

                // Signed: > 0 = sisa hari, 0 = hari ini, < 0 = telat.
                $diff = $today->diffInDays($dueDate, false);
                if ($diff > $window) {
                    continue; // belum masuk window reminder
                }

                // Anti-spam: sudah dinotifikasi dalam 20 jam terakhir?
                $recent = SecurityAlert::query()
                    ->where('record_id', $itemId)
                    ->where('type', 'like', 'rtp.deadline%')
                    ->where('created_at', '>=', now()->subHours(20))
                    ->exists();
                if ($recent) {
                    $skipped++;
                    continue;
                }

                $overdue = $diff < 0;
                $recipient = ! empty($item['owner_user_id'])
                    ? 'user:'.$item['owner_user_id']
                    : 'role:dpo,admin';

                $riskEvent = (string) ($item['risk_event'] ?? 'Tindakan mitigasi');
                $dpiaNo = $dpia->registration_number ?? $dpia->custom_number ?? $dpia->id;

                if ($overdue) {
                    $title = "RTP terlambat: {$riskEvent}";
                    $body = "Tindakan mitigasi untuk \"{$riskEvent}\" (DPIA {$dpiaNo}) sudah melewati tenggat "
                        .abs($diff)." hari lalu dan belum diverifikasi. Segera tindak lanjuti & unggah bukti mitigasi.";
                    $kind = 'warning';
                    $severity = 'high';
                    $type = 'rtp.deadline_overdue';
                } else {
                    $when = $diff === 0 ? 'hari ini' : "dalam {$diff} hari";
                    $title = "RTP jatuh tempo {$when}: {$riskEvent}";
                    $body = "Tindakan mitigasi untuk \"{$riskEvent}\" (DPIA {$dpiaNo}) jatuh tempo {$when}. "
                        ."Lengkapi pelaksanaan & unggah bukti mitigasi sebelum tenggat.";
                    $kind = 'info';
                    $severity = 'medium';
                    $type = 'rtp.deadline_due';
                }

                NotificationService::dispatch(
                    kind: $kind,
                    severity: $severity,
                    module: 'rtp',
                    type: $type,
                    recipient: $recipient,
                    orgId: $dpia->org_id,
                    title: $title,
                    body: $body,
                    actionUrl: '/risk-treatment-plan?dpia_id='.$dpia->id,
                    metadata: [
                        'record_id' => $itemId,
                        'dpia_id' => $dpia->id,
                        'due_date' => $due,
                        'days_remaining' => $diff,
                        'priority' => $item['priority'] ?? null,
                    ],
                );
                $sent++;
            }
        }

        $this->info("RTP deadline scan selesai: {$sent} notifikasi terkirim, {$skipped} dilewati (anti-spam).");

        return self::SUCCESS;
    }
}
