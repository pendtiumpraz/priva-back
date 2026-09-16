<?php

namespace App\Services\Pesan;

/**
 * Satu pesan singkat ke satu nomor. `tujuan` selalu E.164 (hasil
 * NomorTelepon::e164); pembentukan ke format gateway terjadi di driver.
 *
 * Pesan ke kanal ini pendek dan MINIM: nama pengendali, nama penerima, dan
 * tautan. SMS bukan kanal tersandi — penanda anak dan tujuan pemrosesan
 * tidak pernah ikut.
 */
final class PesanSingkat
{
    public const KONTEKS_TAUTAN_WALI = 'guardian_link';

    public const KONTEKS_TAUTAN_PERALIHAN = 'transition_link';

    public const KONTEKS_UJI = 'test';

    public function __construct(
        public readonly string $tujuan,
        public readonly string $teks,
        public readonly string $konteks,
    ) {}
}
