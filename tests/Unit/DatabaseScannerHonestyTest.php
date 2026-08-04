<?php

namespace Tests\Unit;

use App\Services\DatabaseScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mengunci satu sifat yang lebih penting daripada kelengkapan fitur: pemindai
 * tidak boleh melaporkan keberhasilan yang tidak pernah terjadi.
 *
 * Sebelum perbaikan ini, MSSQL, Oracle, dan MongoDB tidak pernah menyambung ke
 * apa pun. `testConnection` mengembalikan `success => true` beserta versi server
 * karangan ("Microsoft SQL Server 2022 (RTM) - 16.0.1000.6"), dan `scanSchema`
 * mengembalikan tabel contoh tanpa penanda apa pun. Pada evaluasi PoC, hasil
 * seperti itu jauh lebih merusak daripada fitur yang memang belum ada — penguji
 * mengambil kesimpulan di atas data yang tidak pernah berasal dari sistemnya.
 *
 * Test ini sengaja bergantung pada ketiadaan driver di lingkungan CI: itulah
 * kondisi yang dulu memicu sukses palsu. Bila suatu saat driver dipasang,
 * test yang bersangkutan dilewati, bukan dianggap lulus diam-diam.
 */
class DatabaseScannerHonestyTest extends TestCase
{
    private function pdoDriverAbsent(array $candidates): bool
    {
        return empty(array_intersect($candidates, \PDO::getAvailableDrivers()));
    }

    #[Test]
    public function mssql_tanpa_driver_melapor_gagal_bukan_sukses_palsu(): void
    {
        if (! $this->pdoDriverAbsent(['sqlsrv', 'dblib'])) {
            $this->markTestSkipped('Driver SQL Server tersedia — jalur ini tidak berlaku.');
        }

        $result = DatabaseScanner::testConnection('mssql', ['host' => 'db.internal', 'database' => 'core']);

        $this->assertFalse($result['success'], 'Koneksi tanpa driver tidak boleh dilaporkan berhasil.');
        $this->assertTrue($result['driver_missing'] ?? false);
        $this->assertArrayNotHasKey('server_version', $result, 'Versi server tidak boleh dikarang saat tidak ada koneksi.');
        $this->assertArrayNotHasKey('tables_found', $result, 'Jumlah tabel tidak boleh dikarang saat tidak ada koneksi.');
    }

    #[Test]
    public function oracle_tanpa_driver_melapor_gagal_bukan_sukses_palsu(): void
    {
        if (! $this->pdoDriverAbsent(['oci', 'oci8'])) {
            $this->markTestSkipped('Driver Oracle tersedia — jalur ini tidak berlaku.');
        }

        $result = DatabaseScanner::testConnection('oracle', ['host' => 'db.internal', 'service_name' => 'ORCL']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['driver_missing'] ?? false);
        $this->assertArrayNotHasKey('server_version', $result);
    }

    #[Test]
    public function mongodb_tanpa_ekstensi_melapor_gagal_bukan_sukses_palsu(): void
    {
        if (extension_loaded('mongodb')) {
            $this->markTestSkipped('Ekstensi MongoDB tersedia — jalur ini tidak berlaku.');
        }

        $result = DatabaseScanner::testConnection('mongodb', ['host' => 'db.internal', 'database' => 'core']);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['driver_missing'] ?? false);
        $this->assertArrayNotHasKey('collections_found', $result);
    }

    #[Test]
    public function scan_tanpa_driver_mengembalikan_kosong_dan_menjelaskan_sebabnya(): void
    {
        $cases = [
            'mssql' => ['sqlsrv', 'dblib'],
            'oracle' => ['oci', 'oci8'],
        ];

        foreach ($cases as $sourceType => $drivers) {
            if (! $this->pdoDriverAbsent($drivers)) {
                continue;
            }

            $result = DatabaseScanner::scanSchema($sourceType, ['host' => 'x', 'database' => 'y']);

            $this->assertSame([], $result['tables'], "[{$sourceType}] tidak boleh mengembalikan tabel karangan.");
            $this->assertNotEmpty($result['error'] ?? null, "[{$sourceType}] wajib menjelaskan kegagalannya.");
            $this->assertTrue($result['driver_missing'] ?? false, "[{$sourceType}] wajib menandai driver yang hilang.");
        }
    }

    #[Test]
    public function data_contoh_selalu_menandai_dirinya_sendiri(): void
    {
        $result = DatabaseScanner::simulateScan('mysql');

        $this->assertTrue($result['simulated'], 'Data contoh wajib membawa penanda simulated.');
        $this->assertNotEmpty($result['simulated_reason']);
        $this->assertSame('simulated', $result['engine']);
        $this->assertNotEmpty($result['tables'], 'Data contoh tetap harus berisi agar berguna untuk peragaan.');
    }

    #[Test]
    public function hasil_pemindaian_nyata_tidak_pernah_ditandai_simulated(): void
    {
        // Sumber tak dikenal menempuh cabang default: gagal, dan yang penting
        // di sini adalah ia TIDAK menyulih data contoh diam-diam.
        $result = DatabaseScanner::scanSchema('sumber_tidak_dikenal', []);

        $this->assertSame([], $result['tables']);
        $this->assertNotEmpty($result['error'] ?? null);
        $this->assertFalse($result['simulated'] ?? false);
    }
}
