<?php

namespace App\Support;

/**
 * Nomor telepon untuk PENGIRIMAN — bukan untuk pencarian.
 *
 * Kunci pencarian (KunciPencarian) menyamakan nomor ke bentuk lokal `08…`
 * supaya hash-nya deterministik. Gateway pesan justru minta bentuk lain:
 * E.164 (`+62812…`), digit polos (`62812…`), atau lokal — tergantung
 * penyedianya. Kelas ini memvalidasi sekali ke E.164, lalu membentuknya
 * sesuai permintaan gateway.
 *
 * Bawaan negara: Indonesia. Tanpa `+`, hanya bentuk Indonesia yang diterima
 * (08…, 62…, 8…); nomor asing wajib ditulis dengan `+` dan kode negaranya.
 * Nomor Indonesia harus seluler (628…): tautan SMS/WhatsApp ke nomor rumah
 * tidak pernah sampai, dan "terkirim" yang tidak sampai lebih buruk daripada
 * ditolak terbuka.
 */
final class NomorTelepon
{
    public const BENTUK = ['e164', 'digits', 'local'];

    /** Bentuk E.164 (`+62812…`), atau null bila bukan nomor yang masuk akal. */
    public static function e164(?string $mentah): ?string
    {
        $n = trim((string) $mentah);
        if ($n === '' || str_contains($n, '@')) {
            return null;
        }

        $internasional = str_starts_with($n, '+');
        $digit = preg_replace('/\D/', '', $n) ?? '';
        if ($digit === '') {
            return null;
        }

        if ($internasional) {
            $hasil = $digit;
        } elseif (str_starts_with($digit, '00')) {
            $hasil = substr($digit, 2);
        } elseif (str_starts_with($digit, '0')) {
            $hasil = '62'.substr($digit, 1);
        } elseif (str_starts_with($digit, '62')) {
            $hasil = $digit;
        } elseif (str_starts_with($digit, '8')) {
            $hasil = '62'.$digit;
        } else {
            return null;
        }

        $panjang = strlen($hasil);
        if ($panjang < 10 || $panjang > 15) {
            return null;
        }
        if (str_starts_with($hasil, '62') && ! str_starts_with($hasil, '628')) {
            return null;
        }

        return '+'.$hasil;
    }

    public static function valid(?string $mentah): bool
    {
        return self::e164($mentah) !== null;
    }

    /** Bentuk yang diminta gateway. `$e164` harus hasil dari e164(). */
    public static function format(string $e164, string $bentuk): string
    {
        $digit = ltrim($e164, '+');

        return match ($bentuk) {
            'digits' => $digit,
            'local' => str_starts_with($digit, '62') ? '0'.substr($digit, 2) : '+'.$digit,
            default => '+'.$digit,
        };
    }

    /** Untuk log dan audit: hanya empat digit terakhir yang terbaca. */
    public static function samarkan(string $nomor): string
    {
        $digit = preg_replace('/\D/', '', $nomor) ?? '';

        return $digit === '' ? '—' : str_repeat('•', max(0, strlen($digit) - 4)).substr($digit, -4);
    }
}
