<?php

namespace App\Services\Verifikasi;

use App\Models\VerificationMethod;

/**
 * Driver simulasi — untuk staging, demo, dan uji alur ujung-ke-ujung tanpa
 * kredensial Dukcapil. TIDAK PERNAH tersedia di produksi: RegistriPenyedia
 * menolaknya dan CRUD metode tidak menerimanya di sana. Metode "verifikasi"
 * yang meloloskan siapa pun adalah `guardian_mode` versi lebih buruk.
 *
 * Aturan: bila `config.accept_nik` diisi, hanya NIK di daftar itu yang cocok;
 * bila kosong, NIK berakhiran digit GENAP cocok, GANJIL tidak — supaya demo
 * bisa memperlihatkan kedua jalur tanpa pengaturan apa pun.
 */
final class MockVerifikasiDriver implements PenyediaVerifikasi
{
    public function periksa(VerificationMethod $metode, KlaimIdentitas $klaim): HasilVerifikasi
    {
        $nik = $klaim->nik();
        $terima = $metode->config['accept_nik'] ?? [];
        $terima = is_array($terima) ? array_map('strval', $terima) : [];

        $cocok = $terima !== []
            ? in_array($nik, $terima, true)
            : ((int) substr($nik, -1)) % 2 === 0;

        return $cocok
            ? HasilVerifikasi::terbukti('MOCK-'.strtoupper(substr(hash('sha256', $nik.'|'.$metode->id), 0, 10)))
            : HasilVerifikasi::ditolak('Simulasi: data tidak sesuai.');
    }
}
