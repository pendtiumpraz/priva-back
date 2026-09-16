<?php

namespace App\Services\Verifikasi;

/**
 * Hasil satu pemeriksaan identitas. Tiga keadaan, dan yang ketiga PENTING:
 *
 *   cocok        penyedia menyatakan klaim sesuai catatannya;
 *   tidak_cocok  penyedia menyatakan TIDAK sesuai — jawaban yang definitif;
 *   gagal        kami tidak tahu: penyedia tak terjangkau, kredensial tenant
 *                salah, kontrak responsnya berubah. Ini BUKAN "tidak cocok" —
 *                memberi tahu wali "identitas Anda tidak cocok" saat yang rusak
 *                adalah API key tenant adalah tuduhan, dan tenant tidak akan
 *                pernah tahu integrasinya patah.
 *
 * Yang dibawa pulang dari penyedia hanya nomor rujukan (untuk audit) dan
 * alasan singkat. Tidak ada salinan respons mentah: respons itu bisa memuat
 * data kependudukan yang tidak kami minta dan tidak boleh kami simpan.
 */
final class HasilVerifikasi
{
    public const COCOK = 'cocok';

    public const TIDAK_COCOK = 'tidak_cocok';

    public const GAGAL = 'gagal';

    private function __construct(
        public readonly string $status,
        public readonly ?string $referensi,
        public readonly ?string $alasan,
        public readonly ?int $httpStatus,
    ) {}

    /** Penyedia menyatakan klaim SESUAI catatannya. */
    public static function terbukti(?string $referensi = null, ?int $httpStatus = null): self
    {
        return new self(self::COCOK, self::ringkas($referensi, 255), null, $httpStatus);
    }

    /** Penyedia menyatakan TIDAK sesuai — jawaban definitif. */
    public static function ditolak(?string $alasan = null, ?int $httpStatus = null): self
    {
        return new self(self::TIDAK_COCOK, null, self::ringkas($alasan, 500), $httpStatus);
    }

    /** Kami tidak tahu: gangguan teknis, kredensial, atau kontrak berubah. */
    public static function terganggu(string $alasan, ?int $httpStatus = null): self
    {
        return new self(self::GAGAL, null, self::ringkas($alasan, 500), $httpStatus);
    }

    public function cocok(): bool
    {
        return $this->status === self::COCOK;
    }

    public function gagal(): bool
    {
        return $this->status === self::GAGAL;
    }

    /** @return array{status: string, reference: string|null, reason: string|null, http_status: int|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reference' => $this->referensi,
            'reason' => $this->alasan,
            'http_status' => $this->httpStatus,
        ];
    }

    private static function ringkas(?string $teks, int $maks): ?string
    {
        if ($teks === null) {
            return null;
        }
        $teks = trim($teks);

        return $teks === '' ? null : mb_substr($teks, 0, $maks);
    }
}
