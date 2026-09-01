<?php

namespace App\Support;

/**
 * Registry kewajiban yang dapat dikenai SANKSI ADMINISTRATIF menurut
 * PP 33/2026 Pasal 184-186. Data referensi statik (bukan tabel) — bunyi
 * Pasal 184(1) enumeratif dan tidak diedit tenant.
 *
 * Tiap kewajiban punya `signal` opsional yang menautkannya ke state platform
 * nyata untuk menghitung paparan (exposure) — lihat SanctionExposureService.
 */
class SanctionRegistry
{
    /** Jenis sanksi — Pasal 184(2). */
    public const SANCTION_TYPES = [
        'peringatan_tertulis' => 'Peringatan tertulis',
        'penghentian_sementara' => 'Penghentian sementara kegiatan pemrosesan Data Pribadi',
        'penghapusan_pemusnahan' => 'Penghapusan atau pemusnahan Data Pribadi',
        'denda_administratif' => 'Denda administratif',
    ];

    /** Denda administratif — Pasal 185. */
    public const FINE_MAX_PERCENT = 2.0;

    public const FINE_BASIS = 'Paling tinggi 2% (dua persen) dari pendapatan/penerimaan tahunan terhadap variabel pelanggaran (PP 33/2026 Pasal 185).';

    /** Variabel penghitung denda — Pasal 185(2). */
    public const FINE_VARIABLES = [
        'Dampak negatif akibat pelanggaran',
        'Durasi waktu terjadinya pelanggaran',
        'Jenis Data Pribadi yang terdampak',
        'Jumlah Subjek Data Pribadi yang terdampak',
        'Proses temuan pelanggaran',
        'Tingkat keterbukaan & kerja sama saat pemeriksaan',
        'Skala usaha Pengendali/Prosesor',
        'Kemampuan membayar',
        'Tingkat kepatuhan',
        'Variabel lain yang ditetapkan Lembaga',
    ];

