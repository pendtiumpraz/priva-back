<?php

namespace App\Services;

use App\Models\DpiaCategory;
use App\Models\DpiaCategoryRisk;
use Illuminate\Support\Facades\DB;

/**
 * Lazy-seed the 21 DPIA risk-control categories + their question text + default
 * risk events the first time a tenant hits the DPIA module. After that, DPO
 * CRUDs freely — system defaults no longer auto-sync.
 *
 * Kategori + teks pertanyaan mengikuti kuesioner DPIA baku (lihat dpia.txt):
 * Legal Basis, Retensi, Autentikasi, … Hak Subjek Data. `description` kategori
 * = teks pertanyaan yang ditampilkan di wizard Edit Potensi Risiko + detail DPIA.
 */
class DpiaCategoryService
{
    public static function ensureSeeded(string $orgId): void
    {
        if (DpiaCategory::where('org_id', $orgId)->exists()) {
            return;
        }

        self::seedFor($orgId);
    }

    /**
     * (Re)seed the 21 default categories for an org. Used by ensureSeeded (lazy)
     * and by the dpia:resync-categories command (forced refresh).
     */
    public static function seedFor(string $orgId): void
    {
        DB::transaction(function () use ($orgId) {
            foreach (self::defaults() as $idx => $bucket) {
                $cat = DpiaCategory::create([
                    'org_id' => $orgId,
                    'name' => $bucket['name'],
                    'description' => $bucket['question'] ?? null,
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
            ['name' => 'Legal Basis', 'question' => 'Apakah organisasi telah melakukan identifikasi pemrosesan beserta dasar pemrosesan / legal basis yang terkait? Jika iya, sebutkan frekuensinya.', 'risks' => [
                'Pemrosesan data pribadi tanpa dasar hukum yang sah',
                'Dasar pemrosesan tidak teridentifikasi/terdokumentasi',
                'Ketidakpatuhan terhadap UU PDP',
            ]],
            ['name' => 'Retensi', 'question' => 'Dalam hal Subjek Data menggunakan Persetujuan/Consent sebagai dasar pemrosesan, apakah organisasi telah menentukan batasan waktu dari berlakunya persetujuan tersebut?', 'risks' => [
                'Persetujuan kedaluwarsa tetap digunakan',
                'Tidak ada batas waktu berlakunya persetujuan',
                'Pemrosesan melewati masa berlaku consent',
            ]],
            ['name' => 'Autentikasi', 'question' => 'Apakah terdapat mekanisme autentikasi yang diterapkan pada sistem/aplikasi yang berkaitan dengan pemrosesan data pribadi (mis. MFA, kompleksitas password)? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Akses tidak sah akibat autentikasi lemah',
                'Pembobolan kredensial pengguna',
                'Sistem kritikal tanpa MFA',
            ]],
            ['name' => 'Pemantauan Akses', 'question' => 'Apakah organisasi melakukan pemantauan terhadap akses data pribadi secara berkala? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Akses tidak sah tidak terdeteksi tepat waktu',
                'Tidak tersedia log akses yang memadai',
                'Anomali akses terlambat diketahui',
            ]],
            ['name' => 'Enkripsi (Structured)', 'question' => 'Apakah organisasi menerapkan enkripsi terhadap data pribadi yang bersifat structured (di dalam database)? Jika iya, sebutkan mekanisme dan teknologinya.', 'risks' => [
                'Kebocoran data dari database tanpa enkripsi',
                'Akses tidak sah ke data terstruktur',
                'Pencurian data melalui salinan/backup database',
            ]],
            ['name' => 'Enkripsi (Unstructured)', 'question' => 'Apakah organisasi menerapkan enkripsi terhadap data pribadi yang bersifat unstructured (media penyimpanan, file sharing, cloud, folder server/komputer)? Jika iya, sebutkan mekanisme dan teknologinya.', 'risks' => [
                'Kebocoran file/dokumen yang tidak terenkripsi',
                'Akses tidak sah ke file sharing / cloud',
                'Kehilangan media penyimpanan berisi data pribadi',
            ]],
            ['name' => 'Anonimisasi', 'question' => 'Apakah organisasi menerapkan anonimisasi / penyamaran data untuk memastikan data pribadi tidak dapat dibaca oleh pihak yang tidak berwenang?', 'risks' => [
                'Data pribadi terekspos dalam bentuk teridentifikasi',
                'Re-identifikasi data yang seharusnya anonim',
                'Penyalahgunaan data dalam proses analitik',
            ]],
            ['name' => 'Autorisasi (Hak Akses)', 'question' => 'Apakah organisasi melakukan pembatasan pengungkapan data pribadi (membatasi pihak yang dapat menerima data)? Jika iya, sebutkan mekanisme dan kebijakan/prosedurnya.', 'risks' => [
                'Pengungkapan data ke pihak tidak berwenang',
                'Tidak ada pembatasan akses internal yang jelas',
                'Hak akses berlebihan (over-privilege)',
            ]],
            ['name' => 'Pihak Ketiga', 'question' => 'Apakah organisasi memiliki standar manajemen risiko privasi pihak ketiga / third party privacy risk management? Jika iya, sebutkan kebijakan/prosedurnya.', 'risks' => [
                'Pihak ketiga tidak mematuhi UU PDP',
                'Penyalahgunaan data pribadi oleh pihak ketiga',
                'Tidak ada perjanjian pemrosesan data (DPA)',
            ]],
            ['name' => 'Pemantauan Berkala', 'question' => 'Apakah organisasi melakukan pemantauan secara berkala terhadap penggunaan data pribadi, baik oleh internal maupun pihak ketiga? Jika iya, sebutkan mekanisme/teknologinya.', 'risks' => [
                'Penyalahgunaan data tidak terpantau',
                'Aktivitas pihak ketiga tidak dimonitor',
                'Tidak ada peringatan dini penyalahgunaan data',
            ]],
            ['name' => 'Uji Tuntas', 'question' => 'Apakah organisasi melakukan penilaian atau uji tuntas / due diligence terhadap pihak ketiga yang memroses data pribadi? Jika iya, sebutkan frekuensinya.', 'risks' => [
                'Risiko pihak ketiga tidak teridentifikasi',
                'Vendor tanpa kontrol keamanan yang memadai',
                'Tidak ada evaluasi/asesmen vendor berkala',
            ]],
            ['name' => 'Pemetaan Data Pribadi', 'question' => 'Apakah organisasi melakukan pemetaan data pribadi pada siklus pemrosesan data pribadi? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Aliran data pribadi tidak terpetakan',
                'Shadow data / data tersembunyi tidak terlacak',
                'Sulit memenuhi hak subjek karena data tak terlacak',
            ]],
            ['name' => 'Back-up dan Restore', 'question' => 'Apakah organisasi telah melakukan pengujian Backup dan Restore secara berkala? Jika iya, sebutkan frekuensinya.', 'risks' => [
                'Kegagalan pemulihan data saat dibutuhkan',
                'Backup korup atau tidak pernah diuji',
                'Kehilangan data pribadi secara permanen',
            ]],
            ['name' => 'Keamanan Back-up', 'question' => 'Apakah organisasi telah menerapkan mekanisme keamanan pada Backup (mis. enkripsi dan pembatasan akses)? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Akses tidak sah ke media/penyimpanan backup',
                'Backup tidak terenkripsi',
                'Kebocoran data melalui salinan backup',
            ]],
            ['name' => 'Data Minimization', 'question' => 'Apakah organisasi telah meminimalisasi data pribadi yang dikumpulkan dan memastikan hanya data yang relevan dengan tujuannya yang dikumpulkan? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Pengumpulan data pribadi yang berlebihan',
                'Penyimpanan data yang tidak relevan',
                'Peningkatan permukaan risiko kebocoran',
            ]],
            ['name' => 'Pemberitahuan Privasi', 'question' => 'Apakah seluruh tujuan dari pemrosesan data pribadi yang dilakukan organisasi tercantum dalam Pemberitahuan Privasi (Privacy Notice)?', 'risks' => [
                'Tujuan pemrosesan tidak transparan ke subjek data',
                'Privacy notice tidak lengkap atau usang',
                'Pelanggaran prinsip transparansi',
            ]],
            ['name' => 'Pemusnahan Data', 'question' => 'Apakah organisasi telah menerapkan penghapusan/pemusnahan yang aman untuk data pribadi yang telah melewati masa retensinya (secure disposal)? Apakah dilengkapi bukti penghapusan?', 'risks' => [
                'Penyimpanan data melebihi masa retensi',
                'Penghapusan tidak aman / data dapat dipulihkan',
                'Tidak ada bukti penghapusan/pemusnahan',
            ]],
            ['name' => 'Kualitas Data', 'question' => 'Apakah organisasi telah menentukan standar kualitas data? Jika iya, sebutkan nama dokumen standar kualitas data yang dimiliki.', 'risks' => [
                'Data pribadi tidak akurat / usang',
                'Duplikasi dan inkonsistensi data',
                'Keputusan diambil berdasarkan data yang salah',
            ]],
            ['name' => 'Verifikasi Data', 'question' => 'Apakah organisasi telah menyusun dan menerapkan standar proses verifikasi data? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Data tidak terverifikasi kebenarannya',
                'Kesalahan input data tidak terdeteksi',
                'Identitas subjek data tidak tervalidasi',
            ]],
            ['name' => 'Transfer Luar Negeri', 'question' => 'Apakah organisasi telah melakukan identifikasi dan penerapan pengendalian untuk transfer data ke luar negeri (Cross Border Data Transfer)? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Transfer data tanpa safeguard yang memadai',
                'Negara tujuan tanpa perlindungan setara',
                'Ketidakpatuhan regulasi transfer internasional',
            ]],
            ['name' => 'Hak Subjek Data', 'question' => 'Apakah organisasi telah menetapkan mekanisme agar Subjek Data Pribadi mendapatkan hak subjek data yang diatur oleh UU PDP? Jika iya, sebutkan mekanismenya.', 'risks' => [
                'Hak subjek data tidak dapat dipenuhi',
                'Keterlambatan menanggapi permintaan subjek data',
                'Proses permintaan hak tidak terdokumentasi',
            ]],
        ];
    }
}
