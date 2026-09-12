<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Vendor;
use App\Services\NotificationService;
use App\Services\VendorScreening\VendorScreeningService;
use Illuminate\Console\Command;

/**
 * Pemantauan kabar buruk pihak ketiga dari internet, berkala.
 *
 * Screening manual hanya berjalan saat seseorang menekan tombol. Padahal
 * pertanyaan "apakah pihak ketiga kita pernah bocor datanya" baru berguna bila
 * ditanyakan terus-menerus. Perintah ini menelusuri pihak ketiga yang sedang
 * dipakai (aktif / sedang onboarding) dan menyimpan hasilnya sebagai riwayat
 * screening; kenaikan risiko otomatis memberi tahu admin lewat
 * VendorScreeningService::notifyIfRiskIncreased().
 *
 * Batas per organisasi menjaga agar satu jadwal tidak menembak ratusan kueri
 * sekaligus; yang paling lama tidak ditengok dikerjakan lebih dulu.
 */
class ScanVendorAdverseMedia extends Command
{
    protected $signature = 'tprm:scan-adverse-media {--limit=25 : maksimal pihak ketiga per organisasi}';

    protected $description = 'Telusuri kabar insiden, sanksi, atau gugatan pihak ketiga yang sedang dipakai';

    public function handle(VendorScreeningService $screening): int
    {
        if (! NotificationService::isEnabled() || ! NotificationService::isSchedulerEnabled()) {
            $this->info('Notifikasi atau penjadwal dimatikan root — dilewati.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $total = 0;

        foreach (Organization::whereNull('deleted_at')->get() as $org) {
            if (! NotificationService::isEnabled($org->id)) {
                $this->line("  lewati {$org->name} — notifikasi dimatikan tenant");

                continue;
            }

            $vendors = Vendor::where('org_id', $org->id)
                ->whereIn('lifecycle_status', [Vendor::LIFECYCLE_ACTIVE, Vendor::LIFECYCLE_ONBOARDING])
                ->orderByRaw('last_assessed_at is null desc, last_assessed_at asc')
                ->limit($limit)
                ->get();

            foreach ($vendors as $vendor) {
                try {
                    $screening->run($vendor, ['adverse_media']);
                    $total++;
                } catch (\Throwable $e) {
                    $this->warn("  {$org->name} / {$vendor->name}: GAGAL — ".$e->getMessage());
                }
            }

            $this->line("  {$org->name}: {$vendors->count()} pihak ketiga ditelusuri");
        }

        $this->info("Selesai. {$total} penelusuran dijalankan.");

        return self::SUCCESS;
    }
}
