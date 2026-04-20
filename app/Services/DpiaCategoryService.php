<?php

namespace App\Services;

use App\Models\DpiaCategory;
use App\Models\DpiaCategoryRisk;
use Illuminate\Support\Facades\DB;

/**
 * Lazy-seed the 21 Nexus UU PDP categories + their 5 default risks the first
 * time a tenant hits the DPIA module. After that, DPO CRUDs freely — system
 * defaults no longer auto-sync.
 */
class DpiaCategoryService
{
    public static function ensureSeeded(string $orgId): void
    {
        if (DpiaCategory::where('org_id', $orgId)->exists()) return;

        DB::transaction(function () use ($orgId) {
            foreach (self::defaults() as $idx => $bucket) {
                $cat = DpiaCategory::create([
                    'org_id' => $orgId,
                    'name' => $bucket['name'],
                    'sequence' => $idx + 1,
                    'is_active' => true,
                ]);
                foreach ($bucket['risks'] as $rIdx => $riskName) {
                    DpiaCategoryRisk::create([
                        'org_id' => $orgId,
                        'category_id' => $cat->id,
                        'risk_event' => $riskName,
                        'sequence' => $rIdx + 1,
                        'is_active' => true,
                    ]);
                }
            }
        });
    }

    private static function defaults(): array
    {
        return [
            ['name' => 'Dasar Hukum Pemrosesan', 'risks' => [
                'Penggunaan Dasar Pemrosesan yang Tidak Tepat',
                'Potensi Penarikan Persetujuan oleh Subjek Data',
                'Ketidakcocokan dengan Prinsip Minimalisasi Data',
                'Ketidakpatuhan Terhadap Hak Subjek Data',
                'Kurangnya Transparansi dan Kepercayaan Subjek Data',
            ]],
            ['name' => 'Pemrosesan Data Pribadi yang Sah', 'risks' => [
                'Persetujuan yang Kedaluwarsa atau Tidak Relevan',
                'Pelanggaran Prinsip Penggunaan Data yang Minim dan Tujuan Terbatas',
                'Penurunan Kepercayaan Subjek Data',
                'Potensi Masalah saat Penarikan Persetujuan',
                'Risiko Kebocoran Data yang Sudah Tidak Diperlukan',
            ]],
            ['name' => 'Kesesuaian Tujuan Pemrosesan', 'risks' => [
                'Ketidaksesuaian Informasi dengan Praktik Pemrosesan yang sebenarnya',
                'Potensi Ketidakpatuhan terhadap Regulasi PDP',
                'Subjek Data Tidak Dapat Menggunakan Haknya Secara Optimal',
                'Pelanggaran Prinsip Transparansi',
                'Penggunaan Data yang Tidak Sesuai dengan Tujuan Pengumpulan',
            ]],
            ['name' => 'Minimisasi Data', 'risks' => [
                'Pengumpulan Data Pribadi yang Tidak Relevan atau Berlebihan',
                'Peningkatan Risiko Kebocoran Data dan Akses Tidak Sah',
                'Tantangan dalam Penghapusan Data yang Tidak Relevan',
                'Ketidaksesuaian dengan Harapan dan Hak Subjek Data',
                'Kompleksitas dalam Manajemen Data dan Pengawasan',
            ]],
            ['name' => 'Keakuratan Data', 'risks' => [
                'Ketidaklengkapan data pribadi',
                'Ketidakakuratan dan Ketidaktepatan Data',
                'Duplikasi dan Inkonsistensi Data',
                'Ketidakpastian dalam Tanggung Jawab Kualitas Data',
                'Waktu dan Biaya Lebih Besar untuk Verifikasi Data',
            ]],
            ['name' => 'Pembatasan Penyimpanan', 'risks' => [
                'Persetujuan yang Kedaluwarsa atau Tidak Relevan',
                'Pelanggaran Prinsip Penggunaan Data yang Minim dan Tujuan Terbatas',
                'Penurunan Kepercayaan Subjek Data',
                'Potensi Masalah saat Penarikan Persetujuan',
                'Risiko Kebocoran Data yang Sudah Tidak Diperlukan',
            ]],
            ['name' => 'Integritas dan Kerahasiaan', 'risks' => [
                'Kebocoran Data pada Field yang Tidak Dienkripsi',
                'Risiko Privilege Escalation oleh Insiders',
                'Hilangnya Kepercayaan Pelanggan',
                'Akses Tidak Sah Melalui Backup atau Salinan Database',
                'Kebocoran Data Pribadi Melalui Media Penyimpanan yang Tidak Aman',
            ]],
            ['name' => 'Akuntabilitas', 'risks' => [
                'Pihak Ketiga Tidak Mematuhi regulasi PDP dan Keamanan Data',
                'Penyalahgunaan Data Pribadi oleh Pihak Ketiga',
                'Risiko Keamanan Tidak Teridentifikasi dalam Proses Pengelolaan Data oleh Pihak Ketiga',
                'Menurunnya Kepercayaan dari Pelanggan dan Mitra Bisnis',
                'Ketidakmampuan untuk Mengambil Langkah Pemulihan dengan Cepat jika Terjadi Insiden',
            ]],
            ['name' => 'Hak Subjek Data - Akses', 'risks' => [
                'Tidak Tersedianya Mekanisme yang Jelas untuk Memenuhi Hak Subjek Data',
                'Keterlambatan dalam Menanggapi Permintaan Hak Subjek Data',
                'Kesalahan dalam Memproses Hak Subjek Data',
                'Pelanggaran PDP Akibat Ketidaksengajaan dalam Pengungkapan Data Pribadi',
                'Tidak Terdokumentasinya Proses Permintaan Hak Subjek Data',
            ]],
            ['name' => 'Hak Subjek Data - Koreksi', 'risks' => [
                'Tidak Tersedianya Mekanisme yang Jelas untuk Memenuhi Hak Subjek Data',
                'Keterlambatan dalam Menanggapi Permintaan Hak Subjek Data',
                'Kesalahan dalam Memproses Hak Subjek Data',
                'Ketidakakuratan dan Ketidaktepatan Data',
                'Tidak Terdokumentasinya Proses Permintaan Hak Subjek Data',
            ]],
            ['name' => 'Hak Subjek Data - Hapus', 'risks' => [
                'Penyimpanan Data yang Tidak Diperlukan Melebihi Masa Retensi',
                'Penghapusan atau Penghancuran yang Tidak Aman',
                'Tidak Ada Bukti Penghapusan atau Penghancuran Data',
                'Akses Tidak Sah terhadap Data yang Harusnya Dihapus',
                'Ketidakpatuhan terhadap regulasi PDP',
            ]],
            ['name' => 'Hak Subjek Data - Portabilitas', 'risks' => [
                'Tidak Tersedianya Mekanisme yang Jelas untuk Memenuhi Hak Subjek Data',
                'Keterlambatan dalam Menanggapi Permintaan Hak Subjek Data',
                'Kesalahan dalam Memproses Hak Subjek Data',
                'Pelanggaran PDP Akibat Ketidaksengajaan dalam Pengungkapan Data Pribadi',
                'Tidak Terdokumentasinya Proses Permintaan Hak Subjek Data',
            ]],
            ['name' => 'Persetujuan dan Consent', 'risks' => [
                'Persetujuan yang Kedaluwarsa atau Tidak Relevan',
                'Potensi Masalah saat Penarikan Persetujuan',
                'Penurunan Kepercayaan Subjek Data',
                'Pelanggaran Prinsip Transparansi',
                'Risiko Kebocoran Data yang Sudah Tidak Diperlukan',
            ]],
            ['name' => 'Transfer Data Lintas Batas', 'risks' => [
                'Tidak Adanya Mekanisme Pengendalian untuk Transfer Data ke Luar Negeri',
                'Kegagalan Mematuhi Persyaratan Peraturan Terkait Transfer Data Internasional',
                'Kebocoran Data Selama Transfer',
                'Kegagalan Memastikan Perlindungan Data yang Konsisten di Negara Tujuan',
                'Kurangnya Transparansi dalam Transfer Data Lintas Batas',
            ]],
            ['name' => 'Enkripsi dan Pseudonymization', 'risks' => [
                'Kebocoran Data Pribadi dalam Bentuk yang Dapat Diidentifikasi',
                'Akses Tidak Sah oleh Pihak Internal atau Eksternal',
                'Eksploitasi Data Pribadi dalam Proses Analitik',
                'Ketergantungan pada Kontrol Akses Saja',
                'Potensi Penyalahgunaan Data saat Berbagi dengan Pihak Ketiga',
            ]],
            ['name' => 'Kontrol Akses', 'risks' => [
                'Pengungkapan Data kepada Pihak yang Tidak Berwenang',
                'Penyalahgunaan Data oleh Pihak Ketiga',
                'Tidak Ada Pembatasan yang Jelas terhadap Akses Internal',
                'Kurangnya Transparansi dalam Proses data sharing',
                'Penggunaan Data yang Tidak Sesuai dengan Tujuan Pengumpulan',
            ]],
            ['name' => 'Monitoring dan Logging', 'risks' => [
                'Aktivitas Akses Tidak Sah Tidak Teridentifikasi Tepat Waktu',
                'Deteksi Terlambat atas Perilaku Mencurigakan atau Anomali Akses',
                'Tidak Efektifnya Log dalam Menyediakan Bukti yang Auditable',
                'Penggunaan Data oleh Pihak Internal Tanpa Otorisasi yang Jelas',
                'Peningkatan Beban dan Kompleksitas saat Insiden Terjadi',
            ]],
            ['name' => 'Retensi Data', 'risks' => [
                'Penyimpanan Data yang Tidak Diperlukan Melebihi Masa Retensi',
                'Penghapusan atau Penghancuran yang Tidak Aman',
                'Tidak Ada Bukti Penghapusan atau Penghancuran Data',
                'Ketidakpatuhan terhadap regulasi PDP',
                'Akses Tidak Sah terhadap Data yang Harusnya Dihapus',
            ]],
            ['name' => 'Manajemen Insiden', 'risks' => [
                'Kegagalan Pemulihan Data saat Dibutuhkan',
                'Kehilangan Data Pribadi yang Rentan terhadap Kebocoran dan Pelanggaran PDP',
                'Ketidakcocokan antara Data Backup dan Data yang Ada',
                'Keterlambatan dalam Pemulihan Sistem saat Terjadi Bencana atau Insiden Keamanan',
                'Kurangnya Dokumentasi dan Prosedur yang Tepat untuk Proses Pemulihan',
            ]],
            ['name' => 'Pelatihan dan Kesadaran', 'risks' => [
                'Kurangnya Pemahaman dari Pihak Internal Mengenai Tujuan Pemrosesan',
                'Penggunaan Data oleh Pihak Internal Tanpa Otorisasi yang Jelas',
                'Ketidakmampuan untuk Mengambil Langkah Pemulihan dengan Cepat jika Terjadi Insiden',
                'Risiko Privilege Escalation oleh Insiders',
                'Tidak Efektifnya Log dalam Menyediakan Bukti yang Auditable',
            ]],
            ['name' => 'Penilaian Dampak Berkala', 'risks' => [
                'Deteksi Terlambat atas Aktivitas Penggunaan Data yang Tidak Sah',
                'Akses Data yang Tidak Terkontrol selama proses Pemantauan',
                'Kurangnya Pemantauan Aktivitas Pihak Ketiga yang Mengakses Data Pribadi',
                'Tidak Adanya Peringatan Dini terhadap Penyalahgunaan Data',
                'Sulitnya Menyediakan Bukti Audit yang Komprehensif',
            ]],
        ];
    }
}
