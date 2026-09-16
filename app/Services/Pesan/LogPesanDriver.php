<?php

namespace App\Services\Pesan;

use App\Support\NomorTelepon;
use Illuminate\Support\Facades\Log;

/**
 * Driver `log` — untuk lingkungan lokal dan uji. Isi pesan (termasuk
 * tautannya) ditulis utuh ke log supaya pengembang bisa mengekliknya;
 * karena itu driver ini TIDAK untuk produksi.
 */
final class LogPesanDriver implements PengirimPesan
{
    public function kirim(PesanSingkat $pesan, array $cfg): HasilKirim
    {
        Log::info('[kanal pesan] '.$pesan->konteks, [
            'to' => NomorTelepon::samarkan($pesan->tujuan),
            'teks' => $pesan->teks,
        ]);

        return HasilKirim::berhasil(null, 'LOG-'.substr(hash('sha256', $pesan->tujuan.'|'.microtime(true)), 0, 10));
    }
}
