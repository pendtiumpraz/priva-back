<?php

namespace App\Services\Pesan;

use App\Jobs\KirimPesanSingkatJob;

/**
 * Satu pintu ke kanal SMS/WhatsApp platform.
 *
 * Konfigurasinya `config('messaging.sms')` — bawaan dari .env, ditimpa
 * seksi `messaging` Pengaturan Sistem saat boot. Fitur memeriksa
 * `tersedia()` SEBELUM menjanjikan apa pun: widget hanya menawarkan kanal
 * telepon, dan LayananWali hanya menerima kontak telepon, bila kanalnya
 * benar-benar bisa mengirim.
 */
final class KanalPesan
{
    public const DRIVERS = ['off', 'log', 'http'];

    public function __construct(
        private readonly HttpPesanDriver $http,
        private readonly LogPesanDriver $log,
    ) {}

    /** @return array<string, mixed> */
    public static function konfigurasi(): array
    {
        $cfg = config('messaging.sms');

        return is_array($cfg) ? $cfg : [];
    }

    /** @param  array<string, mixed>|null  $cfg */
    public static function driver(?array $cfg = null): string
    {
        $d = strtolower(trim((string) (($cfg ?? self::konfigurasi())['driver'] ?? 'off')));

        return in_array($d, self::DRIVERS, true) ? $d : 'off';
    }

    /**
     * Kanal telepon bisa dipakai SAAT INI: driver hidup, dan untuk `http`
     * URL gateway-nya ada. Terdaftar ≠ bisa dijalankan.
     *
     * @param  array<string, mixed>|null  $cfg
     */
    public static function tersedia(?array $cfg = null): bool
    {
        $cfg ??= self::konfigurasi();

        return match (self::driver($cfg)) {
            'log' => true,
            'http' => trim((string) ($cfg['http_url'] ?? '')) !== '',
            default => false,
        };
    }

    /**
     * Kirim sekarang (sinkron). Dipakai job antrean dan tombol uji.
     *
     * @param  array<string, mixed>|null  $cfg
     */
    public function kirim(PesanSingkat $pesan, ?array $cfg = null): HasilKirim
    {
        $cfg ??= self::konfigurasi();

        return match (self::driver($cfg)) {
            'log' => $this->log->kirim($pesan, $cfg),
            'http' => $this->http->kirim($pesan, $cfg),
            default => HasilKirim::gagal('Kanal pesan tidak aktif (driver off).'),
        };
    }

    /** Antrekan — seperti Mail::queue: permintaan HTTP tidak menunggu gateway. */
    public function antre(PesanSingkat $pesan): void
    {
        KirimPesanSingkatJob::dispatch($pesan);
    }
}
