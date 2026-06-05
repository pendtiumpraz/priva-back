<?php

namespace App\Support\Schema;

/**
 * Definisi DEFAULT kanonik wizard RoPA — sumber kebenaran untuk:
 *   - seed schema per-org (materialize ke module_custom_sections/fields)
 *   - reset-to-default (hapus override org → seed ulang dari sini)
 *
 * Hasil ekstraksi dari wizard hardcoded lama (7 section ~50 field).
 *
 * field_type: text | textarea | number | date | boolean | select | multiselect | tags | special
 * widget (nullable): penanda renderer khusus untuk field kompleks. Field dengan
 *   widget != null perilakunya di-hardcode di komponen React — admin boleh
 *   hide/relabel/reorder tapi TIDAK boleh ubah tipe/opsi-nya. Field widget=null
 *   = generik penuh (editable tipe + opsi).
 *
 * Sub-field kondisional (mis. lb_*, penerima_*) TIDAK didaftarkan terpisah —
 * dimiliki oleh widget grup induknya (renderer-nya yang menampilkan).
 */
class RopaDefaultSchema
{
    public const MODULE = 'ropa';

    private const JENIS_PEMROSESAN = [
        'Pemerolehan dan pengumpulan data',
        'Pengolahan dan penganalisisan data',
        'Penyimpanan data',
        'Perbaikan dan pembaruan data',
        'Penampilan, pengumuman, transfer, penyebarluasan, atau pengungkapan data',
        'Penghapusan atau pemusnahan data',
    ];

    private const LEGAL_BASIS_OPTIONS = [
        'Persetujuan yang Sah Secara Eksplisit',
        'Pemenuhan Kewajiban Perjanjian',
        'Pemenuhan Kewajiban Hukum',
        'Pemenuhan Pelindungan Kepentingan Vital',
        'Pelaksanaan Tugas dalam Rangka Kepentingan Umum',
        'Pemenuhan Kepentingan yang Sah (Legitimate Interest)',
    ];

    private const DATA_TYPES_SPECIFIC = [
        'Data dan informasi kesehatan', 'Data biometrik', 'Data Genetika',
        'Catatan Kejahatan', 'Data Anak', 'Data Keuangan Pribadi',
        'Data lainnya sesuai dengan ketentuan peraturan perundang-undangan', 'Not Applicable',
    ];

    private const DATA_TYPES_GENERAL = [
        'Nama Lengkap', 'Jenis Kelamin', 'Kewarganegaraan', 'Agama', 'Status Perkawinan',
        'Data pribadi yang dikombinasikan untuk mengidentifikasi seseorang', 'Not Applicable',
    ];

    private const DATA_TYPES_PII = [
        'Tempat Lahir', 'Tanggal Lahir', 'Data Geografis', 'Ras atau Etnis',
        'Nomor Jaminan Sosial (Social Security Number)', 'Nomor Paspor',
        'Nomor Surat Izin Mengemudi (SIM)', 'Alamat Email', 'Nomor Telepon',
        'Alamat Rumah', 'Alamat IP (IP Address)', 'Nomor Pegawai', 'Nomor Plat Kendaraan',
        'Nama pengguna media sosial', 'Nama Ibu Kandung', 'Nomor Identitas Nasional (KTP)',
        'Nomor Pokok Wajib Pajak (NPWP)', 'Not Applicable',
    ];

    private const DATA_SOURCE_OPTIONS = [
        'Individu', 'Organisasi', 'Sumber Terbuka / Publik', 'Lembaga Pemerintahan', 'Lembaga Survey',
    ];

    private const TRANSFER_BASIS_OPTIONS = [
        'Negara domisili Pengendali Data Pribadi atau organisasi internasional yang menerima transfer data pribadi memiliki tingkat pelindungan data pribadi yang sama atau lebih tinggi dari yang diatur',
        'Terdapat pelindungan data pribadi yang memadai dan bersifat mengikat (kontrak)',
        'Persetujuan Subjek Data',
    ];

