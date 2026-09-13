<?php

namespace App\Support;

/**
 * Basis URL untuk tautan yang dibuka MANUSIA di peramban.
 *
 * Perlu dibedakan tegas dari `app.url`, yang adalah host API Laravel. Semua
 * tautan publik — pengisian RoPA pihak ketiga, asesmen TPRM, tautan berbagi
 * rekaman, tombol di dalam surel, tautan atur ulang kata sandi — menunjuk
 * HALAMAN, dan halaman itu dilayani Next.js, bukan Laravel. Menunjuk ke host
 * API menghasilkan 404 bagi penerimanya.
 *
 * KENAPA INI PERNAH SALAH DIAM-DIAM
 * ---------------------------------
 * `config/app.php` menjatuhkan `frontend_url` ke `APP_URL` bila FRONTEND_URL
 * tidak di-set. Untuk pemasangan satu host (nginx melayani frontend dan
 * mem-proxy /api ke backend) itu benar. Untuk pemasangan terpisah — frontend di
 * Vercel, backend di hosting lain — hasilnya adalah tautan yang menunjuk ke
 * host API, dan tidak ada satu pun galat yang muncul: tautannya terbentuk
 * rapi, tersalin rapi, dan baru gagal di tangan penerima.
 *
 * Karena itu keterjatuhan itu sekarang DAPAT DIKETAHUI lewat
 * `app.frontend_url_explicit`, dan pemanggil yang menyusun tautan untuk pihak
 * luar sebaiknya menyampaikan `peringatan()` kepada penggunanya.
 */
final class FrontendUrl
{
    /** Basis URL frontend, tanpa garis miring di ujung. */
    public static function base(): string
    {
        return rtrim((string) config('app.frontend_url'), '/');
    }

    /**
     * Menyusun tautan absolut ke sebuah halaman frontend.
     */
    public static function link(string $path): string
    {
        return self::base().'/'.ltrim($path, '/');
    }

    /**
     * Benarkah URL ini menunjuk halaman milik kita sendiri?
     *
     * Dipakai sebagai penjaga pengalihan terbuka. Pemeriksaan yang wajar
     * ditulis orang — `str_starts_with($url, $base)` — TIDAK cukup: dengan
     * basis "https://app.contoh.id", alamat "https://app.contoh.id.jahat.com/x"
     * lolos begitu saja, karena ia memang berawalan sama. Maka di sini
     * diharuskan tepat sama, atau berlanjut dengan garis miring — yaitu batas
     * yang memisahkan nama host dari jalurnya.
     */
    public static function milikSendiri(?string $url): bool
    {
        $base = self::base();
        if ($base === '' || $url === null || $url === '') {
            return false;
        }

        return $url === $base || str_starts_with($url, $base.'/');
    }

    /**
     * Benarkah basis ini hanya hasil keterjatuhan ke APP_URL?
     *
     * Sengaja dibaca dari konfigurasi, bukan dari env() langsung: pada
     * produksi yang menjalankan `config:cache`, env() mengembalikan null dan
     * pemeriksaannya akan selalu bilang "salah setel".
     */
    public static function tidakDiset(): bool
    {
        return config('app.frontend_url_explicit') !== true;
    }

    /**
     * Peringatan yang layak ditampilkan di sebelah tautan yang baru dibuat,
     * atau null bila memang tidak ada masalah.
     *
     * Pemasangan satu host juga ikut diperingatkan. Itu disengaja: menyetel
     * FRONTEND_URL ke nilai yang sama menghilangkan peringatannya, dan
     * konfigurasi yang dinyatakan terang lebih baik daripada yang kebetulan
     * benar.
     */
    public static function peringatan(): ?string
    {
        if (! self::tidakDiset()) {
            return null;
        }

        return 'FRONTEND_URL belum di-set, sehingga tautan ini memakai APP_URL ('
            .rtrim((string) config('app.url'), '/')
            .') — yaitu host API, bukan host halaman. Penerima tautan kemungkinan mendapat 404. '
            .'Set FRONTEND_URL di .env backend ke alamat frontend Next.js. '
            .'Bila frontend memang satu host dengan API, set ke alamat yang sama untuk menghilangkan peringatan ini.';
    }
}
