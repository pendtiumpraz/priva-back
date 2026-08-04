<?php

namespace App\Services;

use App\Models\ControlLibraryItem;

/**
 * Katalog kontrol bawaan dan penyemaiannya per tenant.
 *
 * Kontrol dipilih dari kewajiban yang benar-benar disebut UU PDP, bukan dari
 * daftar praktik keamanan umum. Alasannya praktis: pustaka ini dipakai untuk
 * menjawab "kontrol apa yang menutup risiko ini" di hadapan pemeriksa, dan
 * kontrol yang tidak dapat ditelusuri ke dasar hukum tidak menolong di situ.
 *
 * Rujukan pasal disertakan pada tiap butir supaya laporan DPIA dapat menunjuk
 * dasarnya tanpa penyusunnya harus mencari sendiri.
 */
class ControlLibraryService
{
    /**
     * Semai default bila tenant belum punya satu pun kontrol.
     *
     * Sengaja tidak menyemai ulang ketika tenant sudah memilikinya — termasuk
     * ketika tenant sudah menghapus semuanya dengan sengaja. Penyemaian ulang
     * otomatis akan membuat kontrol yang dibuang muncul kembali tanpa diminta.
     */
    public static function ensureSeeded(string $orgId): int
    {
        $exists = ControlLibraryItem::withoutGlobalScope('org')
            ->withTrashed()
            ->where('org_id', $orgId)
            ->exists();

        if ($exists) {
            return 0;
        }

        return self::seed($orgId);
    }

