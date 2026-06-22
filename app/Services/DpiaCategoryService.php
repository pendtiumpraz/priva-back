<?php

namespace App\Services;

use App\Models\DpiaCategory;
use App\Models\DpiaCategoryRisk;
use Database\Seeders\DpiaRiskEventTemplateSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Lazy-seed the 21 DPIA risk-control categories + question text + default risk
 * events the first time a tenant hits the DPIA module.
 *
 * Kategori + teks pertanyaan mengikuti kuesioner DPIA baku (dpia.txt). Default
 * RISK EVENT diambil dari library riset DpiaRiskEventTemplateSeeder (SATU sumber
 * kebenaran) — TIDAK dikarang ulang. `description` kategori = teks pertanyaan
 * yang ditampilkan di wizard Edit Potensi Risiko + detail DPIA.
 */
class DpiaCategoryService
{
    /**
     * 21 kategori: nama (label dpia.txt), teks pertanyaan, dan risk_key yang
     * memetakan ke bucket library riset (DpiaRiskEventTemplateSeeder::library()).
     */
    private const CATEGORIES = [
        ['name' => 'Legal Basis', 'risk_key' => 'legal_basis', 'question' => 'Apakah organisasi telah melakukan identifikasi pemrosesan beserta dasar pemrosesan / legal basis yang terkait? Jika iya, sebutkan frekuensinya.'],
        ['name' => 'Retensi', 'risk_key' => 'retensi', 'question' => 'Dalam hal Subjek Data menggunakan Persetujuan/Consent sebagai dasar pemrosesan, apakah organisasi telah menentukan batasan waktu dari berlakunya persetujuan tersebut?'],
        ['name' => 'Autentifikasi', 'risk_key' => 'penilaian_risiko_autentikasi', 'question' => 'Apakah terdapat mekanisme autentikasi yang diterapkan pada sistem/aplikasi yang berkaitan dengan pemrosesan data pribadi (mis. MFA, kompleksitas password)? Jika iya, sebutkan mekanismenya.'],
        ['name' => 'Pemantauan Akses', 'risk_key' => 'pemantauan_akses', 'question' => 'Apakah organisasi melakukan pemantauan terhadap akses data pribadi secara berkala? Jika iya, sebutkan mekanismenya.'],
        ['name' => 'Enkripsi (Structured)', 'risk_key' => 'enkripsi_structured', 'question' => 'Apakah organisasi menerapkan enkripsi terhadap data pribadi yang bersifat structured (di dalam database)? Jika iya, sebutkan mekanisme dan teknologinya.'],
        ['name' => 'Enkripsi (Unstructured)', 'risk_key' => 'enkripsi_unstructured', 'question' => 'Apakah organisasi menerapkan enkripsi terhadap data pribadi yang bersifat unstructured (media penyimpanan, file sharing, cloud, folder server/komputer)? Jika iya, sebutkan mekanisme dan teknologinya.'],
        ['name' => 'Anonimisasi', 'risk_key' => 'anonimisasi', 'question' => 'Apakah organisasi menerapkan anonimisasi / penyamaran data untuk memastikan data pribadi tidak dapat dibaca oleh pihak yang tidak berwenang?'],
        ['name' => 'Autorisasi (Hak Akses)', 'risk_key' => 'autorisasi', 'question' => 'Apakah organisasi melakukan pembatasan pengungkapan data pribadi (membatasi pihak yang dapat menerima data)? Jika iya, sebutkan mekanisme dan kebijakan/prosedurnya.'],
        ['name' => 'Pihak Ketiga', 'risk_key' => 'pihak_ketiga', 'question' => 'Apakah organisasi memiliki standar manajemen risiko privasi pihak ketiga / third party privacy risk management? Jika iya, sebutkan kebijakan/prosedurnya.'],
        ['name' => 'Pemantauan Berkala', 'risk_key' => 'pemantauan_berkala', 'question' => 'Apakah organisasi melakukan pemantauan secara berkala terhadap penggunaan data pribadi, baik oleh internal maupun pihak ketiga? Jika iya, sebutkan mekanisme/teknologinya.'],
        ['name' => 'Uji Tuntas', 'risk_key' => 'uji_tuntas', 'question' => 'Apakah organisasi melakukan penilaian atau uji tuntas / due diligence terhadap pihak ketiga yang memroses data pribadi? Jika iya, sebutkan frekuensinya.'],
        ['name' => 'Pemetaan Data Pribadi', 'risk_key' => 'pemetaan_data', 'question' => 'Apakah organisasi melakukan pemetaan data pribadi pada siklus pemrosesan data pribadi? Jika iya, sebutkan mekanismenya.'],
        ['name' => 'Back-up dan Restore', 'risk_key' => 'backup_restore', 'question' => 'Apakah organisasi telah melakukan pengujian Backup dan Restore secara berkala? Jika iya, sebutkan frekuensinya.'],
        ['name' => 'Keamanan Back-up', 'risk_key' => 'keamanan_backup', 'question' => 'Apakah organisasi telah menerapkan mekanisme keamanan pada Backup (mis. enkripsi dan pembatasan akses)? Jika iya, sebutkan mekanismenya.'],
        ['name' => 'Data Minimization', 'risk_key' => 'data_minimization', 'question' => 'Apakah organisasi telah meminimalisasi data pribadi yang dikumpulkan dan memastikan hanya data yang relevan dengan tujuannya yang dikumpulkan? Jika iya, sebutkan mekanismenya.'],
        ['name' => 'Pemberitahuan Privasi', 'risk_key' => 'pemberitahuan_privasi', 'question' => 'Apakah seluruh tujuan dari pemrosesan data pribadi yang dilakukan organisasi tercantum dalam Pemberitahuan Privasi (Privacy Notice)?'],
        ['name' => 'Pemusnahan Data', 'risk_key' => 'pemusnahan_data', 'question' => 'Apakah organisasi telah menerapkan penghapusan/pemusnahan yang aman untuk data pribadi yang telah melewati masa retensinya (secure disposal)? Apakah dilengkapi bukti penghapusan?'],
        ['name' => 'Kualitas Data', 'risk_key' => 'kualitas_data', 'question' => 'Apakah organisasi telah menentukan standar kualitas data? Jika iya, sebutkan nama dokumen standar kualitas data yang dimiliki.'],
        ['name' => 'Verifikasi Data', 'risk_key' => 'verifikasi_data', 'question' => 'Apakah organisasi telah menyusun dan menerapkan standar proses verifikasi data? Jika iya, sebutkan mekanismenya.'],
        ['name' => 'Transfer Luar Negeri', 'risk_key' => 'transfer_luar_negeri', 'question' => 'Apakah organisasi telah melakukan identifikasi dan penerapan pengendalian untuk transfer data ke luar negeri (Cross Border Data Transfer)? Jika iya, sebutkan mekanismenya.'],
        ['name' => 'Hak Subjek Data', 'risk_key' => 'hak_subjek_data', 'question' => 'Apakah organisasi telah menetapkan mekanisme agar Subjek Data Pribadi mendapatkan hak subjek data yang diatur oleh UU PDP? Jika iya, sebutkan mekanismenya.'],
    ];

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
        $riskLibrary = self::riskLibrary();

        DB::transaction(function () use ($orgId, $riskLibrary) {
            foreach (self::CATEGORIES as $idx => $bucket) {
                $cat = DpiaCategory::create([
                    'org_id' => $orgId,
                    'name' => $bucket['name'],
                    'description' => $bucket['question'],
                    'sequence' => $idx + 1,
                    'is_active' => true,
                ]);
                $risks = $riskLibrary[$bucket['risk_key']] ?? [];
                foreach ($risks as $rIdx => $riskName) {
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

    /**
     * Map risk_key => [risk_event, ...] dari library riset (sumber tunggal).
     */
    private static function riskLibrary(): array
    {
        $out = [];
        foreach (DpiaRiskEventTemplateSeeder::library() as $bucket) {
            $out[$bucket['key']] = $bucket['risks'];
        }

        return $out;
    }
}
