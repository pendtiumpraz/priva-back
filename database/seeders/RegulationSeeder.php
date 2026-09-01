<?php

namespace Database\Seeders;

use App\Models\Regulation;
use Illuminate\Database\Seeder;

/**
 * Registry regulasi platform. UU PDP + PP 33 = core (wajib, selalu aktif);
 * sisanya add-on opsional (default OFF).
 *
 * Idempotent — updateOrCreate by code.
 */
class RegulationSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['code' => 'uu_pdp', 'name' => 'UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi', 'short' => 'UU PDP', 'category' => 'id_core', 'is_core' => true, 'default_enabled' => true, 'sort' => 1, 'description' => 'Undang-undang pokok pelindungan data pribadi di Indonesia.'],
            ['code' => 'pp_33', 'name' => 'PP No. 33 Tahun 2026 tentang Peraturan Pelaksanaan UU PDP', 'short' => 'PP 33/2026', 'category' => 'id_core', 'is_core' => true, 'default_enabled' => true, 'sort' => 2, 'description' => 'Aturan pelaksana UU PDP 27/2022.'],
            ['code' => 'pojk', 'name' => 'POJK Pelindungan Konsumen & Data Sektor Jasa Keuangan', 'short' => 'POJK', 'category' => 'id_sektoral', 'is_core' => false, 'default_enabled' => false, 'sort' => 10, 'description' => 'Ketentuan OJK terkait pelindungan konsumen dan data di sektor jasa keuangan.'],
            ['code' => 'uu_ite', 'name' => 'UU ITE (No. 11/2008 jo. 19/2016 jo. 1/2024)', 'short' => 'UU ITE', 'category' => 'id_sektoral', 'is_core' => false, 'default_enabled' => false, 'sort' => 11, 'description' => 'Informasi dan Transaksi Elektronik.'],
            ['code' => 'gdpr', 'name' => 'EU General Data Protection Regulation', 'short' => 'GDPR', 'category' => 'internasional', 'is_core' => false, 'default_enabled' => false, 'sort' => 20, 'description' => 'Regulasi pelindungan data Uni Eropa.'],
            ['code' => 'pdpa_sg', 'name' => 'Singapore Personal Data Protection Act', 'short' => 'PDPA (SG)', 'category' => 'internasional', 'is_core' => false, 'default_enabled' => false, 'sort' => 21, 'description' => 'Personal Data Protection Act Singapura.'],
            ['code' => 'iso_27701', 'name' => 'ISO/IEC 27701 Privacy Information Management', 'short' => 'ISO 27701', 'category' => 'standar', 'is_core' => false, 'default_enabled' => false, 'sort' => 30, 'description' => 'Standar manajemen informasi privasi.'],
        ];

        foreach ($rows as $r) {
            Regulation::updateOrCreate(['code' => $r['code']], array_merge($r, ['is_active' => true]));
        }

        $this->command?->info('✅ Regulations registry: '.count($rows).' entry (2 core, '.(count($rows) - 2).' add-on).');
    }
}
