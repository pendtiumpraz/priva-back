<?php

namespace Database\Seeders;

use App\Models\MaturityQuestion;
use Illuminate\Database\Seeder;

/**
 * Seeds the 18 Maturity Assessment questions from
 * docs/new_feat/Privacy Compliance Maturity Assessment.pdf §2.
 *
 * Idempotent: updateOrCreate by (question_code, version), so re-running
 * after edits in this file picks up text/scoring guide changes without
 * orphaning existing responses (responses reference question_code +
 * version pair — see maturity_question_responses).
 *
 * Domains map to UU PDP chapters:
 *   - governance              → Pasal 53 (Tata Kelola & DPO)
 *   - processing_basis        → Pasal 20 & 5-13 (Dasar pemrosesan & hak)
 *   - controller_obligations  → Pasal 35-39 (Kewajiban pengendali)
 *   - security                → Pasal 46-48 (Keamanan & kegagalan)
 */
class MaturityQuestionsSeeder extends Seeder
{
    public function run(): void
    {
        $version = 'v1';
        $questions = $this->questions();

        foreach ($questions as $i => $q) {
            MaturityQuestion::updateOrCreate(
                ['question_code' => $q['question_code'], 'version' => $version],
                array_merge($q, [
                    'sort_order' => $i + 1,
                    'is_active' => true,
                    'version' => $version,
                ]),
            );
        }
    }

