<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Dua modul per SUBJEK — PP 33/2026 Pasal 38 (anak) & 39 (disabilitas).
 *
 *   consent_guardian       Consent Wali          /consent-guardian       kelas `anak`
 *   consent_accessibility  Consent Aksesibilitas /consent-accessibility  kelas `disabilitas`
 *
 * Id modul = id izin (`permission:consent_guardian,read`) = pemilik titik
 * pengumpulan (`consent_collection_points.owner_module`). Menu key-nya
 * berawalan `consent-` dengan tanda hubung; PermissionService menyamakan
 * `-` dan `_` sehingga keduanya satu.
 *
 * Rute kedua modul dilayani controller yang sama; modulnya dibawa sebagai
 * default rute (`modul`, `kelas`) — lihat routes/api.php.
 */
final class ModulSubjek
{
    public const GUARDIAN = 'consent_guardian';

    public const ACCESSIBILITY = 'consent_accessibility';

    public const SEMUA = [self::GUARDIAN, self::ACCESSIBILITY];

    public const KELAS = [
        self::GUARDIAN => KelasSubjek::ANAK,
        self::ACCESSIBILITY => KelasSubjek::DISABILITAS,
    ];

    public const AWALAN = [
        self::GUARDIAN => 'consent-guardian',
        self::ACCESSIBILITY => 'consent-accessibility',
    ];

    public static function kelas(string $modul): string
    {
        return self::KELAS[$modul] ?? throw new \InvalidArgumentException("Modul subjek tidak dikenal: {$modul}");
    }

    public static function dariRequest(Request $request): string
    {
        $modul = (string) $request->route('modul');
        if (! in_array($modul, self::SEMUA, true)) {
            abort(500, 'Rute modul subjek tidak membawa default `modul`.');
        }

        return $modul;
    }
}
