<?php

namespace App\Services\Pesan;

final class HasilKirim
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $alasan,
        public readonly ?int $httpStatus,
        public readonly ?string $referensi,
    ) {}

    public static function berhasil(?int $httpStatus = null, ?string $referensi = null): self
    {
        return new self(true, null, $httpStatus, $referensi !== null ? mb_substr($referensi, 0, 255) : null);
    }

    public static function gagal(string $alasan, ?int $httpStatus = null): self
    {
        return new self(false, mb_substr($alasan, 0, 500), $httpStatus, null);
    }
}
