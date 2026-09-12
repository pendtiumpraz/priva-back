<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Anonimkan data pribadi pengguna yang sudah dihapus melewati masa tenggang.
 *
 * UU PDP Pasal 43 & 44: data pribadi wajib dimusnahkan bila tidak lagi
 * diperlukan. Sebelum ini, menghapus pengguna hanya soft delete — nama, surel,
 * dan telepon bertahan selamanya di basis data.
 *
 * Masa tenggang ada karena `UserController::restore()` memang menyediakan
 * pemulihan: menghapus akun karena salah klik harus bisa dibatalkan. Sesudah
 * tenggang lewat, niat menghapusnya dianggap sungguh-sungguh dan datanya
 * dimusnahkan — anonimisasi bersifat SATU ARAH.
 *
 * Polanya sengaja meniru tenants:cleanup-archived yang sudah ada (arsip →
 * tenggang → pemusnahan permanen + jejak audit), supaya di basis kode ini cuma
 * ada satu cara memikirkan retensi.
 *
 *   php artisan users:anonymize-deleted [--days=30] [--dry-run]
 */
class AnonymizeDeletedUsers extends Command
{
    protected $signature = 'users:anonymize-deleted '
        .'{--days=30 : Masa tenggang sejak dihapus, dalam hari} '
        .'{--dry-run : Laporkan tanpa mengubah apa pun}';

    protected $description = 'Anonimkan data pribadi pengguna terhapus yang melewati masa tenggang (UU PDP Pasal 43 & 44)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $days = 30;
        }
        $dryRun = (bool) $this->option('dry-run');
        $batas = now()->subDays($days);

        // Hanya yang SUDAH dihapus, sudah lewat tenggang, dan belum dianonimkan.
        $kandidat = User::onlyTrashed()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<=', $batas)
            ->whereNull('anonymized_at')
            ->get();

        if ($kandidat->isEmpty()) {
            $this->info('Tidak ada pengguna yang perlu dianonimkan.');

            return self::SUCCESS;
        }

        $jumlah = 0;
        foreach ($kandidat as $user) {
            if ($dryRun) {
                $this->line("[dry-run] akan dianonimkan: {$user->id} (dihapus {$user->deleted_at})");
                $jumlah++;

                continue;
            }

            $orgId = $user->org_id;
            $dihapusPada = optional($user->deleted_at)->toIso8601String();

            $user->anonymize();

            try {
                // Jejaknya TIDAK memuat nama atau surel — mencatat data yang
                // baru saja dimusnahkan akan membatalkan gunanya pemusnahan.
                AuditLog::create([
                    'module' => 'users',
                    'record_id' => $user->id,
                    'action' => 'anonymized',
                    'user_name' => 'Sistem',
                    'user_role' => 'system',
                    'section' => 'retensi',
                    'changes' => [
                        'org_id' => $orgId,
                        'deleted_at' => $dihapusPada,
                        'grace_days' => $days,
                        'basis' => 'UU PDP Pasal 43 & 44',
                    ],
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Audit log anonimisasi pengguna gagal: '.$e->getMessage());
            }

            $jumlah++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '').$jumlah.' pengguna diproses.');

        return self::SUCCESS;
    }
}
