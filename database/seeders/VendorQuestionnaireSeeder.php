<?php

namespace Database\Seeders;

use App\Models\VendorQuestionnaire;
use Illuminate\Database\Seeder;

/**
 * Phase 2 — Vendor questionnaire bank v1.
 *
 * Three categories cover ~85% of FI vendor scenarios under UU PDP +
 * POJK 11/2022 + ISO 27001 Annex A. Each question has:
 *   - regulation_ref: which clause it maps to (audit defense)
 *   - weight 1-10:    how much this question moves the score
 *   - direction +1/-1: does a "good" answer raise (+1) or lower (-1) score
 *   - answer_options: per-option score_contribution in -1.0..+1.0 range
 *
 * Score formula in VendorRiskScoreService:
 *   contribution = weight × direction × answer_normalized
 *   final = clamp(0, 100, 50 + sum(contributions))
 *
 * v1 question codes:
 *   CLD-01..CLD-15  Cloud Infrastructure
 *   SAAS-01..SAAS-12 SaaS Application
 *   PROC-01..PROC-12 Data Processor
 */
class VendorQuestionnaireSeeder extends Seeder
{
    public function run(): void
    {
        $rows = array_merge(
            $this->cloudInfrastructure(),
            $this->saasApplication(),
            $this->dataProcessor(),
        );

        foreach ($rows as $i => $row) {
            VendorQuestionnaire::query()->updateOrCreate(
                ['category' => $row['category'], 'version' => $row['version'], 'question_code' => $row['question_code']],
                array_merge($row, ['sort_order' => $i]),
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function cloudInfrastructure(): array
    {
        return [
            // ─── Section: Governance ─────────────────────────────────────
            $this->yesNo('CLD-01', 'cloud_infrastructure', 'governance',
                'Apakah vendor memiliki sertifikasi SOC 2 Type II yang aktif?',
                'SOC 2 Type II = audit independen ≥ 6 bulan operasional. Bukti kontrol keamanan benar-benar dijalankan, bukan hanya didokumentasikan.',
                'ISO 27001 Annex A.6.1.1', weight: 8, direction: 1, yesScore: 1.0, noScore: -0.5),

            $this->yesNo('CLD-02', 'cloud_infrastructure', 'governance',
                'Apakah vendor memiliki sertifikasi ISO 27001:2022 yang aktif?',
                'ISO 27001 = framework manajemen keamanan informasi internasional. Wajib di-renew setiap 3 tahun.',
                'ISO 27001', weight: 7, direction: 1, yesScore: 1.0, noScore: -0.4),

            // ─── Section: Data Handling ──────────────────────────────────
            $this->multiChoice('CLD-03', 'cloud_infrastructure', 'data_handling',
                'Di region mana data utama Anda akan di-host?',
                'UU PDP Pasal 56 mensyaratkan transfer ke negara dengan tingkat perlindungan setara, atau menggunakan SCCs/BCR.',
                'UU PDP Pasal 56', weight: 9, direction: 1, options: [
                    ['value' => 'indonesia',    'label' => 'Indonesia (data center lokal)',     'score_contribution' => 1.0],
                    ['value' => 'asean',        'label' => 'ASEAN (Singapore, Malaysia, dll)',  'score_contribution' => 0.6],
                    ['value' => 'gdpr_adequate','label' => 'GDPR adequate (EU, UK, Japan, KR, dll)', 'score_contribution' => 0.4],
                    ['value' => 'usa',          'label' => 'United States',                      'score_contribution' => 0.0],
                    ['value' => 'china_russia', 'label' => 'China / Russia / Iran',              'score_contribution' => -1.0],
                    ['value' => 'other',        'label' => 'Lainnya / belum tahu',               'score_contribution' => -0.4],
                ]),

            $this->yesNo('CLD-04', 'cloud_infrastructure', 'security',
                'Apakah enkripsi at-rest aktif secara default (AES-256 atau setara)?',
                'Enkripsi at-rest mencegah pembacaan data dari disk yang dicuri atau backup yang bocor.',
                'POJK 11/2022 Pasal 27', weight: 9, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->yesNo('CLD-05', 'cloud_infrastructure', 'security',
                'Apakah enkripsi in-transit (TLS 1.2 ke atas) wajib untuk semua koneksi?',
                'TLS 1.2+ mencegah eavesdropping di network. TLS 1.0/1.1 sudah deprecated.',
                'POJK 11/2022 Pasal 27', weight: 7, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->yesNo('CLD-06', 'cloud_infrastructure', 'security',
                'Apakah Multi-Factor Authentication (MFA) wajib untuk akses admin/operator?',
                'MFA mencegah credential leak menjadi full compromise.',
                'POJK 11/2022 Pasal 27', weight: 6, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->yesNo('CLD-07', 'cloud_infrastructure', 'security',
                'Apakah audit log accessible by tenant dan retained ≥ 1 tahun?',
                'Audit log diperlukan untuk forensik insiden + bukti audit kepatuhan.',
                'POJK 11/2022', weight: 6, direction: 1, yesScore: 1.0, noScore: -0.5),

            $this->yesNo('CLD-08', 'cloud_infrastructure', 'contractual',
                'Apakah ada komitmen data residency tertulis di MSA/DPA?',
                'Tanpa komitmen tertulis, vendor bisa pindahkan data ke region lain tanpa pemberitahuan.',
                'UU PDP Pasal 51', weight: 8, direction: 1, yesScore: 1.0, noScore: -0.7),

            $this->yesNo('CLD-09', 'cloud_infrastructure', 'data_handling',
                'Apakah ada data deletion guarantee (≤ 90 hari) saat kontrak berakhir?',
                'Tanpa ini, data bisa tetap di backup vendor selamanya — risiko kebocoran kontinu.',
                'UU PDP Pasal 30', weight: 7, direction: 1, yesScore: 1.0, noScore: -0.7),

            $this->multiChoice('CLD-10', 'cloud_infrastructure', 'security',
                'SLA notifikasi vendor saat ada insiden security yang memengaruhi data Anda?',
                'UU PDP Pasal 46 mengharuskan pengendali notifikasi ke Komdigi dalam 72 jam — jika vendor lambat, pengendali tidak bisa comply.',
                'UU PDP Pasal 46', weight: 8, direction: 1, options: [
                    ['value' => 'le_24h',  'label' => '≤ 24 jam',                 'score_contribution' => 1.0],
                    ['value' => 'le_72h',  'label' => '≤ 72 jam',                 'score_contribution' => 0.4],
                    ['value' => 'gt_72h',  'label' => '> 72 jam atau best-effort', 'score_contribution' => -0.8],
                    ['value' => 'no_sla',  'label' => 'Tidak ada SLA',             'score_contribution' => -1.0],
                ]),

            $this->yesNo('CLD-11', 'cloud_infrastructure', 'governance',
                'Apakah daftar sub-processor di-disclose dan klien punya hak veto?',
                'Sub-processor adalah pihak ke-4 — risiko cascade. UU PDP Pasal 51 mensyaratkan kontrol pengendali.',
                'UU PDP Pasal 51', weight: 6, direction: 1, yesScore: 1.0, noScore: -0.5),

            $this->yesNo('CLD-12', 'cloud_infrastructure', 'contractual',
                'Apakah vendor menyediakan DPA (Data Processing Agreement) yang memenuhi UU PDP Pasal 51?',
                'Pasal 51 mensyaratkan kontrol pengendali atas prosesor melalui kontrak tertulis. DPA = bukti pemenuhan kewajiban ini.',
                'UU PDP Pasal 51', weight: 9, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->multiChoice('CLD-13', 'cloud_infrastructure', 'security',
                'Cadence penetration test independen?',
                'Pen test eksternal mengidentifikasi kerentanan yang tidak terlihat oleh tim internal.',
                'POJK 11/2022 Pasal 28', weight: 7, direction: 1, options: [
                    ['value' => 'quarterly',  'label' => 'Per kuartal',     'score_contribution' => 1.0],
                    ['value' => 'annually',   'label' => 'Per tahun',       'score_contribution' => 0.5],
                    ['value' => 'ad_hoc',     'label' => 'Ad-hoc / on-demand', 'score_contribution' => 0.0],
                    ['value' => 'never',      'label' => 'Tidak pernah / tidak tahu', 'score_contribution' => -0.7],
                ]),

            $this->yesNo('CLD-14', 'cloud_infrastructure', 'compliance',
                'Apakah vendor MEMILIKI riwayat insiden / breach yang dipublikasikan dalam 24 bulan terakhir?',
                'Riwayat breach bukan otomatis disqualifier — tapi cara vendor merespons dan memperbaiki kontrolnya yang menentukan.',
                null, weight: 9, direction: -1, yesScore: 1.0, noScore: -0.3),  // direction=-1: yes turunkan score

            $this->yesNo('CLD-15', 'cloud_infrastructure', 'security',
                'Apakah backup terenkripsi, di-store offsite, dan restore-test rutin?',
                'Backup yang tidak terenkripsi = single point of failure. Restore-test mencegah backup ternyata corrupt saat disaster.',
                'POJK 11/2022', weight: 6, direction: 1, yesScore: 1.0, noScore: -0.5),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function saasApplication(): array
    {
        return [
            $this->yesNo('SAAS-01', 'saas', 'governance',
                'Apakah vendor memiliki SOC 2 Type II atau ISO 27001 yang aktif?',
                'SaaS yang menyimpan data PDP wajib punya minimal salah satu sertifikasi.',
                'ISO 27001', weight: 8, direction: 1, yesScore: 1.0, noScore: -0.5),

            $this->multiChoice('SAAS-02', 'saas', 'data_handling',
                'Region hosting data SaaS ini?',
                null, 'UU PDP Pasal 56', weight: 9, direction: 1, options: [
                    ['value' => 'indonesia',    'label' => 'Indonesia',                          'score_contribution' => 1.0],
                    ['value' => 'asean',        'label' => 'ASEAN',                              'score_contribution' => 0.6],
                    ['value' => 'gdpr_adequate','label' => 'GDPR adequate',                      'score_contribution' => 0.4],
                    ['value' => 'usa',          'label' => 'United States',                      'score_contribution' => 0.0],
                    ['value' => 'china_russia', 'label' => 'China / Russia / Iran',              'score_contribution' => -1.0],
                    ['value' => 'multi_region', 'label' => 'Multi-region (auto, tidak terkunci)', 'score_contribution' => -0.5],
                ]),

            $this->yesNo('SAAS-03', 'saas', 'security',
                'Apakah enkripsi at-rest aktif secara default?',
                null, 'POJK 11/2022', weight: 8, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->yesNo('SAAS-04', 'saas', 'security',
                'Apakah enkripsi in-transit (TLS 1.2+) wajib?',
                null, null, weight: 6, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->yesNo('SAAS-05', 'saas', 'security',
                'Apakah SSO (SAML/OIDC) atau MFA wajib bisa di-enforce di tenant kita?',
                'Tanpa SSO/MFA enforcement, password lemah karyawan menjadi vektor compromise.',
                null, weight: 6, direction: 1, yesScore: 1.0, noScore: -0.7),

            $this->yesNo('SAAS-06', 'saas', 'contractual',
                'Apakah vendor menyediakan DPA yang memenuhi UU PDP Pasal 51?',
                null, 'UU PDP Pasal 51', weight: 9, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->yesNo('SAAS-07', 'saas', 'governance',
                'Apakah daftar sub-processor di-disclose?',
                null, 'UU PDP Pasal 51', weight: 5, direction: 1, yesScore: 1.0, noScore: -0.4),

            $this->yesNo('SAAS-08', 'saas', 'data_handling',
                'Apakah tersedia data export / portability untuk pemenuhan hak subjek (DSR Right to Portability)?',
                'UU PDP Pasal 8 — subjek berhak meminta data dalam format terstruktur. SaaS tanpa export merepotkan kepatuhan.',
                'UU PDP Pasal 8', weight: 6, direction: 1, yesScore: 1.0, noScore: -0.5),

            $this->yesNo('SAAS-09', 'saas', 'data_handling',
                'Apakah retention policy clear & tenant bisa configure?',
                null, 'UU PDP Pasal 30', weight: 5, direction: 1, yesScore: 1.0, noScore: -0.4),

            $this->multiChoice('SAAS-10', 'saas', 'security',
                'SLA breach notification?',
                null, 'UU PDP Pasal 46', weight: 7, direction: 1, options: [
                    ['value' => 'le_24h',  'label' => '≤ 24 jam',                 'score_contribution' => 1.0],
                    ['value' => 'le_72h',  'label' => '≤ 72 jam',                 'score_contribution' => 0.4],
                    ['value' => 'gt_72h',  'label' => '> 72 jam atau best-effort', 'score_contribution' => -0.7],
                    ['value' => 'no_sla',  'label' => 'Tidak ada',                 'score_contribution' => -1.0],
                ]),

            $this->yesNo('SAAS-11', 'saas', 'governance',
                'Apakah ada audit independen tahunan (SOC 2 / ISO 27001 surveillance)?',
                null, null, weight: 5, direction: 1, yesScore: 1.0, noScore: -0.3),

            $this->yesNo('SAAS-12', 'saas', 'compliance',
                'Apakah vendor pernah mengalami breach publik dalam 24 bulan terakhir?',
                null, null, weight: 8, direction: -1, yesScore: 1.0, noScore: -0.3),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function dataProcessor(): array
    {
        return [
            $this->yesNo('PROC-01', 'data_processor', 'governance',
                'Apakah office utama vendor di Indonesia?',
                'Domestic processor mengurangi risiko cross-border + akses regulator lebih mudah.',
                'UU PDP Pasal 51', weight: 5, direction: 1, yesScore: 1.0, noScore: -0.3),

            $this->yesNo('PROC-02', 'data_processor', 'security',
                'Apakah karyawan yang akses data PDP menjalani background check?',
                'Insider threat = penyebab umum kebocoran data. Background check minimal mitigasi.',
                'ISO 27001 A.7.1.1', weight: 8, direction: 1, yesScore: 1.0, noScore: -0.7),

            $this->yesNo('PROC-03', 'data_processor', 'security',
                'Apakah akses data PDP dibatasi need-to-know dengan role-based access control?',
                'Tanpa RBAC, semua karyawan bisa lihat semua data — risiko exposure massif.',
                'UU PDP Pasal 39', weight: 8, direction: 1, yesScore: 1.0, noScore: -0.8),

            $this->multiChoice('PROC-04', 'data_processor', 'governance',
                'Cadence training PDP untuk karyawan operasional?',
                'Training rutin memastikan karyawan paham UU PDP yang terus berkembang.',
                'UU PDP Pasal 35', weight: 6, direction: 1, options: [
                    ['value' => 'quarterly', 'label' => 'Per kuartal',  'score_contribution' => 1.0],
                    ['value' => 'biannual',  'label' => 'Per 6 bulan',  'score_contribution' => 0.7],
                    ['value' => 'annual',    'label' => 'Per tahun',    'score_contribution' => 0.4],
                    ['value' => 'one_time',  'label' => 'Sekali saat onboarding', 'score_contribution' => -0.2],
                    ['value' => 'never',     'label' => 'Tidak ada',    'score_contribution' => -0.8],
                ]),

            $this->yesNo('PROC-05', 'data_processor', 'contractual',
                'Apakah DPA UU-PDP-compliant sudah ditandatangani?',
                'Pasal 51 — pengendali wajib pastikan prosesor patuh via kontrak tertulis. DPA = bukti.',
                'UU PDP Pasal 51', weight: 10, direction: 1, yesScore: 1.0, noScore: -1.0),

            $this->yesNo('PROC-06', 'data_processor', 'governance',
                'Apakah daftar sub-processor di-disclose dan butuh persetujuan klien tertulis sebelum penambahan?',
                null, 'UU PDP Pasal 51', weight: 6, direction: 1, yesScore: 1.0, noScore: -0.5),

            $this->yesNo('PROC-07', 'data_processor', 'contractual',
                'Apakah hak audit klien (audit right) tertulis di kontrak?',
                'Tanpa audit right, klien tidak bisa verifikasi klaim vendor — Pasal 51 mensyaratkan kontrol nyata.',
                'UU PDP Pasal 51', weight: 7, direction: 1, yesScore: 1.0, noScore: -0.6),

            $this->yesNo('PROC-08', 'data_processor', 'compliance',
                'Apakah vendor memiliki cyber liability insurance ≥ USD 1 juta?',
                'Insurance bukan substitute untuk security, tapi memberikan recourse finansial saat insiden.',
                null, weight: 4, direction: 1, yesScore: 1.0, noScore: -0.2),

            $this->yesNo('PROC-09', 'data_processor', 'governance',
                'Apakah ada DPO atau Privacy PIC yang named di kontrak dengan kontak yang valid?',
                'UU PDP Pasal 53 — pengendali wajib mengenal PIC privasi di prosesor.',
                'UU PDP Pasal 53', weight: 5, direction: 1, yesScore: 1.0, noScore: -0.4),

            $this->yesNo('PROC-10', 'data_processor', 'compliance',
                'Apakah vendor pernah dijatuhi sanksi regulator (Komdigi / OJK / BSSN) dalam 24 bulan terakhir?',
                null, null, weight: 9, direction: -1, yesScore: 1.0, noScore: -0.3),

            $this->yesNo('PROC-11', 'data_processor', 'security',
                'Apakah workstation karyawan punya endpoint protection (antivirus, EDR) + disk encryption?',
                'Insider laptop yang hilang/dicuri tanpa enkripsi = breach.',
                'POJK 11/2022 Pasal 27', weight: 6, direction: 1, yesScore: 1.0, noScore: -0.5),

            $this->yesNo('PROC-12', 'data_processor', 'security',
                'Apakah office punya physical access control (badge, CCTV, visitor log)?',
                'Physical security yang lemah = social engineering jadi mudah.',
                'ISO 27001 A.11.1', weight: 4, direction: 1, yesScore: 1.0, noScore: -0.3),
        ];
    }

    /** Helper: build a yes/no question row. */
    private function yesNo(
        string $code, string $category, string $section,
        string $text, ?string $description, ?string $regulationRef,
        int $weight, int $direction,
        float $yesScore, float $noScore,
    ): array {
        return [
            'category' => $category,
            'version' => 'v1',
            'question_code' => $code,
            'section' => $section,
            'question_text' => $text,
            'description' => $description,
            'regulation_ref' => $regulationRef,
            'answer_type' => VendorQuestionnaire::ANSWER_YES_NO,
            'answer_options' => [
                ['value' => 'yes', 'label' => 'Ya', 'score_contribution' => $yesScore],
                ['value' => 'no',  'label' => 'Tidak', 'score_contribution' => $noScore],
                ['value' => 'unknown', 'label' => 'Belum tahu', 'score_contribution' => -0.3],
            ],
            'weight' => $weight,
            'direction' => $direction,
            'is_active' => true,
        ];
    }

    /** Helper: build a multi-choice question row. */
    private function multiChoice(
        string $code, string $category, string $section,
        string $text, ?string $description, ?string $regulationRef,
        int $weight, int $direction, array $options,
    ): array {
        return [
            'category' => $category,
            'version' => 'v1',
            'question_code' => $code,
            'section' => $section,
            'question_text' => $text,
            'description' => $description,
            'regulation_ref' => $regulationRef,
            'answer_type' => VendorQuestionnaire::ANSWER_MULTI_CHOICE,
            'answer_options' => $options,
            'weight' => $weight,
            'direction' => $direction,
            'is_active' => true,
        ];
    }
}
