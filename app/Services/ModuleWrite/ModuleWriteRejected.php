<?php

namespace App\Services\ModuleWrite;

use RuntimeException;

/**
 * Penolakan yang BERASAL DARI ATURAN MODUL, bukan dari kesalahan teknis.
 *
 * Kunci penyuntingan RoPA/DPIA — assign-group yang terkunci di luar status
 * in_progress, dan konten yang terkunci saat status `waiting` — dulu berupa
 * response 409 yang ditulis langsung di controller. Membiarkannya di sana
 * berarti jalur tulis lain (kunci API mitra, impor, agen AI) MELEWATI kunci itu:
 * mitra bisa menyunting record yang sedang terkunci untuk review, padahal dari
 * aplikasi tidak bisa.
 *
 * Karena itu aturannya ikut pindah ke service, dan penolakannya disampaikan
 * lewat exception ini. Pemanggil yang merender amplopnya sendiri — controller
 * menjawab 409 JSON, API mitra menjawab 409 JSON versinya — tetapi KEPUTUSANNYA
 * cuma satu dan tinggal di satu tempat.
 */
class ModuleWriteRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  data tambahan untuk badan response
     */
    public function __construct(
        string $message,
        public readonly int $status = 409,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function toResponseBody(): array
    {
        return ['message' => $this->getMessage()] + $this->context;
    }
}
