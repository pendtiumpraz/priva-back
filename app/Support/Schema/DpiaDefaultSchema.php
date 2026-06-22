<?php

namespace App\Support\Schema;

/**
 * Definisi DEFAULT kanonik wizard DPIA — sumber kebenaran untuk:
 *   - seed schema per-org (materialize ke module_custom_sections/fields)
 *   - reset-to-default (hapus override org → seed ulang dari sini)
 *
 * Hasil ekstraksi dari wizard hardcoded DPIA (3 section, selaras
 * `frontend/src/app/(dashboard)/dpia/page.tsx` + `App\Models\Dpia`).
 *
 * field_type: text | textarea | number | date | boolean | select | multiselect | tags | special
 * widget (nullable): penanda renderer khusus untuk field kompleks. Field dengan
 *   widget != null perilakunya di-hardcode di komponen React — admin boleh
 *   hide/relabel/reorder tapi TIDAK boleh ubah tipe/opsi-nya. Field widget=null
 *   = generik penuh (editable tipe + opsi).
 *
 * Sub-field kondisional (mis. per-kategori risk_events: dampak/probabilitas/
 * kontrol/penanganan) TIDAK didaftarkan terpisah — dimiliki oleh widget grup
 * induknya (renderer-nya yang menampilkan), sama seperti pola RoPA.
 *
 * @see RopaDefaultSchema  Konsep & format identik.
 */
class DpiaDefaultSchema
{
    public const MODULE = 'dpia';

    /**
     * 21 kategori penilaian risiko — selaras `App\Models\Dpia::RISK_CATEGORIES`
     * dan platform live. Dipakai sebagai opsi widget `risk_assessment_matrix`.
     */
    private const RISK_CATEGORIES = [
        'Legal Basis',
        'Retensi',
        'Autentikasi',
        'Pemantauan Akses',
        'Enkripsi (Structured)',
        'Enkripsi (Unstructured)',
        'Anonimisasi',
        'Autorisasi (Hak Akses)',
        'Pihak Ketiga',
        'Pemantauan Berkala',
        'Uji Tuntas',
        'Pemetaan Data Pribadi',
        'Back-up dan Restore',
        'Keamanan Back-up',
        'Data Minimization',
        'Pemberitahuan Privasi',
        'Pemusnahan Data',
        'Kualitas Data',
        'Verifikasi Data',
        'Transfer Luar Negeri',
        'Hak Subjek Data',
    ];

    /**
     * Opsi penanganan risiko per risk-event (dimiliki widget risk_assessment_matrix).
     * Skala: dampak 1-5, probabilitas 1-5, kontrol 1-3.
     */
    private const PENANGANAN = ['Mitigasi', 'Terima (Accept)', 'Transfer', 'Hentikan (Terminate)'];

    private const KATEGORI_PENGENDALI = [
        'Pengendali Data Pribadi',
        'Pemroses Data Pribadi',
        'Pengendali Data Pribadi Bersama',
    ];

    /**
     * @return array<int, array{section_key:string, section_label:string, fields:array<int,array<string,mixed>>}>
     */
    public static function sections(): array
    {
        return [
            [
                'section_key' => 'informasi_dpia',
                'section_label' => 'Informasi DPIA',
                'fields' => [
                    self::f('nama_dpia', 'Nama DPIA', 'text', required: true),
                    self::f('entitas', 'Entitas', 'text', widget: 'readonly_org'),
                    self::f('kategori', 'Kategori', 'select', widget: 'category_picker', options: self::KATEGORI_PENGENDALI),
                    self::f('description', 'Deskripsi Pemrosesan', 'textarea', required: true),
                    self::f('dpo_name', 'Pejabat Pelindungan Data (DPO)', 'text', required: true),
                    self::f('pic_name', 'Penanggung Jawab (PIC) Pemrosesan', 'text'),
                    self::f('risk_level', 'Risk Level', 'special', widget: 'risk_level_auto', required: true),
                ],
            ],
            [
                'section_key' => 'koneksi_ropa',
                'section_label' => 'Koneksi RoPA',
                'fields' => [
                    // Multi-RoPA: 1 DPIA bisa cover banyak aktivitas pemrosesan (RoPA).
                    // wizard_data.koneksi_ropa.connected_ropas → sync ke pivot dpia_ropa.
                    self::f('connected_ropas', 'RoPA Terkait', 'special', widget: 'ropa_picker', required: true),
                ],
            ],
            [
                'section_key' => 'potensi_risiko',
                'section_label' => 'Potensi Risiko',
                'fields' => [
                    // Matriks penilaian risiko per-kategori. Tiap kategori menyimpan
                    // { answer, description, risk_events[{risk_event, dampak 1-5,
                    // probabilitas 1-5, kontrol 1-3, penanganan, notes}] } — di-render
                    // oleh widget hardcoded (sub-field tidak didaftarkan terpisah).
                    self::f('risk_assessment', 'Penilaian Risiko per Kategori', 'special', widget: 'risk_assessment_matrix', required: true, options: self::RISK_CATEGORIES),
                    self::f('rekomendasi_asesmen', 'Rekomendasi Asesmen', 'textarea'),
                ],
            ],
        ];
    }

    /**
     * Helper bikin satu definisi field default. Identik dengan RopaDefaultSchema.
     *
     * @param  array<int,string>  $options
     */
    private static function f(
        string $name,
        string $label,
        string $type,
        ?string $widget = null,
        bool $required = false,
        array $options = [],
    ): array {
        return [
            'field_name' => $name,
            'field_label' => $label,
            'field_type' => $type,
            'widget' => $widget,
            'field_options' => $options ?: null,
            'is_required' => $required,
        ];
    }
}