    /**
     * 18 questions per PDF, ordered as they appear in the spec.
     * scoring_guide carries the level descriptions so the frontend
     * MaturityRuler can show them inline.
     */
    private function questions(): array
    {
        $defaultGuide = [
            '1-3'  => 'Ad-hoc — proses informal, tidak konsisten.',
            '4-6'  => 'Defined — kebijakan tertulis ada, implementasi bervariasi.',
            '7-8'  => 'Managed — terintegrasi, dipantau berkala, ada metrik.',
            '9-10' => 'Optimized — otomatisasi + perbaikan berkelanjutan.',
        ];

        return [
            // ─── Domain A: Tata Kelola & Penunjukan DPO (Pasal 53) ───
            [
                'question_code' => 'A1',
                'domain' => MaturityQuestion::DOMAIN_GOVERNANCE,
                'regulation_ref' => 'UU PDP Pasal 53',
                'question_text' => 'Bagaimana organisasi telah menunjuk Pejabat/Petugas Pelindungan Data Pribadi (DPO) yang memiliki kompetensi di bidang PDP?',
                'description' => 'Penunjukan DPO formal dengan kompetensi PDP yang teruji dan terdokumentasi.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'A2',
                'domain' => MaturityQuestion::DOMAIN_GOVERNANCE,
                'regulation_ref' => 'UU PDP Pasal 53',
                'question_text' => 'Bagaimana sudah ada struktur organisasi dan program kerja PDP yang jelas mengenai tanggung jawab pengelolaan data di setiap unit kerja?',
                'description' => 'Struktur organisasi PDP dengan RACI yang jelas + program kerja terdokumentasi.',
                'scoring_guide' => $defaultGuide,
            ],

            // ─── Domain B: Dasar Pemrosesan & Hak Subjek (Pasal 20 & 5-13) ───
            [
                'question_code' => 'B3',
                'domain' => MaturityQuestion::DOMAIN_PROCESSING_BASIS,
                'regulation_ref' => 'UU PDP Pasal 20',
                'question_text' => 'Bagaimana setiap pemrosesan data memiliki dasar hukum yang sah (misal: persetujuan/consent, kewajiban kontrak, atau kepentingan yang sah)?',
                'description' => 'Setiap aktivitas pemrosesan terdokumentasi dengan dasar hukum di RoPA + LIA untuk kepentingan sah.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'B4',
                'domain' => MaturityQuestion::DOMAIN_PROCESSING_BASIS,
                'regulation_ref' => 'UU PDP Pasal 5-13',
                'question_text' => 'Bagaimana tersedia mekanisme bagi subjek data untuk melaksanakan haknya (seperti hak akses, pemutakhiran, penghapusan, atau penarikan persetujuan)?',
                'description' => 'Mekanisme DSR yang dapat diakses subjek data — biasanya via portal, email, atau form.',
                'scoring_guide' => $defaultGuide,
            ],

            // ─── Domain C: Kewajiban Pengendali & Prosesor (Pasal 35-39) ───
            [
                'question_code' => 'C5',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 35-39',
                'question_text' => 'Bagaimana kualitas Rekam Kegiatan Pemrosesan Data (RoPA) organisasi miliki yang mendokumentasikan jenis data, tujuan, dan jangka waktu retensi?',
                'description' => 'Kelengkapan + akurasi RoPA — semua aktivitas pemrosesan terdaftar dengan field wajib lengkap.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C6',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 34',
                'question_text' => 'Bagaimana kualitas Penilaian Dampak Pelindungan Data (DPIA) untuk pemrosesan data yang berisiko tinggi?',
                'description' => 'DPIA dilakukan untuk setiap RoPA dengan risk_level tinggi.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C7',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 35',
                'question_text' => 'Bagaimana organisasi menjaga peta aliran data (data flow) atau inventarisasi data (data mapping) yang jelas dari mana data berasal, ke mana data mengalir, dan siapa saja pihak ketiga yang terlibat?',
                'description' => 'Data mapping komprehensif via Information Systems / Data Discovery.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C8',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 39',
                'question_text' => 'Bagaimana kontrak tertulis (data processing agreement) dan kontrol yang secara spesifik mengatur kewajiban pelindungan data sesuai Pasal 39?',
                'description' => 'DPA dengan semua data processor, klausul PDP standar masuk kontrak vendor.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C9',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 29',
                'question_text' => 'Bagaimana prosedur rutin untuk memverifikasi akurasi dan memperbarui data pribadi agar tetap akurat, lengkap, dan tidak menyesatkan sesuai Pasal 29?',
                'description' => 'SOP verifikasi + DSR rectification yang efektif.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C10',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 16',
                'question_text' => 'Bagaimana organisasi memastikan bahwa data pribadi yang dikumpulkan hanya digunakan untuk tujuan yang telah dinyatakan sejak awal dan tidak diproses lebih lanjut untuk tujuan yang tidak relevan?',
                'description' => 'Purpose limitation enforcement — kontrol akses berbasis purpose.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C11',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 17',
                'question_text' => 'Bagaimana kebijakan tertulis dan implementasi mengenai masa retensi data dan prosedur pemusnahan data otomatis atau manual setelah masa retensi berakhir atau tujuan pemrosesan tercapai?',
                'description' => 'Retention policy + automated purge yang berfungsi.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C12',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 35',
                'question_text' => 'Bagaimana organisasi telah menerapkan teknik enkripsi untuk data yang sedang dikirim (in-transit) maupun yang disimpan (at-rest), atau melakukan anonimisasi untuk data statistik?',
                'description' => 'TLS 1.2+ in-transit, AES-256 at-rest, anonymization untuk analytics.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C13',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 35',
                'question_text' => 'Bagaimana tata kelola dan implementasi saat organisasi menyusun buku log (internal breach log) yang mencatat setiap upaya akses tidak sah, meskipun upaya tersebut berhasil digagalkan?',
                'description' => 'Centralized logging + breach attempt detection + retention.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C14',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 39',
                'question_text' => 'Bagaimana organisasi melakukan audit/inspeksi secara berkala terhadap fasilitas keamanan yang dimiliki oleh Prosesor Data?',
                'description' => 'Vendor audit recurrence + assessment quality.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C15',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 35',
                'question_text' => 'Bagaimana pengembangan sistem IT atau aplikasi baru, aspek privasi sudah dipertimbangkan sejak tahap perancangan awal (bukan sebagai fitur tambahan di akhir)?',
                'description' => 'Privacy by Design dalam SDLC — privacy review di setiap fase.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'C16',
                'domain' => MaturityQuestion::DOMAIN_CONTROLLER_OBLIGATIONS,
                'regulation_ref' => 'UU PDP Pasal 35',
                'question_text' => 'Bagaimana program untuk seluruh staf yang menyentuh data pribadi telah menandatangani pakta kerahasiaan dan mendapatkan pelatihan berkala mengenai cara menangani data sesuai standar UU PDP?',
                'description' => 'Pakta kerahasiaan + training % completion + periodicity.',
                'scoring_guide' => $defaultGuide,
            ],

            // ─── Domain D: Keamanan & Penanganan Kegagalan (Pasal 46-48) ───
            [
                'question_code' => 'D17',
                'domain' => MaturityQuestion::DOMAIN_SECURITY,
                'regulation_ref' => 'UU PDP Pasal 35 & 46',
                'question_text' => 'Bagaimana organisasi menerapkan langkah teknis dan organisasional (enkripsi, kontrol akses) untuk menjaga keamanan data dari akses ilegal?',
                'description' => 'Holistic security posture — IAM, MFA, network segmentation, monitoring.',
                'scoring_guide' => $defaultGuide,
            ],
            [
                'question_code' => 'D18',
                'domain' => MaturityQuestion::DOMAIN_SECURITY,
                'regulation_ref' => 'UU PDP Pasal 46',
                'question_text' => 'Bagaimana tata kelola dan implementasi SOP mitigasi dan notifikasi dalam hal terjadi kegagalan pelindungan data pribadi (maksimal 3 x 24 jam)?',
                'description' => 'Breach response SOP + < 72h notification consistently met.',
                'scoring_guide' => $defaultGuide,
            ],
        ];
    }
}
