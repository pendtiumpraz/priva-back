<?php

namespace App\Console\Commands;

use App\Models\Ropa;
use App\Models\SecurityAlert;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Scan masa retensi RoPA lalu kirim notifikasi (PP 33/2026 Pasal 80).
 *
 * Pasal 80: Pengendali Data Pribadi wajib MENGAKHIRI pemrosesan Data Pribadi
 * bila (a) Masa Retensi tercapai, (b) tujuan tercapai, atau (c) atas permintaan
 * Subjek Data. Sebelumnya `ropa.retention_due_date` dihitung tapi PASIF — hanya
 * dipakai kolom ekspor, tidak ada yang bertindak saat retensi lewat.
 *
 * Command terjadwal (harian) ini memindai RoPA yang:
 *   - punya retention_due_date,
 *   - status bukan draft (draft belum jadi pemrosesan berjalan),
 *   - jatuh tempo dalam <= N hari (default 30) ATAU sudah terlampaui (overdue),
 * lalu memberi tahu pemilik (created_by) atau fallback DPO/admin agar
 * pemrosesan diakhiri/diperbarui.
 *
 * Anti-spam: skip bila RoPA ini sudah dapat notifikasi retensi dalam 20 jam
 * terakhir (maksimal 1 reminder/hari per RoPA).
 */
class ScanRopaRetention extends Command
{
    protected $signature = 'notifications:scan-ropa-retention '
        .'{--days=30 : Ambang "akan jatuh tempo" dalam hari}';

    protected $description = 'Kirim notifikasi untuk RoPA yang masa retensinya akan/telah berakhir (PP 33 Pasal 80)';

    public function handle(): int
    {
        $window = (int) $this->option('days');
        if ($window < 1) {
            $window = 30;
        }
        $today = now()->startOfDay();

        // CLI tanpa CurrentOrgContext → BelongsToOrg scope no-op (lintas semua org).
        $ropas = Ropa::query()
            ->whereNotNull('retention_due_date')
            ->where('status', '!=', 'draft')
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($ropas as $ropa) {
            try {
                $dueDate = Carbon::parse($ropa->retention_due_date)->startOfDay();
            } catch (\Throwable $e) {
                continue;
            }

            // Signed: > 0 = sisa hari, 0 = hari ini, < 0 = terlampaui.
            $diff = $today->diffInDays($dueDate, false);
            if ($diff > $window) {
                continue; // belum masuk window reminder
            }

            // Anti-spam per RoPA.
            $recent = SecurityAlert::query()
                ->where('record_id', $ropa->id)
                ->where('type', 'like', 'ropa.retention%')
                ->where('created_at', '>=', now()->subHours(20))
                ->exists();
            if ($recent) {
                $skipped++;

                continue;
            }

            $overdue = $diff < 0;
            $overdueDays = $overdue ? abs($diff) : 0;
            $activity = (string) ($ropa->processing_activity ?: 'Aktivitas pemrosesan');
            $ropaNo = $ropa->registration_number ?: ($ropa->custom_number ?: $ropa->id);
            $recipient = ! empty($ropa->created_by)
                ? 'user:'.$ropa->created_by
                : 'role:dpo,admin';

            if ($overdue) {
                $title = "Masa retensi terlampaui: {$activity}";
                $body = "Aktivitas pemrosesan \"{$activity}\" (RoPA {$ropaNo}) telah melewati masa retensi "
                    .$overdueDays.' hari lalu. Sesuai PP 33/2026 Pasal 80, pemrosesan Data Pribadi wajib diakhiri '
                    .'(penghapusan/pemusnahan/anonimisasi) atau retensi diperbarui dengan dasar hukum yang sah. '
                    .'Segera perbarui status RoPA & tindak lanjuti pemusnahan datanya.';
                $kind = 'warning';
                $severity = 'high';
                $type = 'ropa.retention_overdue';
            } else {
                $when = $diff === 0 ? 'hari ini' : "dalam {$diff} hari";
                $title = "Masa retensi akan berakhir {$when}: {$activity}";
                $body = "Masa retensi aktivitas pemrosesan \"{$activity}\" (RoPA {$ropaNo}) berakhir {$when}. "
                    .'Siapkan pengakhiran pemrosesan (penghapusan/pemusnahan/anonimisasi) atau perpanjangan retensi '
                    .'dengan dasar hukum sesuai PP 33/2026 Pasal 80.';
                $kind = 'info';
                $severity = 'medium';
                $type = 'ropa.retention_due';
            }

            NotificationService::dispatch(
                kind: $kind,
                severity: $severity,
                module: 'ropa',
                type: $type,
                recipient: $recipient,
                orgId: $ropa->org_id,
                title: $title,
                body: $body,
                actionUrl: '/ropa',
                metadata: [
                    'record_id' => $ropa->id,
                    'retention_due_date' => (string) $ropa->retention_due_date,
                    'days_remaining' => $diff,
                    'overdue_days' => $overdueDays,
                ],
            );
            $sent++;
        }

        $this->info("RoPA retention scan selesai: {$sent} notifikasi, {$skipped} dilewati (anti-spam).");

        return self::SUCCESS;
    }
}
