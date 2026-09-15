<?php

namespace App\Support;

/**
 * Kosakata kelas subjek dan keadaan peralihan — PP 33/2026 Pasal 38 & 39.
 *
 * EMPAT tabel memakai kosakata yang sama: `consent_subjects` (keadaan sekarang),
 * `consent_logs` (potret saat penangkapan), `capacity_assessments`, dan
 * `dsr_requests`. Menaruh tetapannya di salah satu model berarti tiga model lain
 * memanggil silang, dan begitu satu di antaranya menambah nilai baru — 'lansia',
 * 'di bawah pengampuan' — yang lain tidak ikut tahu.
 *
 * Dulu tetapan ini menempel di ConsentRecord, tabel yang ternyata tidak pernah
 * ditulis. Itu sendiri sudah cukup jadi alasan memindahkannya ke tempat yang
 * tidak bergantung pada nasib satu tabel.
 */
final class KelasSubjek
{
    /** Bawaan. Tidak ada kewenangan wali yang terlibat. */
    public const DEWASA = 'dewasa';

    /** Pasal 38 — persetujuan diberikan orang tua atau wali. */
    public const ANAK = 'anak';

    /**
     * Pasal 39. Perlu diingat: kelas ini TIDAK berarti "harus lewat wali".
     * Mayoritas penyandang disabilitas memberi persetujuan sendiri; yang mereka
     * butuhkan adalah penyajian yang dapat diakses. Lihat
     * DisabilityServiceScope::bolehMandiri().
     */
    public const DISABILITAS = 'disabilitas';

    public const SEMUA = [self::DEWASA, self::ANAK, self::DISABILITAS];

    /** Kelas yang memang menempuh jalur wali — satu-satunya yang punya baris subjek. */
    public const DILINDUNGI = [self::ANAK, self::DISABILITAS];

    public const LABEL = [
        self::DEWASA => 'Dewasa',
        self::ANAK => 'Anak',
        self::DISABILITAS => 'Penyandang Disabilitas',
    ];

    /**
     * Keadaan peralihan anak → dewasa (Pasal 38 ayat 8).
     *
     * NULL berarti belum relevan. `menunggu_konfirmasi` BUKAN berarti consent
     * batal — dasar hukumnya diperoleh secara sah dan tidak hilang karena ulang
     * tahun; yang berakhir adalah kewenangan walinya.
     */
    public const TRANSISI_MENUNGGU = 'menunggu_konfirmasi';

    public const TRANSISI_DIKONFIRMASI = 'dikonfirmasi';

    public const TRANSISI_DITARIK = 'ditarik';

    public const TRANSISI = [self::TRANSISI_MENUNGGU, self::TRANSISI_DIKONFIRMASI, self::TRANSISI_DITARIK];

    /** Kelas ini menempuh jalur wali? */
    public static function dilindungi(?string $kelas): bool
    {
        return in_array($kelas, self::DILINDUNGI, true);
    }
}
