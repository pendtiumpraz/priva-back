<?php

namespace App\Services\Verifikasi;

use App\Models\VerificationMethod;

/**
 * Kontrak driver verifikasi kuat — PP 33/2026 Pasal 38 ayat (4).
 *
 * Satu metode = satu baris `verification_methods` milik TENANT, dengan
 * kredensialnya sendiri di `config` (terenkripsi). Privasimu tidak memegang
 * kredensial Dukcapil atau e-KYC atas nama semua tenant; driver hanya tahu
 * cara memakai kredensial yang diberikan.
 *
 * Driver TIDAK boleh menyimpan, mencatat ke log, atau mengembalikan klaim
 * (NIK, nama, tanggal lahir). Ia menerima KlaimIdentitas, bertanya ke
 * penyedia, dan pulang membawa HasilVerifikasi.
 */
interface PenyediaVerifikasi
{
    public function periksa(VerificationMethod $metode, KlaimIdentitas $klaim): HasilVerifikasi;
}
