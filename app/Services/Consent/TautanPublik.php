<?php

namespace App\Services\Consent;

use App\Support\FrontendUrl;

/**
 * Tautan yang dibuka MANUSIA — wali dan subjek yang baru dewasa — menunjuk
 * HALAMAN Next.js, bukan endpoint API (lihat App\Support\FrontendUrl).
 *
 * Halaman itu sendiri memanggil endpoint API yang sama dengan sebelumnya:
 *   /wali/{token}       → GET/POST /api/public/consent/guardian/verify/{token}
 *   /peralihan/{token}  → GET /api/public/consent/transition/{token}
 *                         POST …/confirm | …/withdraw
 *
 * Halaman HTML Blade di backend tetap ada sebagai cadangan bila endpoint API
 * dibuka langsung di peramban — tetapi tidak ada lagi tautan yang menunjuk
 * ke sana. Pendek juga penting: tautan ini ikut dalam SMS.
 */
final class TautanPublik
{
    public static function wali(string $token): string
    {
        return FrontendUrl::link('/wali/'.$token);
    }

    public static function peralihan(string $token): string
    {
        return FrontendUrl::link('/peralihan/'.$token);
    }
}
