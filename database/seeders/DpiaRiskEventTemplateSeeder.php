<?php

namespace Database\Seeders;

use App\Models\DpiaRiskEventTemplate;
use Illuminate\Database\Seeder;

/**
 * Seed the DPIA risk event library matching pdp.privasimu.com existing
 * platform's control-area taxonomy (22 buckets, ~110 risk events).
 *
 * DPO picks relevant risks from this library to attach per-category during
 * their DPIA assessment, then scores each with Dampak, Probabilitas,
 * Kontrol, and Penanganan.
 *
 * Idempotent — safe to run multiple times.
 */
class DpiaRiskEventTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->library() as $bucket) {
            $key = $bucket['key'];
            $label = $bucket['label'];
            foreach ($bucket['risks'] as $i => $riskName) {
                DpiaRiskEventTemplate::updateOrCreate(
                    ['category_key' => $key, 'sequence' => $i + 1, 'risk_event' => $riskName, 'is_system' => true, 'org_id' => null],
                    ['category_label' => $label, 'is_active' => true]
                );
            }
        }
    }

    private function library(): array
    {
        return [
            ['key' => 'legal_basis', 'label' => 'Legal Basis', 'risks' => [
                'Persetujuan yang Kedaluwarsa atau Tidak Relevan',
                'Pelanggaran Prinsip Penggunaan Data yang Minim dan Tujuan Terbatas',
                'Penurunan Kepercayaan Subjek Data',
                'Potensi Masalah saat Penarikan Persetujuan',
                'Risiko Kebocoran Data yang Sudah Tidak Diperlukan',
            ]],
            ['key' => 'dasar_pemrosesan', 'label' => 'Dasar Pemrosesan', 'risks' => [
                'Penggunaan Dasar Pemrosesan yang Tidak Tepat',
                'Potensi Penarikan Persetujuan oleh Subjek Data',
                'Ketidakcocokan dengan Prinsip Minimalisasi Data',
                'Ketidakpatuhan Terhadap Hak Subjek Data',
                'Kurangnya Transparansi dan Kepercayaan Subjek Data',
            ]],
            ['key' => 'retensi', 'label' => 'Retensi', 'risks' => [
                'Persetujuan yang Kedaluwarsa atau Tidak Relevan',
                'Pelanggaran Prinsip Penggunaan Data yang Minim dan Tujuan Terbatas',
                'Penurunan Kepercayaan Subjek Data',
                'Potensi Masalah saat Penarikan Persetujuan',
                'Risiko Kebocoran Data yang Sudah Tidak Diperlukan',
            ]],
            ['key' => 'penilaian_risiko_autentikasi', 'label' => 'Penilaian Risiko (Autentikasi)', 'risks' => [
                'Rentan terhadap Serangan Brute Force',
                'Akun yang Mudah Diretas karena Phishing',
                'Akun Terkunci karena Serangan Credential Stuffing',
                'Risiko Privilege Escalation',
                'Kelemahan pada Password yang Lemah atau Mudah Ditebak',
            ]],
            ['key' => 'pemantauan_akses', 'label' => 'Pemantauan Akses', 'risks' => [
                'Aktivitas Akses Tidak Sah Tidak Teridentifikasi Tepat Waktu',
                'Deteksi Terlambat atas Perilaku Mencurigakan atau Anomali Akses',
                'Tidak Efektifnya Log dalam Menyediakan Bukti yang Auditable',
                'Penggunaan Data oleh Pihak Internal Tanpa Otorisasi yang Jelas',
                'Peningkatan Beban dan Kompleksitas saat Insiden Terjadi',
            ]],
            ['key' => 'enkripsi_structured', 'label' => 'Enkripsi (Structured)', 'risks' => [
                'Kebocoran Data pada Field yang Tidak Dienkripsi',
                'Risiko Privilege Escalation oleh Insiders',
                'Hilangnya Kepercayaan Pelanggan',
                'Akses Tidak Sah Melalui Backup atau Salinan Database',
            ]],
            ['key' => 'enkripsi_unstructured', 'label' => 'Enkripsi (Unstructured)', 'risks' => [
                'Kebocoran Data Pribadi Melalui Media Penyimpanan yang Tidak Aman',
                'Kebocoran Data pada Platform File Sharing atau Cloud File Sharing',
                'Penyalahgunaan Data Pribadi di Komputer Personal atau Folder Jaringan',
                'Risiko Akses Tidak Sah dalam Proses Transfer File yang tidak dienkripsi',
            ]],
            ['key' => 'anonimisasi', 'label' => 'Anonimisasi', 'risks' => [
                'Kebocoran Data Pribadi dalam Bentuk yang Dapat Diidentifikasi',
                'Akses Tidak Sah oleh Pihak Internal atau Eksternal',
                'Eksploitasi Data Pribadi dalam Proses Analitik',
                'Ketergantungan pada Kontrol Akses Saja',
                'Potensi Penyalahgunaan Data saat Berbagi dengan Pihak Ketiga',
            ]],
            ['key' => 'autorisasi', 'label' => 'Autorisasi (Hak Akses)', 'risks' => [
                'Pengungkapan Data kepada Pihak yang Tidak Berwenang',
                'Penyalahgunaan Data oleh Pihak Ketiga',
                'Tidak Ada Pembatasan yang Jelas terhadap Akses Internal',
                'Kurangnya Transparansi dalam Proses data sharing',
                'Penggunaan Data yang Tidak Sesuai dengan Tujuan Pengumpulan',
            ]],
            ['key' => 'pihak_ketiga', 'label' => 'Pihak Ketiga', 'risks' => [
                'Pengungkapan Data Pribadi secara Tidak Sah oleh Pihak Ketiga',
                'Ketidaksesuaian Proses Pengolahan Data oleh Pihak Ketiga dengan Kebijakan PDP perusahaan',
                'Pihak Ketiga Menggunakan Data untuk Tujuan di Luar Kesepakatan',
                'Tidak Adanya Pengawasan terhadap Kegiatan Pemrosesan Data oleh Pihak Ketiga',
                'Lemahnya Perlindungan Data dalam Proses Transfer Data ke Pihak Ketiga',
            ]],
            ['key' => 'pemantauan_berkala', 'label' => 'Pemantauan Berkala', 'risks' => [
                'Deteksi Terlambat atas Aktivitas Penggunaan Data yang Tidak Sah',
                'Akses Data yang Tidak Terkontrol selama proses Pemantauan',
                'Kurangnya Pemantauan Aktivitas Pihak Ketiga yang Mengakses Data Pribadi',
                'Tidak Adanya Peringatan Dini terhadap Penyalahgunaan Data',
                'Sulitnya Menyediakan Bukti Audit yang Komprehensif',
            ]],
            ['key' => 'uji_tuntas', 'label' => 'Uji Tuntas', 'risks' => [
                'Pihak Ketiga Tidak Mematuhi regulasi PDP dan Keamanan Data',
                'Penyalahgunaan Data Pribadi oleh Pihak Ketiga',
                'Risiko Keamanan Tidak Teridentifikasi dalam Proses Pengelolaan Data oleh Pihak Ketiga',
                'Menurunnya Kepercayaan dari Pelanggan dan Mitra Bisnis',
                'Ketidakmampuan untuk Mengambil Langkah Pemulihan dengan Cepat jika Terjadi Insiden',
            ]],
            ['key' => 'pemetaan_data', 'label' => 'Pemetaan Data Pribadi', 'risks' => [
                'Tidak Teridentifikasinya Data Pribadi yang Diproses di Seluruh Tahap Siklus',
                'Kesulitan dalam Mengelola Hak Subjek Data',
                'Pengelolaan Data yang Tidak Efisien',
                'Risiko Kebocoran Data yang Tidak Terkendali',
                'Penggunaan Data yang Tidak Sesuai dengan Prinsip Minimasi Data',
            ]],
            ['key' => 'backup_restore', 'label' => 'Back-up dan Restore', 'risks' => [
                'Kegagalan Pemulihan Data saat Dibutuhkan',
                'Kehilangan Data Pribadi yang Rentan terhadap Kebocoran dan Pelanggaran PDP',
                'Ketidakcocokan antara Data Backup dan Data yang Ada',
                'Keterlambatan dalam Pemulihan Sistem saat Terjadi Bencana atau Insiden Keamanan',
                'Kurangnya Dokumentasi dan Prosedur yang Tepat untuk Proses Pemulihan',
            ]],
            ['key' => 'keamanan_backup', 'label' => 'Keamanan Back-up', 'risks' => [
                'Akses Tidak Sah terhadap Data Backup',
                'Penyalahgunaan Data oleh Pihak Internal',
                'Kehilangan atau Pencurian Media Backup',
                'Serangan Ransomware atau Malware terhadap Backup',
                'Penyadapan Data Selama Transfer Backup',
            ]],
            ['key' => 'data_minimization', 'label' => 'Data Minimization', 'risks' => [
                'Pengumpulan Data Pribadi yang Tidak Relevan atau Berlebihan',
                'Peningkatan Risiko Kebocoran Data dan Akses Tidak Sah',
                'Tantangan dalam Penghapusan Data yang Tidak Relevan',
                'Ketidaksesuaian dengan Harapan dan Hak Subjek Data',
                'Kompleksitas dalam Manajemen Data dan Pengawasan',
            ]],
            ['key' => 'pemberitahuan_privasi', 'label' => 'Pemberitahuan Privasi', 'risks' => [
                'Ketidaksesuaian Informasi dengan Praktik Pemrosesan yang sebenarnya',
                'Potensi Ketidakpatuhan terhadap Regulasi PDP',
                'Subjek Data Tidak Dapat Menggunakan Haknya Secara Optimal',
                'Potensi Tuntutan Hukum atau Pengaduan',
                'Pelanggaran Prinsip Transparansi',
                'Kurangnya Pemahaman dari Pihak Internal Mengenai Tujuan Pemrosesan',
            ]],
            ['key' => 'pemusnahan_data', 'label' => 'Pemusnahan Data', 'risks' => [
                'Penyimpanan Data yang Tidak Diperlukan Melebihi Masa Retensi',
                'Penghapusan atau Penghancuran yang Tidak Aman',
                'Tidak Ada Bukti Penghapusan atau Penghancuran Data',
                'Ketidakpatuhan terhadap regulasi PDP',
                'Akses Tidak Sah terhadap Data yang Harusnya Dihapus',
            ]],
            ['key' => 'kualitas_data', 'label' => 'Kualitas Data', 'risks' => [
                'Ketidaklengkapan data pribadi',
                'Ketidakpatuhan terhadap regulasi PDP',
                'Tumpang tindih / duplikasi dan inkonsistensi Data',
                'Ketidakpastian dalam Tanggung Jawab Kualitas Data',
            ]],
            ['key' => 'verifikasi_data', 'label' => 'Verifikasi Data', 'risks' => [
                'Ketidakakuratan dan Ketidaktepatan Data',
                'Duplikasi dan Inkonsistensi Data',
                'Risiko Kepatuhan',
                'Waktu dan Biaya Lebih Besar untuk Verifikasi Data',
                'Tidak Ada Tanggung Jawab yang Jelas',
                'Potensi Pelanggaran PDP dan Keamanan Data',
            ]],
            ['key' => 'transfer_luar_negeri', 'label' => 'Transfer Luar Negeri', 'risks' => [
                'Tidak Adanya Mekanisme Pengendalian untuk Transfer Data ke Luar Negeri',
                'Kegagalan Mematuhi Persyaratan Peraturan Terkait Transfer Data Internasional',
                'Kebocoran Data Selama Transfer',
                'Kegagalan Memastikan Perlindungan Data yang Konsisten di Negara Tujuan',
                'Kurangnya Transparansi dalam Transfer Data Lintas Batas',
            ]],
            ['key' => 'hak_subjek_data', 'label' => 'Hak Subjek Data', 'risks' => [
                'Tidak Tersedianya Mekanisme yang Jelas untuk Memenuhi Hak Subjek Data',
                'Keterlambatan dalam Menanggapi Permintaan Hak Subjek Data',
                'Kesalahan dalam Memproses Hak Subjek Data',
                'Pelanggaran PDP Akibat Ketidaksengajaan dalam Pengungkapan Data Pribadi',
                'Tidak Terdokumentasinya Proses Permintaan Hak Subjek Data',
            ]],
        ];
    }
}
