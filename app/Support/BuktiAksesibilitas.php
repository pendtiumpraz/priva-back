<?php

namespace App\Support;

use App\Models\AccessibilityProvision;
use Illuminate\Validation\Rule;

/**
 * Bukti penyajian yang dapat diakses — PP 33/2026 Pasal 39 ayat (3).
 *
 * Disimpan di `consent_logs.accessibility_meta` HANYA untuk subjek disabilitas:
 * format yang benar-benar dipakai saat menyetujui (pembacaan suara, teks
 * besar, kontras tinggi, ...), apakah subjek didampingi, dan HUBUNGAN
 * pendampingnya — bukan namanya. Yang dicatat adalah bagaimana persetujuan
 * DISAJIKAN, bukan siapa orangnya: status disabilitas seseorang tetap tidak
 * pernah disimpan per orang (lihat catatan di AccessibilityAdminController).
 *
 * Dipakai kedua jalur tangkap (widget publik & Partner API v1) supaya bukti
 * yang sama tersedia dari pintu mana pun.
 */
final class BuktiAksesibilitas
{
    /**
     * Aturan validasi — digabungkan ke aturan tangkap.
     *
     * @return array<string, mixed>
     */
    public static function aturan(): array
    {
        return [
            'accessibility' => 'nullable|array',
            'accessibility.formats_used' => 'nullable|array|max:10',
            'accessibility.formats_used.*' => ['string', Rule::in(AccessibilityProvision::FORMAT)],
            'accessibility.assisted' => 'nullable|boolean',
            'accessibility.companion_relationship' => 'nullable|string|max:120',
        ];
    }

    /**
     * Bentuk yang disimpan, atau null bila tidak ada yang layak dicatat.
     *
     * Kelas selain disabilitas selalu null — bukti aksesibilitas untuk orang
     * dewasa atau anak tidak punya makna hukum dan hanya menambah data.
     *
     * @param  array<string, mixed>|null  $masukan
     * @return array{formats_used: list<string>, assisted: bool, companion_relationship: string|null}|null
     */
    public static function dariMasukan(?array $masukan, ?string $kelas): ?array
    {
        if ($kelas !== KelasSubjek::DISABILITAS || empty($masukan)) {
            return null;
        }

        $format = array_values(array_unique(array_filter(
            array_map(fn ($f) => trim((string) $f), (array) ($masukan['formats_used'] ?? [])),
        )));
        $didampingi = filter_var($masukan['assisted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hubungan = trim((string) ($masukan['companion_relationship'] ?? ''));

        if ($format === [] && ! $didampingi && $hubungan === '') {
            return null;
        }

        return [
            'formats_used' => $format,
            'assisted' => $didampingi || $hubungan !== '',
            'companion_relationship' => $hubungan !== '' ? $hubungan : null,
        ];
    }
}
