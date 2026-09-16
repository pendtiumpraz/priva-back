<?php

/*
|--------------------------------------------------------------------------
| Kanal pesan singkat (SMS / WhatsApp)
|--------------------------------------------------------------------------
|
| Dipakai oleh tautan verifikasi wali (PP 33/2026 Pasal 38) dan tautan
| peralihan anak → dewasa ketika kanal milik orangnya adalah nomor telepon.
| Nilai di sini adalah bawaan dari .env; seksi `messaging` di Pengaturan
| Sistem (superadmin) menimpanya saat boot lewat SettingsServiceProvider.
|
| driver  off   kanal telepon tidak tersedia — widget tidak menawarkannya,
|               dan kontak telepon ditolak terbuka (422), bukan diam-diam.
|         log   tulis ke log aplikasi (lokal / uji).
|         http  gateway HTTP generik — Zenziva, Fonnte, Wablas, Twilio, dan
|               sejenisnya; kontraknya dipetakan lewat templat di bawah.
|
| Placeholder yang dikenali di URL, header, dan badan:
|   {to}       nomor tujuan, dibentuk sesuai `to_format`
|   {message}  isi pesan
|   {secret}   rahasia tunggal (token/API key) — tidak pernah ditampilkan
|   {sender}   nama pengirim
|
*/

return [

    'sms' => [
        'driver' => env('MESSAGING_SMS_DRIVER', 'off'),

        'http_url' => env('MESSAGING_SMS_HTTP_URL'),
        'http_method' => env('MESSAGING_SMS_HTTP_METHOD', 'POST'),

        // JSON objek {"nama": "nilai"}; boleh memakai {secret}.
        'http_headers' => env('MESSAGING_SMS_HTTP_HEADERS'),

        // Rahasia tunggal yang disisipkan ke {secret}.
        'http_secret' => env('MESSAGING_SMS_HTTP_SECRET'),

        // JSON objek templat badan permintaan.
        'http_body' => env('MESSAGING_SMS_HTTP_BODY', '{"to":"{to}","message":"{message}"}'),

        // Opsional: path (notasi titik) di respons JSON yang menandakan
        // sukses, dan nilai yang diharapkan. Kosong = HTTP 2xx sudah cukup.
        'http_success_path' => env('MESSAGING_SMS_HTTP_SUCCESS_PATH'),
        'http_success_equals' => env('MESSAGING_SMS_HTTP_SUCCESS_EQUALS'),

        // e164 (+62812…) | digits (62812…) | local (0812…)
        'to_format' => env('MESSAGING_SMS_TO_FORMAT', 'e164'),

        'sender_name' => env('MESSAGING_SMS_SENDER_NAME', 'Privasimu'),

        'timeout' => (int) env('MESSAGING_SMS_TIMEOUT', 10),
    ],

];
