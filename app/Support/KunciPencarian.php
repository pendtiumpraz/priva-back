<?php

namespace App\Support;

/**
 * Kunci pencarian untuk kolom yang tersandi.
 *
 * MASALAHNYA. Cast EncryptedString memakai AES-256-CBC dengan IV acak: dua
 * penyandian atas teks yang sama menghasilkan sandi yang BERBEDA. Akibatnya
 * `where('email', $plaintext)` pada kolom tersandi TIDAK AKAN PERNAH cocok —
 * dan yang berbahaya, ia tidak melempar galat apa pun. Query-nya cuma
 * mengembalikan kosong, selamanya, dan pemeriksaan yang dibangun di atasnya
 * terlihat seolah bekerja.
 *
 * Itu bukan kemungkinan teoretis. Dua tempat di aplikasi ini memeriksa
 * permohonan DSR ganda dengan cara itu, sehingga pemeriksaannya tidak pernah
 * menemukan apa pun sejak ditulis.
 *
 * JALAN KELUARNYA. Simpan hash deterministik di kolom terpisah, di sebelah
 * nilai tersandinya. Hash-nya bisa dicari dan diindeks; nilai aslinya tetap
 * tersandi. Yang bocor dari hash hanyalah "dua baris ini punya nilai yang
 * sama" — bukan nilainya.
 *
 * NORMALISASI ITU BAGIAN DARI KUNCINYA, bukan kerapian. Tanpa disamakan
 * bentuknya, "Budi@Contoh.ID " dan "budi@contoh.id" menghasilkan hash berbeda
 * dan sistem memperlakukannya sebagai dua orang.
 *
 * Kelas ini ada supaya aturan normalisasinya ditulis SEKALI. Dua salinan yang
 * "kurang lebih sama" akan menyimpang, lalu menghasilkan duplikat yang sangat
 * sulit ditelusuri karena kedua sisinya tampak benar.
 */
final class KunciPencarian
{
    /**
     * Samakan bentuk kontak sebelum di-hash.
     *
     * Surel: huruf kecil, spasi luar dibuang.
     * Telepon: semua pemisah dibuang, awalan Indonesia disamakan ke bentuk 0.
     */
    public static function normalkan(string $nilai): string
    {
        $n = trim(mb_strtolower($nilai));

        if ($n === '' || str_contains($n, '@')) {
            return $n;
        }

        // Diperlakukan sebagai nomor telepon.
        $n = preg_replace('/[^0-9+]/', '', $n) ?? $n;

        if (str_starts_with($n, '+62')) {
            return '0'.substr($n, 3);
        }
        if (str_starts_with($n, '62')) {
            return '0'.substr($n, 2);
        }

        return $n;
    }

    /**
     * Hash deterministik untuk dicari dan diindeks.
     *
     * Mengembalikan null untuk nilai kosong, supaya kolomnya tetap NULL dan
     * tidak ada baris kosong yang saling bertabrakan di indeks unik.
     */
    public static function hash(?string $nilai): ?string
    {
        $n = self::normalkan((string) ($nilai ?? ''));

        return $n === '' ? null : hash('sha256', $n);
    }
}
