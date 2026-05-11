<?php

/**
 * Konfigurasi dompdf untuk Privasimu.
 *
 * Diterbitkan dari vendor `barryvdh/laravel-dompdf` dengan penyesuaian:
 *   - `font_dir` & `font_cache` diarahkan ke `storage/fonts` agar
 *     berkas TTF hasil unduh `php artisan templates:install-fonts`
 *     ditemukan dompdf saat me-render 20 template PDF elegan.
 *   - `isRemoteEnabled` diaktifkan supaya watermark/logo yang
 *     terlanjur menggunakan URL HTTPS tetap dapat dirender (default
 *     export sudah meng-inline asset sebagai data URI; flag ini
 *     hanya jala pengaman).
 */
return [

    'show_warnings' => false,

    'public_path' => null,

    'convert_entities' => true,

    'options' => [

        // Direktori font TTF yang dipakai 20 template baru.
        // Lihat: storage/fonts/README.md
        'font_dir' => storage_path('fonts'),

        // Cache metrik font — sama dengan font_dir agar dompdf
        // hanya membangun cache sekali.
        'font_cache' => storage_path('fonts'),

        'temp_dir' => sys_get_temp_dir(),

        // chroot wajib absolute path. Membatasi akses file system
        // dompdf hanya ke dalam folder aplikasi.
        'chroot' => realpath(base_path()),

        'allowed_protocols' => [
            'data://' => ['rules' => []],
            'file://' => ['rules' => []],
            'http://' => ['rules' => []],
            'https://' => ['rules' => []],
        ],

        'artifactPathValidation' => null,

        'log_output_file' => null,

        // Subsetting menghemat ukuran PDF — diaktifkan supaya
        // template yang memakai banyak weight font tetap ringan.
        'enable_font_subsetting' => true,

        'pdf_backend' => 'CPDF',

        'default_media_type' => 'screen',

        'default_paper_size' => 'a4',

        'default_paper_orientation' => 'portrait',

        // Default font keluarga yang harus ada di font_dir. Bila template
        // belum mengunduh font khusus, dompdf akan jatuh ke serif bawaan
        // (DejaVu) yang ter-bundle di package.
        'default_font' => 'serif',

        'dpi' => 96,

        'enable_php' => false,

        'enable_javascript' => true,

        // Tetap aktif supaya watermark/logo bertipe URL HTTPS dapat
        // dimuat sebagai jala pengaman; preview() controller sudah
        // meng-inline asset sebagai data URI sebelum render.
        'enable_remote' => true,

        'allowed_remote_hosts' => null,

        'font_height_ratio' => 1.1,

        'enable_html5_parser' => true,
    ],

];
