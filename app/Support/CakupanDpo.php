<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/**
 * Cakupan pandangan DPO — diatur admin tenant, per organisasi.
 *
 * DUA setting yang saling bebas, dan itu disengaja. Keempat kombinasi yang biasa
 * disebut orang — "1 DPO per perusahaan", "multi DPO per perusahaan", "1 DPO per
 * divisi", "multi DPO per divisi" — sebenarnya dua sumbu berbeda yang sering
 * tertukar jadi satu dropdown:
 *
 *   CAKUPAN (se-perusahaan / per-divisi)  → menentukan APA yang dilihat DPO.
 *   JUMLAH  (satu / banyak)               → menentukan BERAPA akun boleh
 *                                           memegang peran DPO. Sama sekali
 *                                           tidak memengaruhi akses.
 *
 * Menggabungkannya jadi satu nilai memaksa kode mengurai dua arti dari satu
 * tempat, dan itu sumber salah tafsir yang mahal di kemudian hari.
 *
 * Bawaannya SE-PERUSAHAAN. Itu bukan sekadar pilihan default yang aman, tapi
 * yang sesuai kewajiban: DPO memikul notifikasi insiden 3x24 jam (UU PDP Pasal
 * 46) dan pengawasan kepatuhan menyeluruh. Cakupan per-divisi membuat insiden di
 * divisi lain tak terlihat olehnya — sah dipilih bila tenant memang menugaskan
 * DPO per divisi, tapi konsekuensinya harus disadari, bukan kebetulan.
 *
 * PANDANGAN YANG DIPERSEMPIT: DPO se-perusahaan boleh memfokuskan tampilannya ke
 * satu divisi lewat `users.settings['dpo_view_division']`. Itu KENYAMANAN, bukan
 * kontrol keamanan — ia hanya MENYEMPITKAN dan tidak pernah memperluas, dan ia
 * diabaikan sepenuhnya untuk siapa pun yang bukan DPO se-perusahaan. Tanpa
 * penjagaan itu, staf biasa cukup menulis nilai di preferensinya sendiri untuk
 * ikut melihat divisi lain.
 */
final class CakupanDpo
{
    /** DPO melihat seluruh perusahaan (bawaan). */
    public const SE_PERUSAHAAN = 'company';

    /** DPO hanya melihat divisinya sendiri. */
    public const PER_DIVISI = 'division';

    public const CAKUPAN = [self::SE_PERUSAHAAN, self::PER_DIVISI];

    /** Berapa akun boleh memegang peran DPO. Tidak memengaruhi akses. */
    public const SATU = 'single';

    public const BANYAK = 'multiple';

    public const JUMLAH = [self::SATU, self::BANYAK];

    public const KUNCI_CAKUPAN = 'dpo_scope';

    public const KUNCI_JUMLAH = 'dpo_jumlah';

    /** Kunci preferensi pandangan yang dipersempit, di `users.settings`. */
    public const KUNCI_PANDANGAN = 'dpo_view_division';

    /**
     * Cakupan yang berlaku bagi organisasi user ini.
     *
     * @param  User|null  $user
     */
    public static function cakupan($user): string
    {
        $pengaturan = optional(optional($user)->organization)->settings;
        $nilai = is_array($pengaturan) ? ($pengaturan[self::KUNCI_CAKUPAN] ?? null) : null;

        return in_array($nilai, self::CAKUPAN, true) ? $nilai : self::SE_PERUSAHAAN;
    }

    /**
     * Batas berapa akun boleh memegang peran DPO.
     *
     * @param  Organization|null  $org
     */
    public static function jumlah($org): string
    {
        $pengaturan = optional($org)->settings;
        $nilai = is_array($pengaturan) ? ($pengaturan[self::KUNCI_JUMLAH] ?? null) : null;

        return in_array($nilai, self::JUMLAH, true) ? $nilai : self::BANYAK;
    }

    /**
     * Apakah DPO ini melihat seluruh perusahaan SAAT INI?
     *
     * Dua hal bisa membuatnya tidak: cakupan tenant memang per-divisi, atau
     * ia sendiri sedang mempersempit pandangannya ke satu divisi.
     *
     * @param  User|null  $user
     */
    public static function melihatSeluruhPerusahaan($user): bool
    {
        if (! AssignmentScope::berperanDpo($user)) {
            return false;
        }

        return self::cakupan($user) === self::SE_PERUSAHAAN
            && self::pandanganDipersempit($user) === null;
    }

    /**
     * Divisi yang sedang dipakai DPO ini untuk mempersempit pandangannya, atau
     * null bila tidak sedang mempersempit.
     *
     * Hanya berlaku bagi DPO yang cakupannya SE-PERUSAHAAN. Bagi siapa pun yang
     * lain — termasuk DPO per-divisi, yang batasnya sudah datang dari divisinya
     * sendiri — nilainya diabaikan sepenuhnya.
     *
     * @param  User|null  $user
     */
    public static function pandanganDipersempit($user): ?string
    {
        if (! $user || ! AssignmentScope::berperanDpo($user)) {
            return null;
        }

        if (self::cakupan($user) !== self::SE_PERUSAHAAN) {
            return null;
        }

        $pengaturan = $user->settings;
        $divisi = is_array($pengaturan) ? trim((string) ($pengaturan[self::KUNCI_PANDANGAN] ?? '')) : '';

        return $divisi === '' ? null : $divisi;
    }
}
