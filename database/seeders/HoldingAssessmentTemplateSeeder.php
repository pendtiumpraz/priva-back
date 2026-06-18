<?php

namespace Database\Seeders;

use App\Models\HoldingAssessmentTemplate;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Seeder Holding Compliance Assessment — kerangka template (TANPA pertanyaan).
 *
 * Membuat DUA template KOSONG siap-isi untuk SETIAP organisasi:
 *   1. "Asesmen Kepatuhan Perlindungan Data" (type=normal — Ya/Sebagian/Tidak)
 *   2. "Asesmen Maturitas Perlindungan Data" (type=maturity — skala 1-5)
 *
 * SENGAJA tidak menyertakan pertanyaan: bank pertanyaan bersifat SPESIFIK per
 * perusahaan, jadi tiap holding mengisi sendiri lewat UI (tab Template → tambah
 * pertanyaan per kategori), lalu publish & dispatch.
 *
 * Status awal 'draft' karena template kosong belum bisa di-dispatch (dispatch
 * butuh minimal satu pertanyaan aktif). Idempotent: template by (org_id, name).
 * Jalankan manual: php artisan db:seed --class=HoldingAssessmentTemplateSeeder
 */
class HoldingAssessmentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $orgs = Organization::all();
        if ($orgs->isEmpty()) {
            $this->command->warn('Tidak ada organisasi. Seed PertaminaHoldingSeeder dulu.');

            return;
        }

        $templates = [
            ['type' => 'normal',   'name' => 'Asesmen Kepatuhan Perlindungan Data',  'desc' => 'Penilaian kepatuhan anak perusahaan (Ya/Sebagian/Tidak). Tambahkan pertanyaan sesuai kebutuhan perusahaan Anda.'],
            ['type' => 'maturity', 'name' => 'Asesmen Maturitas Perlindungan Data',  'desc' => 'Penilaian tingkat kematangan (skala 1-5). Tambahkan pertanyaan sesuai kebutuhan perusahaan Anda.'],
        ];

        $count = 0;
        foreach ($orgs as $org) {
            foreach ($templates as $t) {
                HoldingAssessmentTemplate::updateOrCreate(
                    ['org_id' => $org->id, 'name' => $t['name']],
                    [
                        'description' => $t['desc'],
                        'regulation_code' => null,
                        'regulation_name' => null,
                        'type' => $t['type'],
                        'status' => 'draft',
                    ]
                );
            }
            $count++;
        }

        $this->command->info("✅ 2 template kosong (normal + maturity) di-seed untuk {$count} org. Pertanyaan diisi per perusahaan via UI.");
    }
}
