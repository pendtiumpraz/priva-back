<?php

namespace Tests\Unit;

use App\Support\AssignmentScope;
use App\Support\PenugasanDivisi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Aturan penggabungan divisi — diuji tanpa database.
 *
 * Ini bagian yang paling mudah salah secara diam-diam: daftar kosong berarti
 * "(All Group)", sehingga kalau saatBuat() memperlakukannya seperti daftar biasa
 * maka seluruh fitur auto-assign tidak pernah terjadi — record barunya tetap
 * NULL dan terbaca seluruh tenant, persis keadaan yang mau diperbaiki.
 */
class PenugasanDivisiTest extends TestCase
{
    #[Test]
    public function daftar_kosong_dianggap_memuat_semua_divisi(): void
    {
        $this->assertTrue(PenugasanDivisi::memuat(null, 'HR'));
        $this->assertTrue(PenugasanDivisi::memuat('', 'HR'));
        $this->assertTrue(PenugasanDivisi::memuat(AssignmentScope::SEMUA, 'HR'));
    }

    #[Test]
    public function memuat_mencocokkan_nama_utuh_bukan_potongan(): void
    {
        // 'HR' tidak boleh ikut cocok dengan 'HRD'.
        $this->assertTrue(PenugasanDivisi::memuat('HR', 'HR'));
        $this->assertFalse(PenugasanDivisi::memuat('HRD', 'HR'));
        $this->assertTrue(PenugasanDivisi::memuat('Keuangan'.AssignmentScope::DELIM.'HR', 'HR'));
        $this->assertFalse(PenugasanDivisi::memuat('Keuangan'.AssignmentScope::DELIM.'HRD', 'HR'));
    }

    #[Test]
    public function gabung_menambahkan_di_akhir_tanpa_mengubah_urutan(): void
    {
        $this->assertSame(
            'Keuangan'.AssignmentScope::DELIM.'HR',
            PenugasanDivisi::gabung('Keuangan', 'HR'),
        );
    }

    #[Test]
    public function gabung_tidak_menduplikasi(): void
    {
        $daftar = 'Keuangan'.AssignmentScope::DELIM.'HR';
        $this->assertSame($daftar, PenugasanDivisi::gabung($daftar, 'HR'));
    }

    #[Test]
    public function gabung_membiarkan_penugasan_terbuka_apa_adanya(): void
    {
        // Memaksa divisi asal masuk ke "(All Group)" justru MENYEMPITKAN
        // penugasan yang sengaja dibuat terbuka.
        $this->assertNull(PenugasanDivisi::gabung(null, 'HR'));
        $this->assertSame(AssignmentScope::SEMUA, PenugasanDivisi::gabung(AssignmentScope::SEMUA, 'HR'));
    }

    #[Test]
    public function string_kosong_dinormalkan_jadi_null_bukan_disimpan_apa_adanya(): void
    {
        // AssignmentScope berbunyi `whereNull(assign_group) OR = '(All Group)'`.
        // String kosong tidak cocok dengan keduanya, dan tidak cocok pula
        // dengan klausa divisi mana pun — menyimpannya membuat record LENYAP
        // dari semua orang kecuali pembuatnya, tanpa satu pun galat.
        $this->assertNull(PenugasanDivisi::gabung('', 'HR'));
        $this->assertNull(PenugasanDivisi::gabung('   ', 'HR'));

        // Jalur yang bisa menghasilkannya di kode sungguhan: orang mengosongkan
        // isian penugasan saat menyunting.
        $hasil = PenugasanDivisi::saatUbah(['assign_group' => ''], 'HR');
        $this->assertNull($hasil['assign_group']);
    }

    #[Test]
    public function saat_ubah_mengembalikan_divisi_asal_yang_dicoba_dilepas(): void
    {
        $hasil = PenugasanDivisi::saatUbah(['assign_group' => 'Keuangan'], 'HR');

        $this->assertSame('Keuangan'.AssignmentScope::DELIM.'HR', $hasil['assign_group']);
    }

    #[Test]
    public function saat_ubah_membiarkan_perluasan_ke_semua_divisi(): void
    {
        // Menambah divisi lain atau memperluas ke semua memang boleh.
        $hasil = PenugasanDivisi::saatUbah(['assign_group' => AssignmentScope::SEMUA], 'HR');

        $this->assertSame(AssignmentScope::SEMUA, $hasil['assign_group']);
    }

    #[Test]
    public function saat_ubah_tidak_menyentuh_penugasan_yang_tidak_diubah(): void
    {
        // Payload yang tidak menyebut assign_group sama sekali tidak boleh
        // tiba-tiba mendapat kolom itu — update parsial harus tetap parsial.
        $hasil = PenugasanDivisi::saatUbah(['title' => 'Apa saja'], 'HR');

        $this->assertArrayNotHasKey('assign_group', $hasil);
    }

    #[Test]
    public function divisi_asal_tidak_bisa_disetel_lewat_payload(): void
    {
        // Tanpa penjagaan ini, satu baris di payload cukup untuk melepas
        // kuncinya sendiri.
        $hasil = PenugasanDivisi::saatUbah([
            'origin_division' => 'Keuangan',
            'assign_group' => 'Keuangan',
        ], 'HR');

        $this->assertArrayNotHasKey('origin_division', $hasil);
        $this->assertSame('Keuangan'.AssignmentScope::DELIM.'HR', $hasil['assign_group']);
    }

    #[Test]
    public function saat_ubah_tanpa_divisi_asal_tidak_mengunci_apa_pun(): void
    {
        $hasil = PenugasanDivisi::saatUbah(['assign_group' => 'Keuangan'], null);

        $this->assertSame('Keuangan', $hasil['assign_group']);
    }
}