    /**
     * Kewajiban yang dapat dikenai sanksi — Pasal 184(1).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function obligations(): array
    {
        return [
            ['article' => 'Pasal 15 ayat (2)', 'no' => 15, 'category' => 'processor', 'obligation' => 'Perjanjian dengan Prosesor Data Pribadi lain yang dilibatkan'],
            ['article' => 'Pasal 29', 'no' => 29, 'category' => 'governance', 'obligation' => 'Melaksanakan perintah Lembaga'],
            ['article' => 'Pasal 30 ayat (1)', 'no' => 30, 'category' => 'processing', 'obligation' => 'Memiliki dasar pemrosesan Data Pribadi'],
            ['article' => 'Pasal 33 ayat (1)', 'no' => 33, 'category' => 'consent', 'obligation' => 'Persetujuan yang sah secara eksplisit untuk data spesifik'],
            ['article' => 'Pasal 36 ayat (1)', 'no' => 36, 'category' => 'consent', 'obligation' => 'Menunjukkan bukti persetujuan'],
            ['article' => 'Pasal 38 ayat (2)', 'no' => 38, 'category' => 'children', 'obligation' => 'Identifikasi & pelindungan Data Anak'],
            ['article' => 'Pasal 39 ayat (4)', 'no' => 39, 'category' => 'children', 'obligation' => 'Identifikasi & pelindungan Data Penyandang Disabilitas'],
            ['article' => 'Pasal 58', 'no' => 58, 'category' => 'processing', 'obligation' => 'Pemrosesan terbatas & spesifik sesuai tujuan'],
            ['article' => 'Pasal 62 ayat (1) & (5)', 'no' => 62, 'category' => 'transparency', 'obligation' => 'Menyampaikan Informasi (pemberitahuan) ke Subjek Data'],
            ['article' => 'Pasal 67 ayat (1)', 'no' => 67, 'category' => 'processing', 'obligation' => 'Pemrosesan sesuai tujuan'],
            ['article' => 'Pasal 69 ayat (1) & (2)', 'no' => 69, 'category' => 'data_quality', 'obligation' => 'Akurasi, kelengkapan, konsistensi Data Pribadi'],
            ['article' => 'Pasal 71 ayat (1) & (6)', 'no' => 71, 'category' => 'data_quality', 'obligation' => 'Memperbarui & memperbaiki Data Pribadi'],
            ['article' => 'Pasal 74 ayat (1)', 'no' => 74, 'category' => 'records', 'obligation' => 'Perekaman seluruh kegiatan pemrosesan (RoPA)', 'signal' => 'ropa_records'],
            ['article' => 'Pasal 77 ayat (1) & (4)', 'no' => 77, 'category' => 'rights', 'obligation' => 'Memberikan akses Data Pribadi ke Subjek Data'],
            ['article' => 'Pasal 80 ayat (1)', 'no' => 80, 'category' => 'retention', 'obligation' => 'Mengakhiri pemrosesan saat masa retensi tercapai', 'signal' => 'retention_end'],
            ['article' => 'Pasal 82 ayat (1)', 'no' => 82, 'category' => 'retention', 'obligation' => 'Menghapus Data Pribadi'],
            ['article' => 'Pasal 84 ayat (1)', 'no' => 84, 'category' => 'retention', 'obligation' => 'Memusnahkan Data Pribadi'],
            ['article' => 'Pasal 87 ayat (1)', 'no' => 87, 'category' => 'rights', 'obligation' => 'Memberitahukan penghapusan/pemusnahan ke Subjek Data'],
            ['article' => 'Pasal 92 ayat (1)', 'no' => 92, 'category' => 'consent', 'obligation' => 'Menghentikan pemrosesan saat persetujuan ditarik'],
            ['article' => 'Pasal 99 ayat (1)', 'no' => 99, 'category' => 'rights', 'obligation' => 'Penundaan & pembatasan pemrosesan'],
            ['article' => 'Pasal 103 ayat (1)', 'no' => 103, 'category' => 'rights', 'obligation' => 'Memberitahukan penundaan & pembatasan'],
            ['article' => 'Pasal 114 ayat (1)', 'no' => 114, 'category' => 'breach', 'obligation' => 'Notifikasi Kegagalan Pelindungan 3×24 jam (Subjek + Lembaga)', 'signal' => 'breach_notification'],
            ['article' => 'Pasal 115 ayat (1)', 'no' => 115, 'category' => 'breach', 'obligation' => 'Notifikasi kegagalan kepada masyarakat'],
            ['article' => 'Pasal 120 ayat (1)', 'no' => 120, 'category' => 'dpia', 'obligation' => 'Penilaian Dampak (DPIA) untuk pemrosesan risiko tinggi', 'signal' => 'dpia'],
            ['article' => 'Pasal 123 ayat (1)', 'no' => 123, 'category' => 'security', 'obligation' => 'Melindungi & memastikan keamanan Data Pribadi'],
            ['article' => 'Pasal 125 ayat (1)', 'no' => 125, 'category' => 'security', 'obligation' => 'Menjaga kerahasiaan Data Pribadi'],
            ['article' => 'Pasal 126 ayat (1)', 'no' => 126, 'category' => 'security', 'obligation' => 'Pengawasan setiap pihak yang terlibat pemrosesan'],
            ['article' => 'Pasal 129', 'no' => 129, 'category' => 'security', 'obligation' => 'Melindungi Data dari pemrosesan tidak sah'],
            ['article' => 'Pasal 130 ayat (1)', 'no' => 130, 'category' => 'security', 'obligation' => 'Mencegah akses tidak sah'],
            ['article' => 'Pasal 133 ayat (1)', 'no' => 133, 'category' => 'governance', 'obligation' => 'Pelindungan data pada penggabungan/pemisahan/akuisisi'],
            ['article' => 'Pasal 138 ayat (1)', 'no' => 138, 'category' => 'governance', 'obligation' => 'Akuntabilitas — bertanggung jawab & menunjukkan kepatuhan'],
            ['article' => 'Pasal 139 ayat (1)', 'no' => 139, 'category' => 'processor', 'obligation' => 'Kewajiban Prosesor Data Pribadi'],
            ['article' => 'Pasal 140', 'no' => 140, 'category' => 'processor', 'obligation' => 'Pemenuhan kewajiban oleh Prosesor'],
            ['article' => 'Pasal 142 ayat (1)', 'no' => 142, 'category' => 'ppdp', 'obligation' => 'Menunjuk PPDP saat wajib', 'signal' => 'ppdp_appointment'],
            ['article' => 'Pasal 160 ayat (2)', 'no' => 160, 'category' => 'transfer', 'obligation' => 'Pelindungan Data pada transfer ke luar wilayah'],
            ['article' => 'Pasal 165 ayat (1), (2), (3)', 'no' => 165, 'category' => 'transfer', 'obligation' => 'Kriteria/mekanisme transfer ke luar wilayah'],
        ];
    }

    public const CATEGORY_LABELS = [
        'governance' => 'Tata Kelola',
        'processing' => 'Dasar & Pemrosesan',
        'consent' => 'Persetujuan',
        'children' => 'Anak & Disabilitas',
        'transparency' => 'Transparansi',
        'data_quality' => 'Kualitas Data',
        'records' => 'Perekaman (RoPA)',
        'rights' => 'Hak Subjek Data',
        'retention' => 'Retensi & Penghapusan',
        'breach' => 'Kegagalan Pelindungan',
        'dpia' => 'Penilaian Dampak',
        'security' => 'Keamanan',
        'processor' => 'Prosesor',
        'ppdp' => 'PPDP',
        'transfer' => 'Transfer Lintas Negara',
    ];
}
