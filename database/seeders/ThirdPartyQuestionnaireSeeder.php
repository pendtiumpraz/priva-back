<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sprint G — Third-party PDP questionnaire bank (v2_2026).
 *
 * Seeds 56 default questions (system-level, org_id = NULL) sesuai
 * Lampiran A di REVISION_PLAN_TPRM_MATURITY_GAP.md (UU PDP 27/2022).
 *
 * Numbering:
 *   - Governance 1-29   → sort_order 1-29,  code GOV-01..GOV-29
 *   - Operation  30-40  → sort_order 30-40, code OPS-30..OPS-40
 *   - People     41-47  → sort_order 41-47, code PPL-41..PPL-47
 *   - Teknologi  48-49 + 51-57 (skip 50)
 *                       → sort_order 48-49, 51-57, code TEK-48, TEK-49, TEK-51..TEK-57
 *
 * Total: 56 rows.
 *
 * Idempotent: keyed by (org_id NULL, question_code, version='v2_2026').
 */
class ThirdPartyQuestionnaireSeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_merge(
            $this->governance(),
            $this->operation(),
            $this->people(),
            $this->teknologi(),
        );

        $now = now();

        foreach ($rows as $row) {
            $payload = array_merge($row, [
                'org_id' => null,
                'parent_id' => null,
                'category' => 'pdp_compliance',
                'version' => 'v2_2026',
                'answer_type' => 'yes_no',
                'answer_options' => json_encode([
                    ['value' => 'yes', 'label' => 'Ya', 'score_contribution' => 1.0],
                    ['value' => 'no', 'label' => 'Tidak', 'score_contribution' => -1.0],
                    ['value' => 'unknown', 'label' => 'Belum tahu', 'score_contribution' => -0.3],
                ]),
                'weight' => 5,
                'direction' => 1,
                'is_active' => true,
                'requires_evidence_upload' => false,
                'updated_at' => $now,
            ]);

            $existing = DB::table('vendor_questionnaires')
                ->whereNull('org_id')
                ->where('question_code', $row['question_code'])
                ->where('version', 'v2_2026')
                ->first();

            if ($existing) {
                DB::table('vendor_questionnaires')
                    ->where('id', $existing->id)
                    ->update($payload);
            } else {
                DB::table('vendor_questionnaires')->insert(array_merge($payload, [
                    'id' => (string) Str::uuid(),
                    'created_at' => $now,
                ]));
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function governance(): array
    {
        return [
            [
                'question_code' => 'GOV-01',
                'section' => 'governance',
                'sort_order' => 1,
                'question_text' => 'Apakah pihak ketiga memiliki Peraturan Perusahaan / SOP / Kebijakan PDP?',
                'description' => 'Perlu disusun seperti Kebijakan Keamanan Informasi.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Susun Peraturan Perusahaan, SOP, atau Kebijakan PDP.',
            ],
            [
                'question_code' => 'GOV-02',
                'section' => 'governance',
                'sort_order' => 2,
                'question_text' => 'Apakah Privacy Notice sudah dipublikasikan?',
                'description' => 'Dokumen eksternal yang diumumkan ke publik tentang pemrosesan data dan hak subjek.',
                'regulation_ref' => 'UU PDP Pasal 16 ayat (2)',
                'recommendation_if_no' => 'Unggah Privacy Notice ke website atau aplikasi.',
            ],
            [
                'question_code' => 'GOV-03',
                'section' => 'governance',
                'sort_order' => 3,
                'question_text' => 'Apakah sudah ditetapkan dasar pemrosesan (legal basis)?',
                'description' => 'Persetujuan, kontrak, kewajiban hukum, kepentingan umum/vital/legitimate interest.',
                'regulation_ref' => 'UU PDP Pasal 20',
                'recommendation_if_no' => 'Tentukan salah satu dasar pemrosesan.',
            ],
            [
                'question_code' => 'GOV-04',
                'section' => 'governance',
                'sort_order' => 4,
                'question_text' => 'Apakah tersedia skema/template/sistem RoPA?',
                'description' => 'Memuat nama Pengendali, DPO, sumber/tujuan, dasar pemrosesan, jenis data, kategori subjek, akses, alur, retensi, langkah keamanan.',
                'regulation_ref' => 'UU PDP Pasal 31',
                'recommendation_if_no' => 'Susun template RoPA sesuai kaidah peraturan.',
            ],
            [
                'question_code' => 'GOV-05',
                'section' => 'governance',
                'sort_order' => 5,
                'question_text' => 'Apakah tersedia skema/template/sistem DPIA?',
                'description' => 'Format asesmen pemrosesan berisiko tinggi beserta mitigasi.',
                'regulation_ref' => 'UU PDP Pasal 34',
                'recommendation_if_no' => 'Tetapkan format pemrosesan data berisiko tinggi beserta mitigasi.',
            ],
            [
                'question_code' => 'GOV-06',
                'section' => 'governance',
                'sort_order' => 6,
                'question_text' => 'Apakah tersedia peraturan/mekanisme DSAR (Data Subject Access Request)?',
                'description' => 'Saluran permohonan hak subjek data.',
                'regulation_ref' => 'UU PDP Pasal 5-14',
                'recommendation_if_no' => 'Susun peraturan/mekanisme DSAR.',
            ],
            [
                'question_code' => 'GOV-07',
                'section' => 'governance',
                'sort_order' => 7,
                'question_text' => 'Apakah tersedia prosedur hak Subjek Data untuk mendapatkan informasi?',
                'description' => 'Prosedur pemenuhan hak subjek data untuk memperoleh informasi pemrosesan.',
                'regulation_ref' => 'UU PDP Pasal 5',
                'recommendation_if_no' => 'Buat prosedur hak mendapatkan informasi.',
            ],
            [
                'question_code' => 'GOV-08',
                'section' => 'governance',
                'sort_order' => 8,
                'question_text' => 'Apakah tersedia prosedur hak melengkapi/memperbaharui/memperbaiki data?',
                'description' => 'Prosedur pemenuhan hak perbaikan data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 6',
                'recommendation_if_no' => 'Susun prosedur perbaikan data.',
            ],
            [
                'question_code' => 'GOV-09',
                'section' => 'governance',
                'sort_order' => 9,
                'question_text' => 'Apakah tersedia prosedur hak salinan dan akses data?',
                'description' => 'Prosedur pemenuhan hak akses dan salinan data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 7',
                'recommendation_if_no' => 'Bentuk prosedur akses salinan data.',
            ],
            [
                'question_code' => 'GOV-10',
                'section' => 'governance',
                'sort_order' => 10,
                'question_text' => 'Apakah tersedia prosedur hak menghapus/memusnahkan data?',
                'description' => 'Prosedur pemenuhan hak penghapusan data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 8',
                'recommendation_if_no' => 'Buat prosedur penghapusan data.',
            ],
            [
                'question_code' => 'GOV-11',
                'section' => 'governance',
                'sort_order' => 11,
                'question_text' => 'Apakah tersedia prosedur hak menarik kembali persetujuan?',
                'description' => 'Prosedur pemenuhan hak penarikan persetujuan oleh subjek data.',
                'regulation_ref' => 'UU PDP Pasal 9',
                'recommendation_if_no' => 'Susun prosedur tarik persetujuan.',
            ],
            [
                'question_code' => 'GOV-12',
                'section' => 'governance',
                'sort_order' => 12,
                'question_text' => 'Apakah tersedia prosedur hak keberatan atas keputusan otomatis?',
                'description' => 'Prosedur pemenuhan hak keberatan terhadap pengambilan keputusan otomatis.',
                'regulation_ref' => 'UU PDP Pasal 10',
                'recommendation_if_no' => 'Bentuk prosedur keberatan otomasi.',
            ],
            [
                'question_code' => 'GOV-13',
                'section' => 'governance',
                'sort_order' => 13,
                'question_text' => 'Apakah tersedia prosedur hak menunda/membatasi pemrosesan?',
                'description' => 'Prosedur pemenuhan hak pembatasan pemrosesan data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 11',
                'recommendation_if_no' => 'Buat prosedur pembatasan pemrosesan.',
            ],
            [
                'question_code' => 'GOV-14',
                'section' => 'governance',
                'sort_order' => 14,
                'question_text' => 'Apakah tersedia prosedur hak gugat dan ganti rugi?',
                'description' => 'Prosedur pemenuhan hak gugatan dan ganti rugi oleh subjek data.',
                'regulation_ref' => 'UU PDP Pasal 12',
                'recommendation_if_no' => 'Susun prosedur gugat dan ganti rugi.',
            ],
            [
                'question_code' => 'GOV-15',
                'section' => 'governance',
                'sort_order' => 15,
                'question_text' => 'Apakah tersedia prosedur data portability?',
                'description' => 'Prosedur pemenuhan hak portabilitas data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 13',
                'recommendation_if_no' => 'Buat prosedur data portability.',
            ],
            [
                'question_code' => 'GOV-16',
                'section' => 'governance',
                'sort_order' => 16,
                'question_text' => 'Apakah tersedia mekanisme manajemen risiko pihak ketiga?',
                'description' => 'Deteksi tinggi rendahnya risiko kerjasama dengan pihak ketiga.',
                'regulation_ref' => 'UU PDP Pasal 37',
                'recommendation_if_no' => 'Kembangkan peraturan manajemen risiko pihak ketiga.',
            ],
            [
                'question_code' => 'GOV-17',
                'section' => 'governance',
                'sort_order' => 17,
                'question_text' => 'Apakah tersedia Kebijakan Retensi Data?',
                'description' => 'Pengaturan seberapa lama data disimpan.',
                'regulation_ref' => 'UU PDP Pasal 42',
                'recommendation_if_no' => 'Tetapkan Kebijakan Retensi Data.',
            ],
            [
                'question_code' => 'GOV-18',
                'section' => 'governance',
                'sort_order' => 18,
                'question_text' => 'Apakah tersedia mekanisme pemusnahan data?',
                'description' => 'Bagaimana data dihapus di dalam organisasi.',
                'regulation_ref' => 'UU PDP Pasal 44',
                'recommendation_if_no' => 'Susun mekanisme pemusnahan data.',
            ],
            [
                'question_code' => 'GOV-19',
                'section' => 'governance',
                'sort_order' => 19,
                'question_text' => 'Apakah tersedia struktur organisasi PPDP (Pejabat Pelindungan Data Pribadi)?',
                'description' => 'Kejelasan posisi PPDP di bawah direktorat mana.',
                'regulation_ref' => 'UU PDP Pasal 53',
                'recommendation_if_no' => 'Tetapkan struktur organisasi PPDP.',
            ],
            [
                'question_code' => 'GOV-20',
                'section' => 'governance',
                'sort_order' => 20,
                'question_text' => 'Apakah tersedia pola hubungan PPDP dengan Unit Kerja Lain beserta KPI-nya?',
                'description' => 'Pola hubungan dan indikator kepatuhan PPDP.',
                'regulation_ref' => 'UU PDP Pasal 53',
                'recommendation_if_no' => 'Tentukan hubungan dan KPI PPDP.',
            ],
            [
                'question_code' => 'GOV-21',
                'section' => 'governance',
                'sort_order' => 21,
                'question_text' => 'Apakah tersedia kebijakan dan mekanisme transfer data ke luar negeri?',
                'description' => 'Persiapan, mekanisme, dan protokol transfer data ke luar negeri.',
                'regulation_ref' => 'UU PDP Pasal 56',
                'recommendation_if_no' => 'Susun kebijakan transfer data ke luar negeri.',
            ],
            [
                'question_code' => 'GOV-22',
                'section' => 'governance',
                'sort_order' => 22,
                'question_text' => 'Apakah tersedia prosedur TIA (Transfer Impact Assessment)?',
                'description' => 'Asesmen risiko transfer ke negara lain mencakup kecakapan penerima, tujuan, legal basis, dan mitigasi.',
                'regulation_ref' => 'UU PDP Pasal 56 ayat (2) dan (3)',
                'recommendation_if_no' => 'Buat prosedur TIA.',
            ],
            [
                'question_code' => 'GOV-23',
                'section' => 'governance',
                'sort_order' => 23,
                'question_text' => 'Apakah tersedia kebijakan pemrosesan data Anak?',
                'description' => 'Persyaratan persetujuan orang tua atau wali untuk pemrosesan data Anak.',
                'regulation_ref' => 'UU PDP Pasal 25',
                'recommendation_if_no' => 'Buat kebijakan pemrosesan data Anak.',
            ],
            [
                'question_code' => 'GOV-24',
                'section' => 'governance',
                'sort_order' => 24,
                'question_text' => 'Apakah tersedia kebijakan pemrosesan data Penyandang Disabilitas?',
                'description' => 'Fasilitas, asisten virtual, dan teknologi khusus untuk Penyandang Disabilitas.',
                'regulation_ref' => 'UU PDP Pasal 26',
                'recommendation_if_no' => 'Susun kebijakan pemrosesan data Penyandang Disabilitas.',
            ],
            [
                'question_code' => 'GOV-25',
                'section' => 'governance',
                'sort_order' => 25,
                'question_text' => 'Apakah tersedia Kebijakan Keamanan Siber?',
                'description' => 'Identifikasi aset, manajemen risiko, dan kontrol aset.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Tetapkan kebijakan Keamanan Siber.',
            ],
            [
                'question_code' => 'GOV-26',
                'section' => 'governance',
                'sort_order' => 26,
                'question_text' => 'Apakah tersedia prosedur Manajemen Kebocoran Data?',
                'description' => 'Pengumpulan bahan, isolasi, dan analisis insiden.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Tetapkan prosedur Manajemen Kebocoran Data.',
            ],
            [
                'question_code' => 'GOV-27',
                'section' => 'governance',
                'sort_order' => 27,
                'question_text' => 'Apakah tersedia prosedur hubungan dengan otoritas?',
                'description' => 'Aktivitas dan cara menyikapi otoritas pemangku kebijakan.',
                'regulation_ref' => 'UU PDP Pasal 46',
                'recommendation_if_no' => 'Tentukan prosedur hubungan dengan otoritas.',
            ],
            [
                'question_code' => 'GOV-28',
                'section' => 'governance',
                'sort_order' => 28,
                'question_text' => 'Apakah tersedia template Kontrak Pihak Ketiga PDP?',
                'description' => 'Pokok yang diatur dalam kontrak pemrosesan dengan pihak ketiga.',
                'regulation_ref' => 'UU PDP Pasal 23',
                'recommendation_if_no' => 'Pastikan ketersediaan template Kontrak Pihak Ketiga PDP.',
            ],
            [
                'question_code' => 'GOV-29',
                'section' => 'governance',
                'sort_order' => 29,
                'question_text' => 'Apakah tersedia Kebijakan Manajemen Persetujuan?',
                'description' => 'Formulasi bahasa untuk persetujuan subjek data.',
                'regulation_ref' => 'UU PDP Pasal 24',
                'recommendation_if_no' => 'Tentukan Kebijakan Manajemen Persetujuan.',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function operation(): array
    {
        return [
            [
                'question_code' => 'OPS-30',
                'section' => 'operation',
                'sort_order' => 30,
                'question_text' => 'Apakah RoPA (laporan perekaman aktivitas) sudah dikerjakan sesuai template?',
                'description' => 'Pelaksanaan pencatatan aktivitas pemrosesan data sesuai template RoPA.',
                'regulation_ref' => 'UU PDP Pasal 31',
                'recommendation_if_no' => 'Kerjakan laporan RoPA.',
            ],
            [
                'question_code' => 'OPS-31',
                'section' => 'operation',
                'sort_order' => 31,
                'question_text' => 'Apakah DPIA (laporan aktivitas berisiko tinggi) sudah disusun?',
                'description' => 'Pelaksanaan asesmen dampak pemrosesan berisiko tinggi.',
                'regulation_ref' => 'UU PDP Pasal 34',
                'recommendation_if_no' => 'Susun laporan DPIA.',
            ],
            [
                'question_code' => 'OPS-32',
                'section' => 'operation',
                'sort_order' => 32,
                'question_text' => 'Apakah Permintaan dan Pemenuhan Hak Subjek Data dipenuhi dalam 3×24 jam?',
                'description' => 'Dokumentasi dan saluran pemenuhan hak subjek data sesuai tenggat waktu.',
                'regulation_ref' => 'UU PDP Pasal 5-14',
                'recommendation_if_no' => 'Dokumentasikan dan sediakan saluran pemenuhan hak subjek data.',
            ],
            [
                'question_code' => 'OPS-33',
                'section' => 'operation',
                'sort_order' => 33,
                'question_text' => 'Apakah implementasi manajemen risiko pihak ketiga sudah dijalankan?',
                'description' => 'Pelaksanaan manajemen risiko terhadap pihak ketiga.',
                'regulation_ref' => 'UU PDP Pasal 37',
                'recommendation_if_no' => 'Laksanakan implementasi manajemen risiko pihak ketiga.',
            ],
            [
                'question_code' => 'OPS-34',
                'section' => 'operation',
                'sort_order' => 34,
                'question_text' => 'Apakah validasi akurasi, kelengkapan, dan konsistensi data sudah dilakukan?',
                'description' => 'Pelaksanaan validasi kualitas data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 29',
                'recommendation_if_no' => 'Jalankan validasi data.',
            ],
            [
                'question_code' => 'OPS-35',
                'section' => 'operation',
                'sort_order' => 35,
                'question_text' => 'Apakah drafting dan review Kontrak Pihak Ketiga PDP sudah dilaksanakan?',
                'description' => 'Pelaksanaan penyusunan dan peninjauan kontrak pihak ketiga.',
                'regulation_ref' => 'UU PDP Pasal 23',
                'recommendation_if_no' => 'Laksanakan drafting dan review kontrak pihak ketiga.',
            ],
            [
                'question_code' => 'OPS-36',
                'section' => 'operation',
                'sort_order' => 36,
                'question_text' => 'Apakah implementasi Manajemen Persetujuan sudah dijalankan?',
                'description' => 'Pelaksanaan pengelolaan persetujuan subjek data.',
                'regulation_ref' => 'UU PDP Pasal 24',
                'recommendation_if_no' => 'Implementasikan Manajemen Persetujuan.',
            ],
            [
                'question_code' => 'OPS-37',
                'section' => 'operation',
                'sort_order' => 37,
                'question_text' => 'Apakah penghapusan data dan Berita Acara Pemusnahan sudah dijalankan?',
                'description' => 'Pelaksanaan penghapusan data dengan dokumentasi Berita Acara Pemusnahan.',
                'regulation_ref' => 'UU PDP Pasal 44',
                'recommendation_if_no' => 'Jalankan penghapusan data dan Berita Acara Pemusnahan.',
            ],
            [
                'question_code' => 'OPS-38',
                'section' => 'operation',
                'sort_order' => 38,
                'question_text' => 'Apakah informasi pemrosesan data pada CCTV sudah dipasang?',
                'description' => 'Pemberitahuan dan checklist pemrosesan data pada CCTV.',
                'regulation_ref' => 'UU PDP Pasal 17',
                'recommendation_if_no' => 'Pasang informasi PDP pada CCTV.',
            ],
            [
                'question_code' => 'OPS-39',
                'section' => 'operation',
                'sort_order' => 39,
                'question_text' => 'Apakah tersedia sistem untuk pemrosesan data Anak?',
                'description' => 'Penyediaan sistem khusus untuk pemrosesan data Anak.',
                'regulation_ref' => 'UU PDP Pasal 25',
                'recommendation_if_no' => 'Pastikan sistem khusus untuk pemrosesan data Anak.',
            ],
            [
                'question_code' => 'OPS-40',
                'section' => 'operation',
                'sort_order' => 40,
                'question_text' => 'Apakah tersedia sistem untuk Penyandang Disabilitas?',
                'description' => 'Penyediaan sistem khusus untuk Penyandang Disabilitas.',
                'regulation_ref' => 'UU PDP Pasal 26',
                'recommendation_if_no' => 'Pastikan sistem khusus untuk Penyandang Disabilitas.',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function people(): array
    {
        return [
            [
                'question_code' => 'PPL-41',
                'section' => 'people',
                'sort_order' => 41,
                'question_text' => 'Apakah sudah ada penunjukan PPDP beserta Surat Keputusan?',
                'description' => 'Penunjukan resmi Pejabat Pelindungan Data Pribadi melalui Surat Keputusan.',
                'regulation_ref' => 'UU PDP Pasal 53',
                'recommendation_if_no' => 'Lakukan penunjukan PPDP beserta Surat Keputusan.',
            ],
            [
                'question_code' => 'PPL-42',
                'section' => 'people',
                'sort_order' => 42,
                'question_text' => 'Apakah pelatihan PPDP sudah dilaksanakan?',
                'description' => 'Pelaksanaan pelatihan untuk Pejabat Pelindungan Data Pribadi.',
                'regulation_ref' => 'UU PDP Pasal 53',
                'recommendation_if_no' => 'Selenggarakan pelatihan PPDP.',
            ],
            [
                'question_code' => 'PPL-43',
                'section' => 'people',
                'sort_order' => 43,
                'question_text' => 'Apakah PPDP sudah memiliki sertifikasi, pendidikan formal, dan kompetensi yang memadai?',
                'description' => 'Kelengkapan sertifikasi, pendidikan formal, dan kompetensi PPDP.',
                'regulation_ref' => 'UU PDP Pasal 53',
                'recommendation_if_no' => 'Lengkapi sertifikasi, pendidikan formal, dan kompetensi PPDP.',
            ],
            [
                'question_code' => 'PPL-44',
                'section' => 'people',
                'sort_order' => 44,
                'question_text' => 'Apakah pelatihan untuk Pimpinan Organisasi sudah dilaksanakan?',
                'description' => 'Pelaksanaan pelatihan PDP untuk pimpinan organisasi.',
                'regulation_ref' => 'UU PDP Pasal 16, 53, 54',
                'recommendation_if_no' => 'Selenggarakan pelatihan untuk Pimpinan Organisasi.',
            ],
            [
                'question_code' => 'PPL-45',
                'section' => 'people',
                'sort_order' => 45,
                'question_text' => 'Apakah pelatihan untuk karyawan (champion PDP) sudah dilaksanakan?',
                'description' => 'Pelaksanaan pelatihan untuk karyawan sebagai champion PDP.',
                'regulation_ref' => 'UU PDP Pasal 53, 54',
                'recommendation_if_no' => 'Selenggarakan pelatihan karyawan (champion PDP).',
            ],
            [
                'question_code' => 'PPL-46',
                'section' => 'people',
                'sort_order' => 46,
                'question_text' => 'Apakah awareness dan campaign PDP untuk seluruh karyawan sudah dilakukan?',
                'description' => 'Pelaksanaan awareness dan campaign PDP untuk seluruh karyawan.',
                'regulation_ref' => 'UU PDP Pasal 53, 54',
                'recommendation_if_no' => 'Selenggarakan awareness dan campaign PDP untuk seluruh karyawan.',
            ],
            [
                'question_code' => 'PPL-47',
                'section' => 'people',
                'sort_order' => 47,
                'question_text' => 'Apakah awareness PDP untuk pihak ketiga sudah dilakukan?',
                'description' => 'Pelaksanaan awareness PDP terhadap pihak ketiga.',
                'regulation_ref' => 'UU PDP Pasal 53, 54',
                'recommendation_if_no' => 'Selenggarakan awareness PDP untuk pihak ketiga.',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function teknologi(): array
    {
        return [
            [
                'question_code' => 'TEK-48',
                'section' => 'technology',
                'sort_order' => 48,
                'question_text' => 'Apakah sudah diterapkan teknologi PDP (RoPA / DPIA / Consent Management)?',
                'description' => 'Penerapan teknologi pendukung pemrosesan data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 27, 28, 29',
                'recommendation_if_no' => 'Terapkan teknologi PDP (RoPA / DPIA / Consent Management).',
            ],
            [
                'question_code' => 'TEK-49',
                'section' => 'technology',
                'sort_order' => 49,
                'question_text' => 'Apakah enkripsi data pribadi sudah diterapkan?',
                'description' => 'Penerapan enkripsi terhadap data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Terapkan enkripsi data pribadi.',
            ],
            [
                'question_code' => 'TEK-51',
                'section' => 'technology',
                'sort_order' => 51,
                'question_text' => 'Apakah teknologi penghapusan data (data erasure) sudah diterapkan?',
                'description' => 'Penerapan teknologi penghapusan data secara aman.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Terapkan teknologi penghapusan data (data erasure).',
            ],
            [
                'question_code' => 'TEK-52',
                'section' => 'technology',
                'sort_order' => 52,
                'question_text' => 'Apakah pencadangan data (immutable back-up) sudah diterapkan?',
                'description' => 'Penerapan pencadangan data yang bersifat immutable.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Terapkan pencadangan data (immutable back-up).',
            ],
            [
                'question_code' => 'TEK-53',
                'section' => 'technology',
                'sort_order' => 53,
                'question_text' => 'Apakah pseudonimisasi atau masking data sudah diterapkan?',
                'description' => 'Penerapan pseudonimisasi atau masking pada data pribadi.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Terapkan pseudonimisasi atau masking data.',
            ],
            [
                'question_code' => 'TEK-54',
                'section' => 'technology',
                'sort_order' => 54,
                'question_text' => 'Apakah Vulnerability Assessment dan Pentest dilaksanakan secara berkala?',
                'description' => 'Pelaksanaan Vulnerability Assessment dan Penetration Test secara berkala.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Laksanakan Vulnerability Assessment dan Pentest secara berkala.',
            ],
            [
                'question_code' => 'TEK-55',
                'section' => 'technology',
                'sort_order' => 55,
                'question_text' => 'Apakah Security Operation Center (SOC) sudah tersedia?',
                'description' => 'Ketersediaan Security Operation Center untuk pemantauan keamanan.',
                'regulation_ref' => 'UU PDP Pasal 35',
                'recommendation_if_no' => 'Sediakan Security Operation Center (SOC).',
            ],
            [
                'question_code' => 'TEK-56',
                'section' => 'technology',
                'sort_order' => 56,
                'question_text' => 'Apakah teknologi pemrosesan data Anak (verifikasi umur) sudah diterapkan?',
                'description' => 'Penerapan teknologi verifikasi umur untuk pemrosesan data Anak.',
                'regulation_ref' => 'UU PDP Pasal 25',
                'recommendation_if_no' => 'Terapkan teknologi pemrosesan data Anak (verifikasi umur).',
            ],
            [
                'question_code' => 'TEK-57',
                'section' => 'technology',
                'sort_order' => 57,
                'question_text' => 'Apakah teknologi khusus untuk Penyandang Disabilitas sudah diterapkan?',
                'description' => 'Penerapan teknologi pendukung khusus bagi Penyandang Disabilitas.',
                'regulation_ref' => 'UU PDP Pasal 26',
                'recommendation_if_no' => 'Terapkan teknologi khusus untuk Penyandang Disabilitas.',
            ],
        ];
    }
}
