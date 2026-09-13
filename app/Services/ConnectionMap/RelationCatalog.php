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
     * Jenis simpul → module_id izin (seperti yang ditulis di `permission:` rute).
     *
     * Dipakai gerbang ENTITLEMENT peta. Sebelumnya hanya modul yang DIPUSATKAN
     * yang digerbangi, sedangkan simpul tetangganya tidak — sehingga tenant yang
     * modul DPIA-nya dicabut tetap melihat simpul DPIA di peta RoPA-nya. Itu
     * memperlihatkan data dari modul yang sudah bukan miliknya.
     *
     * Jenis yang tidak ada di sini tidak punya konsep entitlement tersendiri
     * (mis. `rtp` yang diturunkan dari DPIA, dan `contract`/`vendor_incident`
     * yang hidup di dalam TPRM).
     */
    public const MODULE_ID = [
        'ropa' => 'ropa',
        'dpia' => 'dpia',
        // Item penanganan risiko hidup DI DALAM baris DPIA (mitigation_tracking),
        // bukan modul tersendiri. Ia harus ikut nasib DPIA: kalau tidak,
        // mencabut DPIA menyisakan simpul RTP yatim yang tetap memberi tahu
        // "ada sekian item penanganan risiko".
        'rtp' => 'dpia',
        'dsr' => 'dsr',
        'consent' => 'consent',
        'breach' => 'breach',
        'data_discovery' => 'data_discovery',
        'third_party' => 'vendor_risk',
        'cross_border' => 'cross_border',
        'lia' => 'lia',
        'tia' => 'tia',
        'contract_review' => 'contract_review',
        'contract' => 'vendor_risk',
        'vendor_incident' => 'vendor_risk',
        'vendor_ropa' => 'vendor_risk',
    ];

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
                // Bentuk lama yang masih dipertahankan saat settings diperbarui —
                // cadangan bagi pivot `consent_collection_ropa`.
                'relation' => 'consent_basis', 'label' => 'dasar consent',
                'from' => 'consent', 'to' => 'ropa', 'is_fallback' => true,
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
                'relation' => 'balanced_by_lia', 'label' => 'dinilai LIA',
                'from' => 'ropa', 'to' => 'lia',
                'kind' => 'fk', 'table' => 'lia_assessments', 'owner' => 'lia',
                'column' => 'linked_ropa_id', 'points_to' => 'ropa', 'inverse' => true,
            ],
            [
                'relation' => 'referenced_by_lia', 'label' => 'dirujuk LIA',
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
                'relation' => 'received_by', 'label' => 'diterima pihak ketiga',
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
                'relation' => 'impacted_by', 'label' => 'terdampak insiden',
                'from' => 'ropa', 'to' => 'breach',
                'kind' => 'fk', 'table' => 'breach_incidents', 'owner' => 'breach',
                'column' => 'linked_ropa_id', 'points_to' => 'ropa', 'inverse' => true,
            ],
            [
                'relation' => 'impacted_by', 'label' => 'terdampak insiden',
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
                // CADANGAN bagi pivot `ropa_vendor`. RoPA lama yang belum
                // tersinkron ke pivot menyimpan daftar UUID di wizard, TANPA
                // peran. Ia hanya boleh dipakai bila pasangan itu belum
                // dinyatakan pivot — kalau tidak, pihak ketiga yang di pivot
                // berperan `sub_processed_by` akan mendapat tepi KEDUA
                // `processed_by` yang salah dan menggandakan hubungannya.
                'relation' => 'processed_by', 'label' => 'diproses pihak ketiga',
                'from' => 'ropa', 'to' => 'third_party', 'is_fallback' => true,
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
                // Pihak ketiga yang DIPASTIKAN terlibat pada insiden — kolom yang
                // ditandai orang. Dugaan hasil penelusuran (BreachThirdPartyController)
                // sengaja TIDAK digambar: peta ini menampilkan hubungan yang sudah
                // ditegaskan, bukan kemungkinan.
                'relation' => 'involves_third_party', 'label' => 'melibatkan pihak ketiga',
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
                'relation' => 'targets', 'label' => 'menyasar sistem',
                'from' => 'dsr', 'to' => 'data_discovery',
                'kind' => 'fk', 'table' => 'dsr_request_scopes', 'owner' => 'dsr_scope',
                'column' => 'information_system_id', 'points_to' => 'data_discovery',
                'owner_column' => 'dsr_request_id', 'owner_type' => 'dsr',
            ],
            [
                'relation' => 'targets', 'label' => 'menyasar sistem',
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

    /** Peran tautan pihak ketiga (pivot ber-`role`) → slug relasi. */
    public const ROLE_RELATIONS = [
        Vendor::ROLE_CONTROLLER => 'shared_to_controller',
        Vendor::ROLE_PROCESSOR => 'processed_by',
        Vendor::ROLE_JOINT_CONTROLLER => 'joint_controller_with',
        Vendor::ROLE_SUB_PROCESSOR => 'sub_processed_by',
    ];

    /**
     * Arti tepi, dibaca dari `from` ke `to` — SATU sumber untuk semua peta.
     *
     * Nilai untuk slug yang sudah ada disalin PERSIS dari
     * ConnectionMapScanner::RELATION_LABELS. Mengubah satu kata pun di sini akan
     * mengganti teks tepi pada peta DSPM yang sudah dipakai, dan ujinya tidak
     * akan menangkapnya karena ia memeriksa pasangan (from, to), bukan label.
     */
    public const LABELS = [
        'supplies' => 'memasok data',
        'consent_basis' => 'dasar consent',
        'assessed_by_dpia' => 'dinilai DPIA',
        'treated_by' => 'ditangani RTP',
        'transfers' => 'mentransfer',
        'balanced_by_lia' => 'dinilai LIA',
        'referenced_by_lia' => 'dirujuk LIA',
        'assessed_by_tia' => 'dinilai TIA',
        'impacted_by' => 'terdampak insiden',
        'processed_by' => 'diproses pihak ketiga',
        'shared_to_controller' => 'dibagikan ke pengendali lain',
        'joint_controller_with' => 'pengendali bersama',
        'sub_processed_by' => 'diproses subprosesor',
        'involves_third_party' => 'melibatkan pihak ketiga',
        'received_by' => 'diterima pihak ketiga',
        'targets' => 'menyasar sistem',
        // Tautan yang sebelumnya tidak pernah digambar peta mana pun.
        'operates_system' => 'memegang sistem',
        'breach_system' => 'sistem terdampak',
        'third_party_incident' => 'insiden pihak ketiga',
        'incident_of_breach' => 'kasus dari insiden',
        'has_contract' => 'kontrak',
        'contract_reviewed' => 'ditinjau',
        'third_party_ropa' => 'dicatat pihak ketiga',
        'covers_ropa' => 'menyentuh kegiatan',
    ];

    public static function labelFor(string $relation): string
    {
        return self::LABELS[$relation] ?? $relation;
    }

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
