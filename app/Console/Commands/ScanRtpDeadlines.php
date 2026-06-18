<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Dpia;
use App\Models\SecurityAlert;
use App\Models\User;
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
    protected $signature = 'notifications:scan-rtp-deadlines '
        .'{--days=7 : Ambang "due soon" dalam hari} '
        .'{--escalate-after=7 : Eskalasi ke atasan setelah overdue lebih dari N hari}';

    protected $description = 'Kirim notifikasi untuk RTP item yang jatuh tempo / terlambat';

    public function handle(): int
    {
        $window = (int) $this->option('days');
        if ($window < 1) {
            $window = 7;
        }
        $escalateAfter = (int) $this->option('escalate-after');
        if ($escalateAfter < 1) {
            $escalateAfter = 7;
        }
        $today = now()->startOfDay();

        // CLI tanpa CurrentOrgContext → BelongsToOrg scope no-op (lintas semua org).
        $dpias = Dpia::query()->whereNotNull('mitigation_tracking')->get();

        $sent = 0;
        $skipped = 0;
        $escalated = 0;

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

                $overdue = $diff < 0;
                $overdueDays = $overdue ? abs($diff) : 0;
                $riskEvent = (string) ($item['risk_event'] ?? 'Tindakan mitigasi');
                $dpiaNo = $dpia->registration_number ?? $dpia->custom_number ?? $dpia->id;

                // ---- 1) Reminder rutin ke owner (anti-spam 20 jam) ----
                $recent = SecurityAlert::query()
                    ->where('record_id', $itemId)
                    ->where('type', 'like', 'rtp.deadline%')
                    ->where('created_at', '>=', now()->subHours(20))
                    ->exists();

                if ($recent) {
                    $skipped++;
                } else {
                    $recipient = ! empty($item['owner_user_id'])
                        ? 'user:'.$item['owner_user_id']
                        : 'role:dpo,admin';

                    if ($overdue) {
                        $title = "RTP terlambat: {$riskEvent}";
                        $body = "Tindakan mitigasi untuk \"{$riskEvent}\" (DPIA {$dpiaNo}) sudah melewati tenggat "
                            .$overdueDays." hari lalu dan belum diverifikasi. Segera tindak lanjuti & unggah bukti mitigasi.";
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

                // ---- 2) ESKALASI ke atasan kalau overdue > ambang (default 7 hari) ----
                if ($overdueDays > $escalateAfter) {
                    $escRecent = SecurityAlert::query()
                        ->where('record_id', $itemId)
                        ->where('type', 'rtp.escalation')
                        ->where('created_at', '>=', now()->subHours(20))
                        ->exists();

                    if (! $escRecent) {
                        [$escRecipient, $ownerName] = $this->resolveEscalationTarget($item);
                        $ownerLabel = $ownerName ? " (PIC: {$ownerName})" : '';

                        NotificationService::dispatch(
                            kind: 'warning',
                            severity: 'critical',
                            module: 'rtp',
                            type: 'rtp.escalation',
                            recipient: $escRecipient,
                            orgId: $dpia->org_id,
                            title: "Eskalasi: RTP terlambat {$overdueDays} hari — {$riskEvent}",
                            body: "Tindakan mitigasi \"{$riskEvent}\" (DPIA {$dpiaNo}){$ownerLabel} sudah terlambat {$overdueDays} hari "
                                .'(>7 hari) dan belum diverifikasi. Mohon tinjau & dorong penyelesaian sebagai atasan/penanggung jawab.',
                            actionUrl: '/risk-treatment-plan?dpia_id='.$dpia->id,
                            metadata: [
                                'record_id' => $itemId,
                                'dpia_id' => $dpia->id,
                                'due_date' => $due,
                                'overdue_days' => $overdueDays,
                                'escalation' => true,
                                'priority' => $item['priority'] ?? null,
                            ],
                        );
                        $escalated++;
                    }
                }
            }
        }

        $this->info("RTP deadline scan selesai: {$sent} reminder, {$escalated} eskalasi, {$skipped} dilewati (anti-spam).");

        return self::SUCCESS;
    }

    /**
     * Tentukan penerima eskalasi (atasan) + nama owner.
     * Prioritas: kepala departemen owner → kepala departemen induk (1 tingkat ke
     * atas) → fallback DPO/admin tenant. Hindari mengeskalasi ke owner sendiri.
     *
     * @return array{0:string,1:?string} [recipientSpec, ownerName]
     */
    private function resolveEscalationTarget(array $item): array
    {
        $ownerId = $item['owner_user_id'] ?? null;
        $owner = $ownerId ? User::find($ownerId) : null;
        $ownerName = $owner?->name;

        if ($owner && $owner->department_id) {
            $dept = Department::find($owner->department_id);
            if ($dept) {
                if (! empty($dept->head_user_id) && $dept->head_user_id !== $owner->id) {
                    return ['user:'.$dept->head_user_id, $ownerName];
                }
                // Owner adalah kepala departemen (atau tak ada head) → naik 1 tingkat.
                if (! empty($dept->parent_id)) {
                    $parent = Department::find($dept->parent_id);
                    if ($parent && ! empty($parent->head_user_id) && $parent->head_user_id !== $owner->id) {
                        return ['user:'.$parent->head_user_id, $ownerName];
                    }
                }
            }
        }

        // Fallback: manajemen tenant (DPO + admin) untuk visibilitas eskalasi.
        return ['role:dpo,admin', $ownerName];
    }
}
