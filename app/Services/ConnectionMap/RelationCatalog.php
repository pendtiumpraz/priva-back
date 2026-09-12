<?php

namespace App\Services\ConnectionMap;

use App\Models\Vendor;

/**
 * Katalog tunggal relasi lintas modul.
 *
 * Selama ini pengetahuan "apa tersambung ke apa" hanya hidup di dalam
 * ConnectionMapScanner, yang memindai SELURUH organisasi. Peta per-record tidak
 * bisa memakai jalan itu — hasil scan disimpan dan baru berubah saat scan
 * dijalankan ulang, sehingga tautan yang baru saja dibuat tidak akan terlihat.
 * Menyalin logikanya ke pembangun kedua akan melahirkan penyimpangan yang persis
 * sama seperti bug F-03 dulu: dua tempat yang seharusnya sama, lalu berbeda.
 *
 * Karena itu relasinya dipindah ke sini sebagai DATA, bukan kode. Menambah satu
 * tautan baru cukup menambah satu baris, dan seluruh peta — per-record,
 * per-modul, maupun se-organisasi — langsung mengetahuinya.
 *
 * Setiap entri mendeskripsikan SATU arah bermakna: `from` adalah yang memasok
 * atau memiliki, `to` adalah yang dihasilkan atau dirujuk. Arah ini mengikuti
 * alur makna, BUKAN arah foreign key — itulah yang membuat peta terbaca sebagai
 * "sumber di kiri, konsekuensi di kanan".
 */
final class RelationCatalog
{
    /** Awalan id simpul per jenis. WAJIB sama dengan yang dipakai scanner DSPM. */
    public const NODE_PREFIX = [
        'ropa' => 'ropa',
        'dpia' => 'dpia',
        'rtp' => 'rtp',
        'lia' => 'lia',
        'tia' => 'tia',
        'cross_border' => 'crossborder',
        'third_party' => 'thirdparty',
        'breach' => 'breach',
        'dsr' => 'dsr',
        'data_discovery' => 'system',
        'consent' => 'consent',
        'contract' => 'contract',
        'vendor_incident' => 'vendorincident',
        'vendor_ropa' => 'vendorropa',
    ];

    /** Modul yang benar-benar tidak punya tautan lintas modul — tombol peta disembunyikan. */
    public const WITHOUT_RELATIONS = ['gap', 'maturity', 'policy_review'];

