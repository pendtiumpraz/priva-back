<?php

/**
 * Konfigurasi Spatie\Browsershot — dipakai oleh App\Services\PdfRenderService
 * untuk merender template PDF yang memerlukan fitur CSS modern (mix-blend-mode,
 * backdrop-filter, gradient text-clip, filter blur) yang tidak didukung dompdf.
 */
return [

    // Path absolut ke binary Chrome/Chromium. Bila kosong, service akan
    // mencoba mendeteksi path standar pada Windows (Program Files,
    // AppData/Local) dan Linux (/usr/bin/google-chrome dsb).
    'chrome_path' => env('BROWSERSHOT_CHROME_PATH'),

    // Path absolut ke binary Node.js. Bila kosong, service akan mencari
    // melalui PATH OS. Wajib diisi pada server yang memakai nvm.
    'node_path' => env('BROWSERSHOT_NODE_PATH'),

    // Batas waktu (detik) — boot Chromium pertama kali bisa lambat,
    // terutama pada container yang belum melakukan pemanasan.
    'timeout' => env('BROWSERSHOT_TIMEOUT', 60),

    // Nonaktifkan sandbox Chromium. Wajib true di dalam Docker/CI yang
    // tidak menyediakan namespace untuk sandbox.
    'no_sandbox' => env('BROWSERSHOT_NO_SANDBOX', false),

];
