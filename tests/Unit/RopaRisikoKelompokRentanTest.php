<?php

namespace Tests\Unit;

use App\Services\RopaRiskCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Kelompok rentan memicu risiko TINGGI karena SIAPA subjeknya.
 *
 * Sebelum ini kalkulator hanya membaca `jenis_data_spesifik`, sehingga:
 *
 *   - RoPA yang mencantumkan subjeknya "Anak" tetapi tidak mencentang
 *     "Data Anak" TIDAK memicu apa pun — padahal itu persis pemrosesan yang
 *     dituju PP 33/2026 Pasal 38;
 *   - "Penyandang Disabilitas" ada di `kategori_subjek` tetapi tidak punya
 *     padanan sama sekali di `jenis_data_spesifik`, sehingga Pasal 39 tidak
 *     pernah memicu penilaian dampak lewat jalur mana pun.
 *
 * Diuji tanpa database — kalkulatornya murni fungsi atas larik wizard.
 */
class RopaRisikoKelompokRentanTest extends TestCase
{
    /** @param array<string, mixed> $pengumpulan */
    private function hitung(array $pengumpulan): array
    {
        return (new RopaRiskCalculator)->calculate([
            'pengumpulan_data' => $pengumpulan,
        ]);
    }

    #[Test]
    public function anak_sebagai_kategori_subjek_memicu_risiko_tinggi(): void
    {
        // Tanpa mencentang "Data Anak" di jenis_data_spesifik.
        $hasil = $this->hitung(['kategori_subjek' => ['Pelanggan/Nasabah', 'Anak']]);

        $this->assertSame('high', $hasil['level']);
        $this->assertContains('vulnerable_subjects', $hasil['triggers']);
    }

    #[Test]
    public function penyandang_disabilitas_memicu_risiko_tinggi(): void
    {
        // Inti Pasal 39: opsi ini tidak punya padanan di jenis_data_spesifik,
        // jadi sebelum perbaikan ia tidak pernah memicu apa pun.
        $hasil = $this->hitung(['kategori_subjek' => ['Penyandang Disabilitas']]);

        $this->assertSame('high', $hasil['level']);
        $this->assertContains('vulnerable_subjects', $hasil['triggers']);
    }

    #[Test]
    public function alasannya_menyebut_kelompok_yang_terdeteksi(): void
    {
        $hasil = $this->hitung(['kategori_subjek' => ['Anak', 'Penyandang Disabilitas']]);

        $alasan = implode(' ', $hasil['reasons']);
        $this->assertStringContainsString('Anak', $alasan);
        $this->assertStringContainsString('Penyandang Disabilitas', $alasan);
        // Rujukan pasalnya ikut, supaya pembaca RoPA tahu dasarnya.
        $this->assertStringContainsString('Pasal 38', $alasan);
        $this->assertStringContainsString('Pasal 39', $alasan);
    }

    #[Test]
    public function kategori_subjek_biasa_tidak_memicu(): void
    {
        // Pembanding negatif — tanpa ini uji di atas bisa lulus karena
        // kalkulatornya menandai SEMUA hal sebagai tinggi.
        $hasil = $this->hitung(['kategori_subjek' => ['Karyawan/Pegawai', 'Pelanggan/Nasabah']]);

        $this->assertNotContains('vulnerable_subjects', $hasil['triggers']);
        $this->assertNotSame('high', $hasil['level']);
    }

    #[Test]
    public function jalur_data_spesifik_yang_lama_tetap_bekerja(): void
    {
        // Perbaikan ini MENAMBAH sumbu, bukan menggantikan yang sudah ada.
        $hasil = $this->hitung(['jenis_data_spesifik' => ['Data Kesehatan']]);

        $this->assertSame('high', $hasil['level']);
        $this->assertContains('sensitive_data', $hasil['triggers']);
    }

    #[Test]
    public function keduanya_bisa_menyala_bersamaan(): void
    {
        $hasil = $this->hitung([
            'kategori_subjek' => ['Anak'],
            'jenis_data_spesifik' => ['Data Kesehatan'],
        ]);

        $this->assertContains('sensitive_data', $hasil['triggers']);
        $this->assertContains('vulnerable_subjects', $hasil['triggers']);
    }

    #[Test]
    public function nilai_kosong_dan_not_applicable_diabaikan(): void
    {
        $hasil = $this->hitung(['kategori_subjek' => ['', '  ', 'Not Applicable']]);

        $this->assertNotContains('vulnerable_subjects', $hasil['triggers']);
    }
}