    private const SECURITY_CONTROLS = [
        'Enkripsi', 'Tokenization', 'Kontrol Akses', 'Backup',
        'Audit Keamanan Reguler', 'Vulnerability Assessment (Tambahan)', 'Penetration Testing (Tambahan)',
    ];

    private const YA_TIDAK = ['Ya', 'Tidak'];

    /**
     * @return array<int, array{section_key:string, section_label:string, fields:array<int,array<string,mixed>>}>
     */
    public static function sections(): array
    {
        return [
            [
                'section_key' => 'detail_pemrosesan',
                'section_label' => 'Detail Pemrosesan',
                'fields' => [
                    self::f('nama_pemrosesan', 'Nama Pemrosesan', 'text', required: true),
                    self::f('entitas', 'Entitas', 'text', widget: 'readonly_org'),
                    self::f('divisi_list', 'Unit Kerja / Divisi yang Terlibat', 'special', widget: 'divisi_picker', required: true),
                    self::f('sistem_terkait', 'Sistem / Aplikasi Terkait', 'special', widget: 'system_picker'),
                    self::f('deskripsi', 'Deskripsi Singkat', 'textarea', required: true),
                    self::f('risk_level', 'Risk Level', 'special', widget: 'risk_level_auto', required: true),
                ],
            ],
            [
                'section_key' => 'dpo_team',
                'section_label' => 'Data Protection Team/Officer',
                'fields' => [
                    self::f('kategori_pemrosesan', 'Kategori Pemrosesan', 'select', required: true, options: [
                        'Pengendali Data Pribadi', 'Pemroses Data Pribadi', 'Pengendali Data Pribadi Bersama',
                    ]),
                    self::f('dpo_list', 'Pejabat PDP (DPO)', 'special', widget: 'person_repeater_dpo', required: true),
                    self::f('pic_list', 'Process Owner / PIC', 'special', widget: 'person_repeater_pic'),
                ],
            ],
            [
                'section_key' => 'informasi_pemrosesan',
                'section_label' => 'Informasi Pemrosesan',
                'fields' => [
                    self::f('tujuan', 'Tujuan Pemrosesan', 'textarea', required: true),
                    self::f('penjelasan', 'Penjelasan Aktivitas Pemrosesan', 'textarea'),
                    self::f('jenis_pemrosesan', 'Jenis Pemrosesan', 'multiselect', required: true, options: self::JENIS_PEMROSESAN),
                    self::f('dasar_pemrosesan', 'Dasar Pemrosesan / Legal Basis', 'special', widget: 'legal_basis_group', required: true, options: self::LEGAL_BASIS_OPTIONS),
                    self::f('legal_basis_detail', 'Catatan Tambahan', 'textarea'),
                    self::f('bantuan_ai', 'Apakah pemrosesan menggunakan bantuan AI?', 'select', widget: 'risk_indicator_ai', options: [
                        'Ya (Keputusan Sepenuhnya menggunakan AI)', 'Ya (Keputusan Akhir dari Manusia)',
                        'Sebagian dari Pemrosesan', 'Tidak menggunakan bantuan AI',
                    ]),
                    self::f('otomatis', 'Apakah menggunakan pengambilan keputusan otomatis?', 'select', widget: 'risk_indicator', options: [
                        'Ya, Keputusan Penuh', 'Ya, Keputusan Akhir dari Manusia', 'Sebagian dari Pemrosesan', 'Tidak',
                    ]),
                    self::f('pemrofilan', 'Apakah pemrosesan melakukan pemrofilan subjek data?', 'multiselect', widget: 'risk_indicator', options: [
                        'Marketing', 'Advertisement', 'Penawaran Produk', 'Peningkatan Pengalaman Pengguna',
                        'Personalisasi Konten', 'Lainnya', 'Not Applicable',
                    ]),
                    self::f('teknologi_baru', 'Apakah menggunakan teknologi baru (emerging tech)?', 'boolean', widget: 'risk_indicator', options: self::YA_TIDAK),
                ],
            ],
            [
                'section_key' => 'pengumpulan_data',
                'section_label' => 'Pengumpulan Data',
                'fields' => [
                    self::f('sumber_data_list', 'Sumber Pengumpulan Data Pribadi', 'special', widget: 'source_collection_group', options: self::DATA_SOURCE_OPTIONS),
                    self::f('jumlah_subjek', 'Jumlah Subjek Data Pribadi', 'select', required: true, options: ['≤ 1.000 subjek', '> 1.000 subjek']),
                    self::f('jenis_data_spesifik', 'Data Pribadi Spesifik (dikumpulkan)', 'multiselect', widget: 'data_types_sensitive', required: true, options: self::DATA_TYPES_SPECIFIC),
                    self::f('jenis_data_umum', 'Data Pribadi Umum (dikumpulkan)', 'multiselect', options: self::DATA_TYPES_GENERAL),
                    self::f('jenis_data_pii', 'PII (dikumpulkan)', 'multiselect', options: self::DATA_TYPES_PII),
                ],
            ],
            [
                'section_key' => 'penggunaan_penyimpanan',
                'section_label' => 'Penggunaan dan Penyimpanan Data',
                'fields' => [
                    self::f('pihak_pemroses', 'Pihak yang Memproses Data Pribadi', 'text', required: true),
                    self::f('kategori_pihak', 'Kategori Pihak', 'multiselect', widget: 'kategori_pihak_group', required: true, options: [
                        'Pengendali Data (Controller)', 'Pemroses Data (Processor)', 'Pengendali Bersama (Joint Controller)', 'Lainnya',
                    ]),
                    self::f('pihak_ketiga', 'Apakah data diproses oleh pihak ketiga?', 'boolean', widget: 'third_party_group', options: self::YA_TIDAK),
                ],
            ],
            [
                'section_key' => 'pengiriman_data',
                'section_label' => 'Pengiriman Data',
                'fields' => [
                    self::f('penerima_internal', 'Penerima Data Internal', 'boolean', widget: 'internal_recipient_group', options: self::YA_TIDAK),
                    self::f('penerima_eksternal', 'Penerima Data Eksternal', 'boolean', widget: 'external_recipient_group', options: self::YA_TIDAK),
                    self::f('jenis_data_spesifik_kirim', 'Data Pribadi Spesifik (dikirimkan)', 'multiselect', options: self::DATA_TYPES_SPECIFIC),
                    self::f('jenis_data_umum_kirim', 'Data Pribadi Umum (dikirimkan)', 'multiselect', options: self::DATA_TYPES_GENERAL),
                    self::f('jenis_data_pii_kirim', 'PII (dikirimkan)', 'multiselect', options: self::DATA_TYPES_PII),
                    self::f('transfer_luar', 'Apakah ada transfer data ke luar negeri?', 'boolean', widget: 'transfer_group', options: self::YA_TIDAK),
                ],
            ],
            [
                'section_key' => 'retensi_keamanan',
                'section_label' => 'Retensi dan Keamanan Data',
                'fields' => [
                    self::f('kontrol_keamanan', 'Kontrol Keamanan yang Diterapkan', 'multiselect', required: true, options: self::SECURITY_CONTROLS),
                    self::f('retensi_list', 'Retensi (master data)', 'special', widget: 'retention_picker'),
                    self::f('masa_retensi', 'Catatan Masa Retensi', 'text'),
                    self::f('ada_prosedur_pemusnahan', 'Apakah ada prosedur pemusnahan data?', 'boolean', widget: 'destruction_group', options: self::YA_TIDAK),
                    self::f('pernah_insiden', 'Insiden Pelanggaran Data', 'select', widget: 'incident_group', options: ['Ya, pernah terjadi', 'Tidak pernah']),
                ],
            ],
        ];
    }

    /**
     * Helper bikin satu definisi field default.
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
