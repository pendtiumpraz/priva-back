<?php

namespace App\Support;

use App\Models\ConsentCollectionPoint;
use Illuminate\Http\Request;

/**
 * Dua modul per SUBJEK — PP 33/2026 Pasal 38 (anak) & 39 (disabilitas).
 *
 *   consent_guardian       Children Pro        /consent-guardian       kelas `anak`
 *   consent_accessibility  Inclusive Privacy   /consent-accessibility  kelas `disabilitas`
 *
 * Nama tampilan resmi: Children Pro (consent anak) dan Inclusive Privacy
 * (consent disabilitas) — label `menu_items` (migrasi 000009) dan i18n
 * frontend; id modul, menu_key, dan href tetap memakai nama teknis.
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

    /**
     * Skrip embed per modul — SENGAJA bukan consent-form.js. Data yang diambil
     * dan endpoint yang dipanggil berbeda: modul wali mengumpulkan data anak +
     * wali + cara verifikasi dan memanggil guardian/request → verify; modul
     * aksesibilitas menyajikan format yang terbukti, pendamping/wali, dan
     * memanggil capture kelas `disabilitas` dengan bukti penyajiannya.
     * Dilayani host FRONTEND (public/), sama seperti consent-form.js.
     */
    public const SKRIP = [
        self::GUARDIAN => 'consent-guardian.js',
        self::ACCESSIBILITY => 'consent-accessibility.js',
    ];

    /** Halaman widget iframe (Next.js) per modul. */
    public const HALAMAN_WIDGET = [
        self::GUARDIAN => '/embed/consent-guardian',
        self::ACCESSIBILITY => '/embed/consent-accessibility',
    ];

    /** Atribut tempat blok widget dipasang di halaman tenant. */
    public const MOUNT = [
        self::GUARDIAN => 'data-privasimu-consent-guardian',
        self::ACCESSIBILITY => 'data-privasimu-consent-accessibility',
    ];

    public static function kelas(string $modul): string
    {
        return self::KELAS[$modul] ?? throw new \InvalidArgumentException("Modul subjek tidak dikenal: {$modul}");
    }

    /**
     * Cuplikan integrasi satu titik: skrip embed, halaman iframe, pratinjau.
     * URL menunjuk host frontend (FRONTEND_URL); bila belum disetel, host API
     * dipakai agar cuplikan tetap terbaca — dengan peringatan FrontendUrl.
     *
     * @return array<string, mixed>
     */
    public static function cuplikan(string $modul, ConsentCollectionPoint $cp): array
    {
        if (! in_array($modul, self::SEMUA, true)) {
            throw new \InvalidArgumentException("Modul subjek tidak dikenal: {$modul}");
        }

        $fe = FrontendUrl::tidakDiset() ? rtrim((string) (config('app.url') ?: url('/')), '/') : FrontendUrl::base();
        $api = rtrim((string) (config('app.url') ?: url('/')), '/').'/api';
        $token = $cp->embed_token ?: $cp->collection_id;
        $skrip = $fe.'/'.self::SKRIP[$modul];
        $mount = self::MOUNT[$modul];
        $nama = (string) $cp->name;

        $snippet = $modul === self::GUARDIAN
            ? <<<HTML
<!-- Privasimu Children Pro (consent anak) — {$nama} -->
<form id="daftar-anak">
  <input name="email" type="email" required>   <!-- penanda anak (surel / ID akun) -->
  <div {$mount}></div>                <!-- blok persetujuan wali muncul di sini -->
  <button type="submit">Daftar</button>
</form>
<script src="{$skrip}"
        data-collection-id="{$token}"
        data-api-host="{$api}"
        data-form-selector="#daftar-anak"
        data-source-form="register"
        defer></script>
HTML
            : <<<HTML
<!-- Privasimu Inclusive Privacy (consent disabilitas) — {$nama} -->
<div {$mount}></div>   <!-- widget berdiri sendiri: penanda subjek, format aksesibel, tombol setuju -->
<script src="{$skrip}"
        data-collection-id="{$token}"
        data-api-host="{$api}"
        data-source-form="register"
        defer></script>
HTML;

        $halaman = self::HALAMAN_WIDGET[$modul];
        $awalanPratinjau = $modul === self::GUARDIAN ? 'guardian' : 'accessibility';

        return [
            'module' => $modul,
            'snippet' => $snippet,
            'script_url' => $skrip,
            'mount_attribute' => $mount,
            'widget_url' => $fe.$halaman.'?collection_id='.rawurlencode((string) $token),
            'preview_url' => $fe.'/embed/subjek-preview?modul='.$awalanPratinjau.'&collection_id='.rawurlencode((string) $token),
            'api_base' => $api,
            'embed_token' => $cp->embed_token,
            'client_key' => $cp->client_key,
            'frontend_warning' => FrontendUrl::peringatan(),
        ];
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
