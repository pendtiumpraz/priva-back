<?php

namespace App\Console\Commands;

use App\Services\Consent\LayananPeralihan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Antrean peralihan anak → dewasa — PP 33/2026 Pasal 38 ayat (8).
 *
 * Dijalankan harian (routes/console.php). Untuk tiap anak yang tanggal
 * genap 18-nya sudah lewat dan belum disentuh: kewenangan wali dicabut,
 * keadaan menjadi menunggu konfirmasi, dan tautan keputusan dikirim ke kanal
 * MILIK subjek bila ada. Yang tidak punya kanal masuk antrean kerja
 * pengendali (terlihat di /consent/guardian) — tidak dicabut otomatis.
 */
class PeralihanAnakDewasaCommand extends Command
{
    protected $signature = 'consent:peralihan-anak
        {--dry-run : Hanya menghitung yang akan diproses, tidak mengubah apa pun}
        {--pada= : Tanggal acuan (Y-m-d); bawaan hari ini}';

    protected $description = 'Antrean peralihan anak → dewasa (PP 33/2026 Pasal 38 ayat 8): cabut kewenangan wali, tandai menunggu konfirmasi, kirim tautan keputusan ke kanal milik subjek.';

    public function handle(LayananPeralihan $layanan): int
    {
        $pada = $this->option('pada') ? Carbon::parse((string) $this->option('pada')) : now();
        $dryRun = (bool) $this->option('dry-run');

        $hasil = $layanan->jalankan($pada, $dryRun);

        $this->info(sprintf(
            '%sDiproses %d · tautan terkirim %d · tanpa kanal (antrean kerja) %d — acuan %s',
            $dryRun ? '[dry-run] ' : '',
            $hasil['diproses'],
            $hasil['terkirim'],
            $hasil['tanpa_kanal'],
            $pada->toDateString(),
        ));

        return self::SUCCESS;
    }
}