    /** Tulis ulang katalog default untuk satu tenant. */
    public static function seed(string $orgId): int
    {
        $count = 0;
        foreach (self::defaults() as $i => $item) {
            ControlLibraryItem::create(array_merge($item, [
                'org_id' => $orgId,
                'sequence' => ($i + 1) * 10,
                'is_seeded' => true,
                'is_active' => true,
            ]));
            $count++;
        }

        return $count;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            // ---------------- Teknis ----------------
            [
                'code' => 'TEK-01',
                'category' => 'teknis',
                'title' => 'Enkripsi data pribadi saat disimpan',
                'description' => 'Data pribadi, terutama data pribadi spesifik, disimpan dalam bentuk terenkripsi sehingga tidak terbaca bila media penyimpanan diakses tanpa hak.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Terapkan enkripsi pada tingkat kolom untuk NIK, data biometrik, data kesehatan, dan data keuangan. Kunci disimpan terpisah dari basis datanya.',
                'reference' => 'UU PDP Pasal 35',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'TEK-02',
                'category' => 'teknis',
                'title' => 'Enkripsi data pribadi saat dikirim',
                'description' => 'Seluruh pengiriman data pribadi antar sistem memakai kanal terenkripsi.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'TLS 1.2 ke atas untuk seluruh antarmuka, termasuk lalu lintas antar layanan di dalam jaringan internal.',
                'reference' => 'UU PDP Pasal 35',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'TEK-03',
                'category' => 'teknis',
                'title' => 'Pembatasan hak akses berbasis peran',
                'description' => 'Akses ke data pribadi dibatasi menurut peran dan kebutuhan tugas, bukan diberikan secara menyeluruh.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Tetapkan matriks hak akses per peran, tinjau ulang berkala, dan cabut akses segera saat pegawai berpindah tugas.',
                'reference' => 'UU PDP Pasal 39',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'TEK-04',
                'category' => 'teknis',
                'title' => 'Pencatatan jejak audit akses data pribadi',
                'description' => 'Setiap akses, perubahan, dan penghapusan data pribadi tercatat beserta identitas pelakunya.',
                'control_type' => 'detektif',
                'implementation_guidance' => 'Simpan jejak audit pada media yang tidak dapat diubah pelakunya sendiri, dengan masa simpan sesuai kewajiban kearsipan.',
                'reference' => 'UU PDP Pasal 39',
                'default_effectiveness' => 2,
            ],
            [
                'code' => 'TEK-05',
                'category' => 'teknis',
                'title' => 'Penyamaran data pada lingkungan non-produksi',
                'description' => 'Data pribadi nyata tidak dipakai di lingkungan pengembangan dan pengujian.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Terapkan penyamaran atau data sintetis pada seluruh salinan basis data untuk pengembangan dan pengujian.',
                'reference' => 'UU PDP Pasal 35',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'TEK-06',
                'category' => 'teknis',
                'title' => 'Penghapusan otomatis setelah masa retensi',
                'description' => 'Data pribadi dimusnahkan setelah masa retensinya berakhir, tanpa bergantung pada tindakan manual.',
                'control_type' => 'korektif',
                'implementation_guidance' => 'Tetapkan masa retensi per kategori data, jalankan proses pemusnahan terjadwal, dan simpan bukti pemusnahannya.',
                'reference' => 'UU PDP Pasal 43',
                'default_effectiveness' => 2,
            ],
            [
                'code' => 'TEK-07',
                'category' => 'teknis',
                'title' => 'Pemantauan anomali akses',
                'description' => 'Pola akses tidak wajar terhadap data pribadi terdeteksi dan ditindaklanjuti.',
                'control_type' => 'detektif',
                'implementation_guidance' => 'Tetapkan ambang kewajaran per peran, mis. unduhan massal di luar jam kerja, dan alirkan peringatannya ke tim keamanan.',
                'reference' => 'UU PDP Pasal 39',
                'default_effectiveness' => 2,
            ],
            [
                'code' => 'TEK-08',
                'category' => 'teknis',
                'title' => 'Pencadangan dan uji pemulihan',
                'description' => 'Data pribadi dicadangkan berkala dan prosedur pemulihannya diuji, bukan hanya didokumentasikan.',
                'control_type' => 'korektif',
                'implementation_guidance' => 'Cadangan disimpan terenkripsi di lokasi terpisah, dan uji pemulihan dijalankan minimal setahun sekali dengan bukti hasilnya.',
                'reference' => 'UU PDP Pasal 35',
                'default_effectiveness' => 2,
            ],

            // ---------------- Organisasi ----------------
            [
                'code' => 'ORG-01',
                'category' => 'organisasi',
                'title' => 'Penunjukan Pejabat Pelindungan Data Pribadi',
                'description' => 'Organisasi menunjuk pejabat yang bertanggung jawab atas pelindungan data pribadi.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Tetapkan penunjukan secara tertulis beserta kewenangan, jalur pelaporan, dan sumber daya yang memadai.',
                'reference' => 'UU PDP Pasal 53-54',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'ORG-02',
                'category' => 'organisasi',
                'title' => 'Pelatihan kesadaran pelindungan data pribadi',
                'description' => 'Pegawai yang menangani data pribadi memperoleh pelatihan berkala.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Selenggarakan pelatihan minimal setahun sekali, dengan materi berbeda untuk peran yang berbeda, dan simpan bukti kehadirannya.',
                'reference' => 'UU PDP Pasal 39',
                'default_effectiveness' => 2,
            ],
            [
                'code' => 'ORG-03',
                'category' => 'organisasi',
                'title' => 'Prosedur penanganan insiden kebocoran',
                'description' => 'Tersedia prosedur baku penanganan kebocoran data pribadi beserta batas waktu pemberitahuannya.',
                'control_type' => 'korektif',
                'implementation_guidance' => 'Prosedur memuat peran, jalur eskalasi, dan pemberitahuan kepada subjek data serta lembaga berwenang dalam 3x24 jam.',
                'reference' => 'UU PDP Pasal 46',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'ORG-04',
                'category' => 'organisasi',
                'title' => 'Uji coba penanganan insiden',
                'description' => 'Prosedur penanganan insiden diuji secara berkala, tidak hanya disusun.',
                'control_type' => 'detektif',
                'implementation_guidance' => 'Selenggarakan simulasi minimal setahun sekali dan catat temuan beserta perbaikannya.',
                'reference' => 'UU PDP Pasal 46',
                'default_effectiveness' => 2,
            ],
            [
                'code' => 'ORG-05',
                'category' => 'organisasi',
                'title' => 'Pemeliharaan rekaman kegiatan pemrosesan',
                'description' => 'Seluruh kegiatan pemrosesan data pribadi terdokumentasi dan dimutakhirkan.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Tinjau ulang RoPA berkala dan setiap kali terjadi perubahan tujuan, sumber, penerima, atau masa retensi.',
                'reference' => 'UU PDP Pasal 31',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'ORG-06',
                'category' => 'organisasi',
                'title' => 'Penilaian dampak sebelum pemrosesan berisiko tinggi',
                'description' => 'Pemrosesan berisiko tinggi dinilai dampaknya sebelum dijalankan.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Tetapkan pemicu wajib DPIA, mis. data pribadi spesifik, pemantauan sistematis, atau pengambilan keputusan otomatis.',
                'reference' => 'UU PDP Pasal 34',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'ORG-07',
                'category' => 'organisasi',
                'title' => 'Prosedur pemenuhan hak subjek data',
                'description' => 'Permohonan hak subjek data ditangani melalui alur baku dengan batas waktu yang terpantau.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Sediakan kanal penerimaan, mekanisme verifikasi identitas pemohon, dan pemantauan tenggat 3x24 jam.',
                'reference' => 'UU PDP Pasal 5-13',
                'default_effectiveness' => 3,
            ],

            // ---------------- Hukum ----------------
            [
                'code' => 'HUK-01',
                'category' => 'hukum',
                'title' => 'Perjanjian pemrosesan dengan prosesor',
                'description' => 'Setiap pihak ketiga yang memproses data pribadi terikat perjanjian tertulis.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Perjanjian memuat ruang lingkup, kewajiban keamanan, larangan subkontrak tanpa izin, dan kewajiban pemusnahan saat berakhir.',
                'reference' => 'UU PDP Pasal 51',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'HUK-02',
                'category' => 'hukum',
                'title' => 'Pengamanan transfer data ke luar wilayah Indonesia',
                'description' => 'Transfer data pribadi ke luar negeri disertai pengamanan yang memadai.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Pastikan negara tujuan memiliki tingkat pelindungan setara, atau gunakan klausul kontraktual baku, dan dokumentasikan dasar transfernya.',
                'reference' => 'UU PDP Pasal 56',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'HUK-03',
                'category' => 'hukum',
                'title' => 'Pencatatan dasar hukum pemrosesan',
                'description' => 'Setiap kegiatan pemrosesan memiliki dasar hukum yang tercatat dan dapat dipertanggungjawabkan.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Tetapkan dasar hukum per kegiatan pada RoPA, dan tinjau ulang bila tujuan pemrosesannya berubah.',
                'reference' => 'UU PDP Pasal 20',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'HUK-04',
                'category' => 'hukum',
                'title' => 'Pengelolaan persetujuan yang dapat dibuktikan',
                'description' => 'Persetujuan subjek data terekam beserta waktu, cakupan, dan cara pemberiannya.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Simpan bukti persetujuan beserta versi naskah yang berlaku saat itu, dan sediakan mekanisme penarikan yang semudah pemberiannya.',
                'reference' => 'UU PDP Pasal 21-24',
                'default_effectiveness' => 3,
            ],
            [
                'code' => 'HUK-05',
                'category' => 'hukum',
                'title' => 'Pemberitahuan privasi kepada subjek data',
                'description' => 'Subjek data memperoleh informasi tujuan, dasar hukum, dan haknya sebelum datanya diproses.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Sediakan pemberitahuan privasi yang mudah diakses, dikelola terpusat, dan berversi agar dapat dibuktikan naskah mana yang berlaku pada suatu waktu.',
                'reference' => 'UU PDP Pasal 21',
                'default_effectiveness' => 3,
            ],

            // ---------------- Fisik ----------------
            [
                'code' => 'FIS-01',
                'category' => 'fisik',
                'title' => 'Pembatasan akses fisik ke ruang server dan arsip',
                'description' => 'Akses fisik ke tempat penyimpanan data pribadi dibatasi dan tercatat.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Terapkan kendali akses fisik beserta pencatatan keluar-masuk, dan tinjau ulang daftar pemegang akses berkala.',
                'reference' => 'UU PDP Pasal 35',
                'default_effectiveness' => 2,
            ],
            [
                'code' => 'FIS-02',
                'category' => 'fisik',
                'title' => 'Pemusnahan dokumen fisik secara aman',
                'description' => 'Dokumen fisik berisi data pribadi dimusnahkan sehingga tidak dapat direkonstruksi.',
                'control_type' => 'korektif',
                'implementation_guidance' => 'Gunakan pencacah silang atau jasa pemusnahan bersertifikat, dan simpan berita acara pemusnahannya.',
                'reference' => 'UU PDP Pasal 43',
                'default_effectiveness' => 2,
            ],
            [
                'code' => 'FIS-03',
                'category' => 'fisik',
                'title' => 'Kebijakan meja dan layar bersih',
                'description' => 'Data pribadi tidak dibiarkan terlihat pada meja kerja maupun layar yang ditinggalkan.',
                'control_type' => 'preventif',
                'implementation_guidance' => 'Tetapkan penguncian layar otomatis dan kewajiban menyimpan dokumen fisik saat meja ditinggalkan.',
                'reference' => 'UU PDP Pasal 35',
                'default_effectiveness' => 1,
            ],
        ];
    }
}
