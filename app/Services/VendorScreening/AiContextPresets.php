<?php

namespace App\Services\VendorScreening;

/**
 * TPRM Phase 3.5 — Custom AI context preset untuk screening.
 *
 * Konteks paragraf ini di-inject ke system prompt AI saat analisis sehingga
 * AI menilai risiko vendor sesuai sektor / kebijakan internal organisasi.
 *
 * Mis. sektor "Perbankan" punya appetite risiko berbeda dengan "Healthcare":
 * - Perbankan: ketat di data nasabah + transaksi, perhatian khusus POJK,
 *   risk appetite untuk vendor cloud → lebih protective
 * - Healthcare: ketat di data kesehatan (sensitif PDP Pasal 4), HIPAA-style
 *   safeguards, retensi rekam medis
 *
 * Tenant pilih preset di UI saat run screening. Kalau pilih 'custom',
 * tenant input paragraf bebas yang disimpan di system_settings key
 * 'vendor_screening.custom_context_paragraph'.
 *
 * Tidak hardcode di vendor_screenings table — preset dipakai inline di
 * prompt saja, tidak perlu persist (kalau perlu audit, simpan key di
 * row vendor_screenings.context_preset).
 */
class AiContextPresets
{
    public const PRESET_NONE = 'none';
    public const PRESET_PERBANKAN = 'perbankan';
    public const PRESET_BUMN = 'bumn';
    public const PRESET_HEALTHCARE = 'healthcare';
    public const PRESET_TELEKOMUNIKASI = 'telekomunikasi';
    public const PRESET_STARTUP_B2B = 'startup_b2b';
    public const PRESET_GOVERNMENT = 'government';
    public const PRESET_CUSTOM = 'custom';

    public const ALL_KEYS = [
        self::PRESET_NONE,
        self::PRESET_PERBANKAN,
        self::PRESET_BUMN,
        self::PRESET_HEALTHCARE,
        self::PRESET_TELEKOMUNIKASI,
        self::PRESET_STARTUP_B2B,
        self::PRESET_GOVERNMENT,
        self::PRESET_CUSTOM,
    ];

    /**
     * Daftar preset siap pakai. Format: key => [label, paragraph].
     * Paragraf inject ke system prompt AI sebagai konteks tambahan.
     */
    public static function options(): array
    {
        return [
            self::PRESET_NONE => [
                'label' => 'Tanpa Konteks Khusus',
                'paragraph' => '',
            ],
            self::PRESET_PERBANKAN => [
                'label' => 'Sektor Perbankan',
                'paragraph' => 'Organisasi pemanggil adalah lembaga jasa keuangan / bank yang tunduk pada UU PDP No. 27/2022 + POJK 11/2022 (manajemen risiko TI) + POJK 38/2024 (tata kelola digital). Risk appetite untuk pihak ketiga SANGAT KETAT terutama soal: (1) lokasi pemrosesan data nasabah, (2) enkripsi data transit + at-rest, (3) right-to-audit klausa di kontrak, (4) breach notification < 24 jam. Vendor yang tidak comply Pasal 27 UU PDP atau menyimpan data nasabah tanpa enkripsi = risiko tinggi otomatis.',
            ],
            self::PRESET_BUMN => [
                'label' => 'Government BUMN',
                'paragraph' => 'Organisasi pemanggil adalah BUMN Indonesia yang tunduk pada UU PDP No. 27/2022 + PerPres 95/2018 (Sistem Pemerintahan Berbasis Elektronik) + ketentuan Kementerian BUMN. Vendor harus comply dengan: (1) data residency di Indonesia (PP 71/2019), (2) klausa hak audit pemerintah, (3) integritas terhadap pencegahan korupsi (vendor tidak boleh di-blacklist LKPP / KPK). Vendor multinasional yang host data di luar negeri = perlu skema cross-border yang valid (BCR atau adequacy decision).',
            ],
            self::PRESET_HEALTHCARE => [
                'label' => 'Healthcare / Rumah Sakit',
                'paragraph' => 'Organisasi pemanggil adalah penyelenggara layanan kesehatan yang tunduk pada UU PDP No. 27/2022 (Pasal 4 — data kesehatan = data spesifik) + PMK 24/2022 (Rekam Medis Elektronik). Vendor yang process data pasien WAJIB: (1) DPA / PKS dengan klausa kerahasiaan medis, (2) retensi rekam medis 25 tahun setelah pasien meninggal, (3) enkripsi data pasien sesuai standar Kemenkes. Vendor cloud asing untuk PHI / rekam medis = risiko kritis tanpa BCR.',
            ],
            self::PRESET_TELEKOMUNIKASI => [
                'label' => 'Telekomunikasi',
                'paragraph' => 'Organisasi pemanggil adalah penyelenggara telekomunikasi yang tunduk pada UU PDP No. 27/2022 + UU 36/1999 (Telekomunikasi) + PerMenkominfo 5/2020 (PSE Privat). Data CDR / metadata pelanggan = data pribadi spesifik. Vendor harus comply: (1) lawful interception support, (2) data lokal sesuai PP 71/2019, (3) audit trail komprehensif. Vendor yang tidak certify ISO 27001 atau setara untuk PSE = perlu perhatian khusus.',
            ],
            self::PRESET_STARTUP_B2B => [
                'label' => 'Startup B2B',
                'paragraph' => 'Organisasi pemanggil adalah startup B2B SaaS yang fokus pada efisiensi operasional dan pertumbuhan. Risk appetite untuk pihak ketiga LEBIH FLEKSIBEL: vendor cloud asing OK selama ada DPA + standard contractual clauses (SCC). Prioritas: scalability + compliance UU PDP minimum + GDPR equivalent kalau target market international.',
            ],
            self::PRESET_GOVERNMENT => [
                'label' => 'Instansi Pemerintah',
                'paragraph' => 'Organisasi pemanggil adalah instansi pemerintah / kementerian / lembaga negara yang tunduk pada UU PDP No. 27/2022 + UU 14/2008 (KIP) + PerPres 95/2018 (SPBE). Vendor wajib: (1) clearance keamanan, (2) data residency Indonesia mutlak (PP 71/2019), (3) tidak boleh masuk daftar hitam LKPP, (4) klausa "secret/confidential information" di kontrak. Vendor luar negeri = risiko kritis kecuali dengan G2G arrangement.',
            ],
            self::PRESET_CUSTOM => [
                'label' => 'Konteks Kustom (input manual)',
                'paragraph' => '', // di-resolve dari system_settings di runtime
            ],
        ];
    }

    /**
     * Get paragraph untuk key tertentu. Untuk 'custom', resolve dari
     * system_settings ('vendor_screening.custom_context_paragraph').
     */
    public static function resolveParagraph(string $key): string
    {
        $options = self::options();
        if (! isset($options[$key])) {
            return '';
        }
        if ($key === self::PRESET_CUSTOM) {
            return (string) config('vendor_screening.custom_context_paragraph', '');
        }
        return $options[$key]['paragraph'];
    }
}
