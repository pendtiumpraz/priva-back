<?php

namespace App\Services\Pesan;

/**
 * Kontrak driver kanal pesan. `$cfg` adalah `config('messaging.sms')` —
 * atau konfigurasi sementara dari tombol "Test Connection" superadmin, yang
 * sengaja tidak menyentuh konfigurasi hidup.
 */
interface PengirimPesan
{
    /** @param  array<string, mixed>  $cfg */
    public function kirim(PesanSingkat $pesan, array $cfg): HasilKirim;
}
