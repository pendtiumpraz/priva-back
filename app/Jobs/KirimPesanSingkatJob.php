<?php

namespace App\Jobs;

use App\Services\Pesan\KanalPesan;
use App\Services\Pesan\PesanSingkat;
use App\Support\NomorTelepon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Kirim satu pesan singkat lewat kanal platform, dengan percobaan ulang.
 * Padanan `Mail::queue()` untuk SMS/WhatsApp.
 */
class KirimPesanSingkatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly PesanSingkat $pesan) {}

    public function handle(KanalPesan $kanal): void
    {
        $hasil = $kanal->kirim($this->pesan);

        if (! $hasil->ok) {
            throw new \RuntimeException('Pengiriman pesan gagal: '.($hasil->alasan ?? 'tanpa keterangan'));
        }
    }

    public function failed(?\Throwable $e): void
    {
        // Nomor disamarkan, isi pesan tidak dicatat: tautannya adalah rahasia.
        Log::error('[kanal pesan] gagal setelah semua percobaan', [
            'konteks' => $this->pesan->konteks,
            'to' => NomorTelepon::samarkan($this->pesan->tujuan),
            'error' => $e?->getMessage(),
        ]);
    }
}
