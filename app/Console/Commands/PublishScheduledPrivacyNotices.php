<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PrivacyNotice;
use App\Models\PrivacyNoticeVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Terbitkan versi pemberitahuan privasi yang sudah tiba waktunya.
 *
 * Berjalan di konteks artisan, sehingga CurrentOrgContext KOSONG dan global
 * scope `org` menjadi no-op — kueri di sini memang sengaja lintas tenant, dan
 * setiap penulisan tetap membawa org_id dari barisnya sendiri.
 */
class PublishScheduledPrivacyNotices extends Command
{
    protected $signature = 'privacy-notices:publish-scheduled {--dry-run : Tampilkan yang akan terbit tanpa menerbitkan}';

    protected $description = 'Terbitkan versi Privacy Notice yang penjadwalannya sudah jatuh tempo';

    public function handle(): int
    {
        $due = PrivacyNoticeVersion::query()
            ->where('status', PrivacyNoticeVersion::STATUS_SCHEDULED)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now())
            ->orderBy('publish_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info('Tidak ada versi yang jatuh tempo. ✓');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $published = 0;

        foreach ($due as $version) {
            $notice = PrivacyNotice::withoutGlobalScope('org')->find($version->privacy_notice_id);
            if (! $notice) {
                $this->warn("Versi {$version->id} menunjuk dokumen yang sudah tidak ada — dilewati.");

                continue;
            }

            $label = "{$notice->code} v{$version->version_number} (jadwal {$version->publish_at})";

            if ($dryRun) {
                $this->line("[dry-run] akan terbit: {$label}");

                continue;
            }

            DB::transaction(function () use ($notice, $version) {
                if ($notice->published_version_id && $notice->published_version_id !== $version->id) {
                    PrivacyNoticeVersion::withoutGlobalScope('org')
                        ->where('id', $notice->published_version_id)
                        ->update([
                            'status' => PrivacyNoticeVersion::STATUS_SUPERSEDED,
                            'superseded_at' => now(),
                        ]);
                }

                $version->update([
                    'status' => PrivacyNoticeVersion::STATUS_PUBLISHED,
                    'published_at' => now(),
                ]);

                $notice->update(['published_version_id' => $version->id]);
            });

            AuditLog::log('privacy-notice', $notice->id, 'version_published_scheduled', [
                'version_number' => $version->version_number,
                'scheduled_for' => $version->publish_at?->toIso8601String(),
            ], 'publish');

            $this->info("Terbit: {$label}");
            $published++;
        }

        $this->info($dryRun
            ? "Selesai (dry-run). {$due->count()} versi jatuh tempo."
            : "Selesai. {$published} versi diterbitkan.");

        return self::SUCCESS;
    }
}