    /**
     * Mekanisme yang dipakai tiap relasi:
     *
     *   fk          — kolom foreign key pada tabel `table`, menunjuk record `points_to`.
     *   json_array  — kolom JSON berisi larik id polos.
     *   json_items  — kolom JSON berisi larik objek; id diambil dari `item_key`.
     *   json_path   — kolom JSON berisi satu id pada jalur `path` (titik sebagai pemisah).
     *   pivot       — tabel pivot dengan dua kolom kunci.
     *
     * `owner` adalah jenis simpul pemilik baris/tabelnya.
     *
     * @return list<array<string, mixed>>
     */
    public static function relations(): array
    {
        return [
            // ---------- Sumber: yang memasok data ke sebuah kegiatan ----------
            [
                'relation' => 'supplies', 'label' => 'memasok data',
                'from' => 'data_discovery', 'to' => 'ropa',
                'kind' => 'pivot', 'table' => 'information_system_ropa',
                'from_key' => 'information_system_id', 'to_key' => 'ropa_id',
            ],
            [
                // Tautan sistem yang diisi lewat wizard TAPI belum pernah dipromosikan
                // ke pivot. Tanpa entri ini, sistem yang dipilih DPO di wizard tidak
                // pernah muncul di peta mana pun — tautannya ada, gambarnya tidak.
                'relation' => 'supplies', 'label' => 'memasok data',
                'from' => 'data_discovery', 'to' => 'ropa',
                'kind' => 'json_items', 'table' => 'ropas', 'owner' => 'ropa',
                'column' => 'wizard_data', 'path' => 'informasi_pemrosesan.sistem_terkait',
                'item_key' => 'system_id', 'points_to' => 'data_discovery', 'inverse' => true,
            ],
            [
                'relation' => 'consent_basis', 'label' => 'dasar consent',
                'from' => 'consent', 'to' => 'ropa',
                'kind' => 'pivot', 'table' => 'consent_collection_ropa',
                'from_key' => 'collection_point_id', 'to_key' => 'ropa_id',
            ],
            [
                // Bentuk lama yang masih dipertahankan saat settings diperbarui.
                'relation' => 'consent_basis', 'label' => 'dasar consent',
                'from' => 'consent', 'to' => 'ropa',
                'kind' => 'json_path', 'table' => 'consent_collection_points', 'owner' => 'consent',
                'column' => 'settings', 'path' => 'linked_ropa_id', 'points_to' => 'ropa',
            ],

            // ---------- Konsekuensi: penilaian & kejadian yang lahir dari pemrosesan ----------
            [
                'relation' => 'assessed_by_dpia', 'label' => 'dinilai DPIA',
                'from' => 'ropa', 'to' => 'dpia',
                'kind' => 'fk', 'table' => 'dpias', 'owner' => 'dpia',
                'column' => 'ropa_id', 'points_to' => 'ropa', 'inverse' => true,
            ],
            [
                'relation' => 'assessed_by_dpia', 'label' => 'dinilai DPIA',
                'from' => 'ropa', 'to' => 'dpia',
                'kind' => 'pivot', 'table' => 'dpia_ropa',
                'from_key' => 'ropa_id', 'to_key' => 'dpia_id',
            ],
            [
                'relation' => 'weighed_by_lia', 'label' => 'ditimbang LIA',
                'from' => 'ropa', 'to' => 'lia',
                'kind' => 'fk', 'table' => 'lia_assessments', 'owner' => 'lia',
                'column' => 'linked_ropa_id', 'points_to' => 'ropa', 'inverse' => true,
            ],
            [
                'relation' => 'weighed_by_lia', 'label' => 'ditimbang LIA',
                'from' => 'dpia', 'to' => 'lia',
                'kind' => 'fk', 'table' => 'lia_assessments', 'owner' => 'lia',
                'column' => 'linked_dpia_id', 'points_to' => 'dpia', 'inverse' => true,
            ],
            [
                'relation' => 'transfers', 'label' => 'mentransfer',
                'from' => 'ropa', 'to' => 'cross_border',
                'kind' => 'fk', 'table' => 'cross_border_transfers', 'owner' => 'cross_border',
                'column' => 'linked_ropa_id', 'points_to' => 'ropa', 'inverse' => true,
            ],
            [
                'relation' => 'transfer_recipient', 'label' => 'penerima transfer',
                'from' => 'cross_border', 'to' => 'third_party',
                'kind' => 'fk', 'table' => 'cross_border_transfers', 'owner' => 'cross_border',
                'column' => 'vendor_id', 'points_to' => 'third_party',
            ],
            [
                'relation' => 'assessed_by_tia', 'label' => 'dinilai TIA',
                'from' => 'cross_border', 'to' => 'tia',
                'kind' => 'fk', 'table' => 'tia_assessments', 'owner' => 'tia',
                'column' => 'linked_cross_border_id', 'points_to' => 'cross_border', 'inverse' => true,
            ],
            [
                'relation' => 'assessed_by_tia', 'label' => 'dinilai TIA',
                'from' => 'ropa', 'to' => 'tia',
                'kind' => 'fk', 'table' => 'tia_assessments', 'owner' => 'tia',
                'column' => 'linked_ropa_id', 'points_to' => 'ropa', 'inverse' => true,
            ],
            [
                'relation' => 'assessed_by_tia', 'label' => 'dinilai TIA',
                'from' => 'third_party', 'to' => 'tia',
                'kind' => 'fk', 'table' => 'tia_assessments', 'owner' => 'tia',
                'column' => 'linked_vendor_id', 'points_to' => 'third_party', 'inverse' => true,
            ],
            [
                'relation' => 'impacted_by_breach', 'label' => 'terdampak insiden',
                'from' => 'ropa', 'to' => 'breach',
                'kind' => 'fk', 'table' => 'breach_incidents', 'owner' => 'breach',
                'column' => 'linked_ropa_id', 'points_to' => 'ropa', 'inverse' => true,
            ],
            [
                'relation' => 'impacted_by_breach', 'label' => 'terdampak insiden',
                'from' => 'ropa', 'to' => 'breach',
                'kind' => 'json_array', 'table' => 'breach_incidents', 'owner' => 'breach',
                'column' => 'linked_ropa_ids', 'points_to' => 'ropa', 'inverse' => true,
            ],

            // ---------- Pihak ketiga ----------
            [
                'relation' => 'processed_by', 'label' => 'diproses pihak ketiga',
                'from' => 'ropa', 'to' => 'third_party',
                'kind' => 'pivot', 'table' => 'ropa_vendor',
                'from_key' => 'ropa_id', 'to_key' => 'vendor_id', 'role_column' => 'role',
            ],
            [
                'relation' => 'processed_by', 'label' => 'diproses pihak ketiga',
                'from' => 'ropa', 'to' => 'third_party',
                'kind' => 'json_array', 'table' => 'ropas', 'owner' => 'ropa',
                'column' => 'wizard_data', 'path' => 'penggunaan_penyimpanan.vendor_ids',
                'points_to' => 'third_party',
            ],
            [
                // Ditambahkan bersama pivotnya; sebelumnya tidak ada peta yang tahu
                // pihak ketiga mana yang memegang sebuah sistem.
                'relation' => 'operates_system', 'label' => 'memegang sistem',
                'from' => 'third_party', 'to' => 'data_discovery',
                'kind' => 'pivot', 'table' => 'information_system_vendor',
                'from_key' => 'vendor_id', 'to_key' => 'information_system_id', 'role_column' => 'role',
            ],
            [
                'relation' => 'breach_third_party', 'label' => 'pihak ketiga terlibat',
                'from' => 'breach', 'to' => 'third_party',
                'kind' => 'json_array', 'table' => 'breach_incidents', 'owner' => 'breach',
                'column' => 'linked_vendor_ids', 'points_to' => 'third_party',
            ],
            [
                'relation' => 'breach_system', 'label' => 'sistem terdampak',
                'from' => 'data_discovery', 'to' => 'breach',
                'kind' => 'json_items', 'table' => 'breach_incidents', 'owner' => 'breach',
                'column' => 'affected_systems', 'item_key' => 'information_system_id',
                'points_to' => 'data_discovery', 'inverse' => true,
            ],
            [
                'relation' => 'third_party_incident', 'label' => 'insiden pihak ketiga',
                'from' => 'third_party', 'to' => 'vendor_incident',
                'kind' => 'fk', 'table' => 'vendor_incidents', 'owner' => 'vendor_incident',
                'column' => 'vendor_id', 'points_to' => 'third_party', 'inverse' => true,
            ],
            [
                'relation' => 'incident_of_breach', 'label' => 'kasus dari insiden',
                'from' => 'breach', 'to' => 'vendor_incident',
                'kind' => 'fk', 'table' => 'vendor_incidents', 'owner' => 'vendor_incident',
                'column' => 'linked_breach_id', 'points_to' => 'breach', 'inverse' => true,
            ],
            [
                'relation' => 'has_contract', 'label' => 'kontrak',
                'from' => 'third_party', 'to' => 'contract',
                'kind' => 'fk', 'table' => 'vendor_contracts', 'owner' => 'contract',
                'column' => 'vendor_id', 'points_to' => 'third_party', 'inverse' => true,
            ],
            [
                // Inilah yang menyambungkan Contract Review ke TPRM. Kolomnya sudah
                // lama ditulis ContractReviewLinker, hanya belum pernah digambar —
                // sehingga Contract Review tampak terisolasi padahal tidak.
                'relation' => 'contract_reviewed', 'label' => 'ditinjau',
                'from' => 'contract', 'to' => 'contract_review',
                'kind' => 'fk', 'table' => 'vendor_contracts', 'owner' => 'contract',
                'column' => 'contract_review_id', 'points_to' => 'contract_review',
            ],
            [
                'relation' => 'third_party_ropa', 'label' => 'dicatat pihak ketiga',
                'from' => 'third_party', 'to' => 'vendor_ropa',
                'kind' => 'fk', 'table' => 'vendor_ropas', 'owner' => 'vendor_ropa',
                'column' => 'vendor_id', 'points_to' => 'third_party', 'inverse' => true,
            ],
            [
                'relation' => 'covers_ropa', 'label' => 'menyentuh kegiatan',
                'from' => 'vendor_ropa', 'to' => 'ropa',
                'kind' => 'pivot', 'table' => 'ropa_vendor_ropa',
                'from_key' => 'vendor_ropa_id', 'to_key' => 'ropa_id',
            ],

            // ---------- Permintaan subjek data ----------
            [
                'relation' => 'searches_system', 'label' => 'menelusuri sistem',
                'from' => 'dsr', 'to' => 'data_discovery',
                'kind' => 'fk', 'table' => 'dsr_request_scopes', 'owner' => 'dsr_scope',
                'column' => 'information_system_id', 'points_to' => 'data_discovery',
                'owner_column' => 'dsr_request_id', 'owner_type' => 'dsr',
            ],
            [
                'relation' => 'searches_system', 'label' => 'menelusuri sistem',
                'from' => 'dsr', 'to' => 'data_discovery',
                'kind' => 'fk', 'table' => 'dsr_executions', 'owner' => 'dsr_execution',
                'column' => 'information_system_id', 'points_to' => 'data_discovery',
                'owner_column' => 'dsr_request_id', 'owner_type' => 'dsr',
            ],
        ];
    }

