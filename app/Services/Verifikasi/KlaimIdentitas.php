<?php

namespace App\Services\Verifikasi;

use Illuminate\Support\Carbon;

/**
 * Klaim identitas wali yang DIKIRIM ke penyedia verifikasi — dan tidak ke
 * mana-mana lagi.
 *
 * Objek ini sengaja tidak punya properti publik, tidak mengimplementasikan
 * JsonSerializable, dan menyamarkan dirinya saat di-dump: NIK dan tanggal
 * lahir wali tidak boleh bocor lewat `json_encode`, `dd()`, log, atau
 * pesan galat. Yang boleh tersimpan dari verifikasi adalah HASILNYA — nomor
 * rujukan penyedia — bukan klaim yang diperiksanya (lihat GuardianConsent).
 */
final class KlaimIdentitas
{
    public function __construct(
        private readonly string $nik,
        private readonly string $nama,
        /** Format Y-m-d, sudah tervalidasi pemanggil. */
        private readonly string $tanggalLahir,
    ) {}

    public function nik(): string
    {
        return $this->nik;
    }

    public function nama(): string
    {
        return $this->nama;
    }

    /** Tanggal lahir dalam format yang diminta kontrak penyedia (mis. `d-m-Y`). */
    public function tanggalLahir(string $format = 'Y-m-d'): string
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $this->tanggalLahir)->format($format);
        } catch (\Throwable) {
            return $this->tanggalLahir;
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'nik' => '************'.substr($this->nik, -4),
            'nama' => mb_substr($this->nama, 0, 1).'***',
            'tanggal_lahir' => '****-**-**',
        ];
    }
}
