<?php

namespace Tests\Unit;

use App\Support\NomorTelepon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class NomorTeleponTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string|null}> */
    public static function kasus(): array
    {
        return [
            'lokal 08' => ['081234567890', '+6281234567890'],
            'lokal dengan pemisah' => ['0812-3456-7890', '+6281234567890'],
            'lokal dengan spasi dan kurung' => ['(0812) 3456 7890', '+6281234567890'],
            'kode negara tanpa plus' => ['6281234567890', '+6281234567890'],
            'kode negara dengan plus' => ['+62 812 3456 7890', '+6281234567890'],
            'awalan 00' => ['006281234567890', '+6281234567890'],
            'tanpa 0 di depan' => ['81234567890', '+6281234567890'],
            'asing dengan plus' => ['+1 555 123 4567', '+15551234567'],
            'asing tanpa plus ditolak' => ['15551234567', null],
            'nomor rumah Indonesia ditolak' => ['0215551234', null],
            'terlalu pendek' => ['0812345', null],
            'terlalu panjang' => ['+62812345678901234', null],
            'surel bukan telepon' => ['siti@contoh.id', null],
            'kosong' => ['', null],
            'huruf saja' => ['abc', null],
        ];
    }

    #[Test]
    #[DataProvider('kasus')]
    public function e164_menormalkan_atau_menolak(string $masukan, ?string $harap): void
    {
        $this->assertSame($harap, NomorTelepon::e164($masukan));
        $this->assertSame($harap !== null, NomorTelepon::valid($masukan));
    }

    #[Test]
    public function format_mengikuti_permintaan_gateway(): void
    {
        $this->assertSame('+6281234567890', NomorTelepon::format('+6281234567890', 'e164'));
        $this->assertSame('6281234567890', NomorTelepon::format('+6281234567890', 'digits'));
        $this->assertSame('081234567890', NomorTelepon::format('+6281234567890', 'local'));
        // Nomor asing tidak punya bentuk "lokal" Indonesia — tetap internasional.
        $this->assertSame('+15551234567', NomorTelepon::format('+15551234567', 'local'));
        $this->assertSame('+6281234567890', NomorTelepon::format('+6281234567890', 'bentuk-aneh'));
    }

    #[Test]
    public function samarkan_hanya_menyisakan_empat_digit(): void
    {
        $this->assertSame('•••••••••7890', NomorTelepon::samarkan('+6281234567890'));
        $this->assertSame('—', NomorTelepon::samarkan(''));
    }
}