    /**
     * Dari mana simpul tiap jenis dibaca, dan bagaimana ia ditampilkan.
     *
     * `label` sengaja TIDAK BOLEH kolom yang memuat data pribadi. DSR misalnya
     * berlabel `request_id`, bukan nama pemohon — grafnya bisa berakhir sebagai
     * berkas JSON di storage dan diunduh, jadi apa pun yang masuk ke sini harus
     * aman dilihat siapa pun yang boleh membuka peta.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function nodeSources(): array
    {
        return [
            'ropa' => ['table' => 'ropas', 'label' => 'processing_activity', 'code' => 'registration_number', 'href' => '/ropa?open=', 'soft' => true],
            'dpia' => ['table' => 'dpias', 'label' => 'registration_number', 'code' => 'registration_number', 'href' => '/dpia?open=', 'soft' => true],
            'lia' => ['table' => 'lia_assessments', 'label' => 'lia_code', 'code' => 'lia_code', 'href' => '/lia?open=', 'soft' => true],
            'tia' => ['table' => 'tia_assessments', 'label' => 'title', 'code' => 'tia_code', 'href' => '/tia?open=', 'soft' => true],
            'cross_border' => ['table' => 'cross_border_transfers', 'label' => 'destination_entity', 'code' => 'destination_country', 'href' => '/cross-border?open=', 'soft' => true],
            'third_party' => ['table' => 'vendors', 'label' => 'name', 'code' => 'country', 'href' => '/vendor-risk?open=', 'soft' => true],
            'breach' => ['table' => 'breach_incidents', 'label' => 'title', 'code' => 'incident_code', 'href' => '/breach?open=', 'soft' => true],
            // Label DSR = nomor permintaan. JANGAN diganti nama/email pemohon.
            'dsr' => ['table' => 'dsr_requests', 'label' => 'request_id', 'code' => 'request_type', 'href' => '/dsr?open=', 'soft' => true],
            'data_discovery' => ['table' => 'information_systems', 'label' => 'name', 'code' => 'source_type', 'href' => '/data-discovery?open=', 'soft' => true],
            'consent' => ['table' => 'consent_collection_points', 'label' => 'name', 'code' => 'collection_id', 'href' => '/consent?open=', 'soft' => true],
            'contract' => ['table' => 'vendor_contracts', 'label' => 'title', 'code' => 'contract_type', 'href' => '/vendor-risk?open=', 'soft' => true],
            'contract_review' => ['table' => 'contract_reviews', 'label' => 'title', 'code' => 'contract_type', 'href' => '/contract-review?open=', 'soft' => true],
            'vendor_incident' => ['table' => 'vendor_incidents', 'label' => 'title', 'code' => 'kind', 'href' => '/vendor-risk?open=', 'soft' => true],
            'vendor_ropa' => ['table' => 'vendor_ropas', 'label' => 'processing_activity', 'code' => null, 'href' => '/vendor-risk?open=', 'soft' => true],
        ];
    }

    /**
     * Jalur (swimlane) untuk peta GLOBAL satu modul.
     *
     * Peta global yang sekadar menumpahkan semua simpul akan jadi bola benang
     * pada tenant dengan ratusan record — terlihat mewah, tak terbaca. Dipisah
     * menurut TAHAP modulnya sendiri, peta itu justru menjawab pertanyaan yang
     * memang ditanyakan orang: berapa yang masih di screening, dan mereka sudah
     * menyentuh data apa saja.
     *
     * @return array<string, array{column: string, order: array<string, string>}>
     */
    public static function lanes(): array
    {
        return [
            'third_party' => ['column' => 'lifecycle_status', 'order' => [
                'prospective' => 'Screening',
                'in_onboarding' => 'Onboarding',
                'active' => 'Aktif',
                'suspended' => 'Ditangguhkan',
                'offboarding' => 'Offboarding',
                'terminated' => 'Berakhir',
            ]],
            'ropa' => ['column' => 'status', 'order' => [
                'draft' => 'Draf', 'submitted' => 'Diajukan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak',
            ]],
            'breach' => ['column' => 'status', 'order' => [
                'detected' => 'Terdeteksi', 'assessing' => 'Assessment', 'containment' => 'Containment',
                'notification' => 'Notifikasi', 'closed' => 'Ditutup',
            ]],
            'dpia' => ['column' => 'status', 'order' => [
                'draft' => 'Draf', 'submitted' => 'Diajukan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak',
            ]],
            'dsr' => ['column' => 'status', 'order' => [
                'new' => 'Baru', 'in_progress' => 'Diproses', 'completed' => 'Selesai', 'rejected' => 'Ditolak',
            ]],
        ];
    }

    /** Peran tautan pihak ketiga → label tepi, sejalan dengan scanner DSPM. */
    public const ROLE_RELATIONS = [
        Vendor::ROLE_CONTROLLER => 'dibagikan ke pengendali',
        Vendor::ROLE_PROCESSOR => 'diproses',
        Vendor::ROLE_JOINT_CONTROLLER => 'pengendali bersama',
        Vendor::ROLE_SUB_PROCESSOR => 'diproses subprosesor',
    ];

    /** Jenis simpul yang punya tautan lintas modul sama sekali. */
    public static function linkableTypes(): array
    {
        $types = [];
        foreach (self::relations() as $r) {
            $types[$r['from']] = true;
            $types[$r['to']] = true;
        }

        return array_keys($types);
    }
}
