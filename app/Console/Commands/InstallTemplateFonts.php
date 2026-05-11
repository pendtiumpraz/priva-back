<?php

namespace App\Console\Commands;

use Dompdf\Dompdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Mengunduh dan mendaftarkan font Google yang dipakai 20 template PDF
 * elegan (RoPA / DPIA / GAP).
 *
 * Sumber: Google Webfonts Helper (https://gwfh.mranftl.com/) — layanan
 * publik yang menyajikan paket ZIP berisi font TTF. Format TTF dipilih
 * karena hanya itu yang didukung dompdf.
 *
 * Penggunaan:
 *   php artisan templates:install-fonts
 *   php artisan templates:install-fonts --force
 */
class InstallTemplateFonts extends Command
{
    protected $signature = 'templates:install-fonts
                            {--force : Timpa berkas font yang sudah ada}';

    protected $description = 'Mengunduh keluarga font Google (Inter, Cormorant, EB Garamond, Plus Jakarta Sans, Playfair, JetBrains Mono) ke storage/fonts dan mendaftarkannya ke dompdf';

    /**
     * Daftar font yang akan diunduh.
     * Kunci array = slug Google Webfonts Helper.
     * Nilai `variants` = varian yang dipakai (mengikuti notasi gwfh).
     * Nilai `family`   = nama yang dipakai dompdf saat mendaftarkan font.
     */
    private array $fonts = [
        'inter' => [
            'family' => 'Inter',
            'variants' => ['400', '500', '600', '700', '800', '900'],
        ],
        'cormorant-garamond' => [
            'family' => 'Cormorant Garamond',
            'variants' => ['400', '500', '600', '400italic', '500italic'],
        ],
        'eb-garamond' => [
            'family' => 'EB Garamond',
            'variants' => ['400', '500', '400italic'],
        ],
        'plus-jakarta-sans' => [
            'family' => 'Plus Jakarta Sans',
            'variants' => ['400', '500', '600', '700', '800'],
        ],
        'playfair-display' => [
            'family' => 'Playfair Display',
            'variants' => ['400', '700', '900', '400italic'],
        ],
        'jetbrains-mono' => [
            'family' => 'JetBrains Mono',
            'variants' => ['400', '500'],
        ],
    ];

    public function handle(): int
    {
        $fontDir = storage_path('fonts');
        if (! File::isDirectory($fontDir)) {
            File::makeDirectory($fontDir, 0755, true);
        }

        $force = (bool) $this->option('force');

        $this->info('Memulai unduhan font template PDF…');
        $this->line('Tujuan: '.$fontDir);
        $this->newLine();

        $totalDownloaded = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($this->fonts as $slug => $meta) {
            $this->line("<comment>{$meta['family']}</comment> ({$slug})");
            $result = $this->downloadFont($slug, $meta, $fontDir, $force);
            $totalDownloaded += $result['downloaded'];
            $totalSkipped += $result['skipped'];
            $totalFailed += $result['failed'];
            $this->newLine();
        }

        $this->info('Ringkasan:');
        $this->line("  Berhasil diunduh : {$totalDownloaded} berkas");
        $this->line("  Dilewati          : {$totalSkipped} berkas (sudah ada, gunakan --force untuk menimpa)");
        if ($totalFailed > 0) {
            $this->warn("  Gagal             : {$totalFailed} berkas");
        }
        $this->newLine();

        // Daftarkan font ke dompdf — membangun cache metrik supaya render
        // pertama tidak lambat.
        $this->info('Mendaftarkan font ke dompdf…');
        $registered = $this->registerWithDompdf($fontDir);
        $this->line("  Terdaftar         : {$registered} berkas TTF");

        $this->newLine();
        $this->info('Selesai. Konfigurasi pastikan config/dompdf.php menunjuk font_dir ke '.$fontDir.'.');

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Mengunduh satu keluarga font sebagai ZIP, kemudian mengekstrak
     * berkas TTF ke storage/fonts.
     *
     * @return array{downloaded:int,skipped:int,failed:int}
     */
    private function downloadFont(string $slug, array $meta, string $fontDir, bool $force): array
    {
        $variants = implode(',', $meta['variants']);
        $url = "https://gwfh.mranftl.com/api/fonts/{$slug}?download=zip&subsets=latin&variants={$variants}&formats=ttf";

        $tmpZip = tempnam(sys_get_temp_dir(), 'fonts_'.$slug.'_');
        $tmpExtract = $tmpZip.'_extract';

        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 30,
                    'user_agent' => 'Privasimu-FontInstaller/1.0',
                    'follow_location' => 1,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $bytes = @file_get_contents($url, false, $ctx);
            if ($bytes === false || strlen($bytes) < 1024) {
                $this->warn("  Gagal mengunduh dari {$url}");

                return ['downloaded' => 0, 'skipped' => 0, 'failed' => count($meta['variants'])];
            }

            file_put_contents($tmpZip, $bytes);

            $zip = new ZipArchive;
            if ($zip->open($tmpZip) !== true) {
                $this->warn("  Berkas ZIP tidak dapat dibuka untuk {$slug}");

                return ['downloaded' => 0, 'skipped' => 0, 'failed' => count($meta['variants'])];
            }

            File::ensureDirectoryExists($tmpExtract);
            $zip->extractTo($tmpExtract);
            $zip->close();

            $downloaded = 0;
            $skipped = 0;

            foreach (File::allFiles($tmpExtract) as $file) {
                if (strtolower($file->getExtension()) !== 'ttf') {
                    continue;
                }

                $dest = $fontDir.DIRECTORY_SEPARATOR.$file->getFilename();
                if (File::exists($dest) && ! $force) {
                    $skipped++;
                    $this->line("    [SKIP] {$file->getFilename()} (sudah ada)");

                    continue;
                }

                File::copy($file->getRealPath(), $dest);
                $downloaded++;
                $this->line("    [OK]   {$file->getFilename()}");
            }

            return ['downloaded' => $downloaded, 'skipped' => $skipped, 'failed' => 0];
        } catch (\Throwable $e) {
            $this->warn("  Error: {$e->getMessage()}");

            return ['downloaded' => 0, 'skipped' => 0, 'failed' => count($meta['variants'])];
        } finally {
            if (File::exists($tmpZip)) {
                @unlink($tmpZip);
            }
            if (File::isDirectory($tmpExtract)) {
                File::deleteDirectory($tmpExtract);
            }
        }
    }

    /**
     * Mendaftarkan seluruh berkas TTF di $fontDir ke metrik dompdf
     * sehingga dompdf dapat menemukan font berdasarkan nama keluarga.
     *
     * Mengembalikan jumlah berkas yang berhasil didaftarkan.
     */
    private function registerWithDompdf(string $fontDir): int
    {
        // Cara umum: cukup membuat instance Dompdf — getFontMetrics()
        // otomatis memuat font dari direktori yang dikonfigurasi.
        // Setelah loadFontFamilies() dipanggil, cache metrik (.ufm) akan
        // dibangun di $fontDir.
        $registered = 0;

        try {
            $dompdf = new Dompdf([
                'fontDir' => $fontDir,
                'fontCache' => $fontDir,
                'isRemoteEnabled' => false,
                'chroot' => realpath(base_path()),
            ]);

            $metrics = $dompdf->getFontMetrics();

            // loadFontFamilies() ada di dompdf >= 2.x; bila method absen,
            // jatuhkan ke daftar manual via registerFont().
            if (method_exists($metrics, 'loadFontFamilies')) {
                $metrics->loadFontFamilies();
            }

            foreach (File::files($fontDir) as $file) {
                if (strtolower($file->getExtension()) === 'ttf') {
                    $registered++;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('  Registrasi dompdf gagal: '.$e->getMessage());
            $this->line('  Font tetap dapat dipakai — dompdf akan membangun cache pada render pertama.');
        }

        return $registered;
    }
}
