<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegulationFrameworkSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('regulation_frameworks')->truncate();
        DB::table('regulation_frameworks')->updateOrInsert(
            ['code' => 'uupdp'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'UU No. 27 Tahun 2022 (UU PDP)',
                'country' => 'Indonesia',
                'is_active' => true,
                'articles' => '[{"id":"TK-FR-01","topic":"Tata Kelola","question":"Apakah Organisasi telah memiliki kerangka kerja (framework) Pelindungan Data Pribadi yang disetujui manajemen tingkat atas?","article":"Pasal 47","weight":5,"score_weight":5},{"id":"TK-FR-02","topic":"Tata Kelola","question":"Apakah Organisasi telah menetapkan peran dan tanggung jawab struktural terkait pelindungan data pribadi (termasuk Penunjukan DPO)?","article":"Pasal 53","weight":5,"score_weight":5},{"id":"TK-FR-03","topic":"Tata Kelola","question":"Apakah Organisasi memiliki struktur tata kelola komite privasi atau keamanan informasi antar divisi?","article":"Pasal 47","weight":4,"score_weight":4},{"id":"TK-FR-04","topic":"Tata Kelola","question":"Apakah manajemen puncak memiliki KPI terkait kepatuhan privasi dan pelindungan data?","article":"Pasal 47","weight":4,"score_weight":4},{"id":"TK-AS-01","topic":"Asas","question":"Apakah seluruh pemrosesan data dilakukan berdasarkan keabsahan hukum, keadilan, dan transparansi?","article":"Pasal 16","weight":5,"score_weight":5},{"id":"TK-AS-02","topic":"Asas","question":"Apakah tujuan pemrosesan data pribadi bersifat terbatas, spesifik, sah secara hukum, dan eksplisit?","article":"Pasal 16","weight":5,"score_weight":5},{"id":"TK-AS-03","topic":"Asas","question":"Apakah Organisasi menerapkan minimisasi data (hanya memproses data yang relevan dan mencukupi)?","article":"Pasal 16","weight":5,"score_weight":5},{"id":"TK-AS-04","topic":"Asas","question":"Apakah Organisasi menjaga akurasi kemutakhiran data pribadi yang disimpannya?","article":"Pasal 16","weight":4,"score_weight":4},{"id":"DH-PM-01","topic":"Dasar Pemrosesan","question":"Apakah persetujuan yang sah (Consent) telah diperoleh dari subjek data secara eksplisit?","article":"Pasal 20","weight":5,"score_weight":5},{"id":"DH-PM-02","topic":"Dasar Pemrosesan","question":"Apakah terdapat mekanisme pencabutan (withdrawal) persetujuan yang semudah saat memberikannya?","article":"Pasal 9","weight":5,"score_weight":5},{"id":"DH-PM-03","topic":"Dasar Pemrosesan","question":"Apakah dasar hak atau pemenuhan kontrak menjadi dasar hukum valid pemrosesan selain consent?","article":"Pasal 20","weight":4,"score_weight":4},{"id":"DH-PM-04","topic":"Dasar Pemrosesan","question":"Apakah kepentingan vital atau kewajiban hukum perusahaan telah didokumentasikan jika memproses data kritikal?","article":"Pasal 20","weight":4,"score_weight":4},{"id":"SP-AS-RO-01","topic":"ROPA","question":"Apakah Organisasi memiliki dokumen Rekam Jejak Pemrosesan Data Pribadi (ROPA) yang komprehensif?","article":"Pasal 31","weight":5,"score_weight":5},{"id":"SP-AS-RO-02","topic":"ROPA","question":"Apakah ROPA memuat informasi tujuan, kategori data, penerima data, dan estimasi waktu retensi?","article":"Pasal 31","weight":4,"score_weight":4},{"id":"SP-AS-DP-01","topic":"DPIA","question":"Apakah Organisasi mewajibkan DPIA untuk pemrosesan berisiko tinggi (teknologi baru, data spesifik, pemantauan sistemik)?","article":"Pasal 34","weight":5,"score_weight":5},{"id":"SP-AS-DP-02","topic":"DPIA","question":"Apakah hasil DPIA memuat mitigasi risiko dan didiskusikan dengan DPO (atau komite keamanan)?","article":"Pasal 34","weight":5,"score_weight":5},{"id":"SP-PR-DA-01","topic":"Data Sensitif","question":"Apakah pemrosesan data pribadi spesifik (kesehatan, biometrik, keuangan) menggunakan pengamanan ekstra ketat?","article":"Pasal 4","weight":5,"score_weight":5},{"id":"SP-PR-DA-02","topic":"Data Anak","question":"Apakah terdapat proses perolehan persetujuan orang tua atau wali untuk pemrosesan data anak?","article":"Pasal 25","weight":5,"score_weight":5},{"id":"SP-PR-SC-01","topic":"Keamanan Informasi","question":"Apakah Organisasi mengenkripsi data pribadi dalam status diam (at rest) dan berpindah (in transit)?","article":"Pasal 35","weight":5,"score_weight":5},{"id":"SP-PR-SC-02","topic":"Keamanan Informasi","question":"Apakah akses log dan aktivitas database direkam serta dipantau berkala?","article":"Pasal 35","weight":4,"score_weight":4},{"id":"SP-PR-SC-03","topic":"Keamanan Informasi","question":"Apakah kontrol akses terbatas diterapkan (Role Based Access Control) untuk file data sensitif?","article":"Pasal 35","weight":5,"score_weight":5},{"id":"SP-PR-SC-04","topic":"Keamanan Informasi","question":"Apakah uji penetrasi dan kerentanan sistem dilakukan sedikitnya satu kali dalam setahun?","article":"Pasal 35","weight":4,"score_weight":4},{"id":"SP-PR-TP-01","topic":"Pihak Ketiga","question":"Apakah Data Processing Agreement (DPA) wajib ditandatangani oleh semua vendor?","article":"Pasal 47","weight":5,"score_weight":5},{"id":"SP-PR-TP-02","topic":"Pihak Ketiga","question":"Apakah Organisasi melakukan due diligence privasi saat proses on-boarding vendor baru?","article":"Pasal 47","weight":4,"score_weight":4},{"id":"SP-RD-IM-01","topic":"Insiden & Kebocoran","question":"Apakah perusahaan memiliki SOP investigasi dan pelaporan insiden kebocoran secara internal?","article":"Pasal 46","weight":5,"score_weight":5},{"id":"SP-RD-IM-02","topic":"Insiden & Kebocoran","question":"Mampukah Organisasi memberitahukan KOMFINFO dan subjek data dalam waktu 3x24 jam jika terjadi kebocoran?","article":"Pasal 46","weight":5,"score_weight":5},{"id":"SP-PR-DT-01","topic":"Hak Subjek Data","question":"Apakah tersedia formulir dan e-mail khusus untuk menerima permintaan subjek data (DSR)?","article":"Pasal 5-13","weight":5,"score_weight":5},{"id":"SP-PR-DT-02","topic":"Hak Subjek Data","question":"Apakah DSR dapat diselesaikan maksimal dalam 3x24 jam kerja hukum?","article":"Pasal 5-13","weight":4,"score_weight":4},{"id":"SP-PR-DT-03","topic":"Hak Subjek Data","question":"Apakah SOP mengatur verifikasi identitas secara layak sebelum membalas request DSR?","article":"Pasal 5-13","weight":4,"score_weight":4},{"id":"SP-PR-PB-01","topic":"Profiling","question":"Apakah Organisasi memberikan opt-out jika memakai pemrosesan keputusan otomatis atau AI?","article":"Pasal 29","weight":4,"score_weight":4},{"id":"SP-PR-CB-01","topic":"Transfer Lintas Batas","question":"Apakah transfer data lintas batas dilandasi oleh kecukupan standar negara tujuan, SCC, atau persetujuan subjek?","article":"Pasal 56","weight":5,"score_weight":5},{"id":"SP-SU-CP-01","topic":"Sustain","question":"Apakah minimal setiap 1 tahun ada program pelatihan privasi untuk karyawan front-liner dan operasional?","article":"Pasal 47","weight":4,"score_weight":4},{"id":"SP-SU-PA-01","topic":"Sustain","question":"Apakah audit independen dilakukan atas kepatuhan privasi sedikitnya satu kali dalam 2 tahun?","article":"Pasal 55","weight":5,"score_weight":5}]',
                'updated_at' => now()
            ]
        );

        DB::table('regulation_frameworks')->updateOrInsert(
            ['code' => 'gdpr'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'General Data Protection Regulation (GDPR)',
                'country' => 'European Union',
                'is_active' => true,
                'articles' => '[
                    {"id":"GDPR-PR-01", "topic":"Prinsip Pemrosesan", "question":"Apakah data pribadi diproses secara sah, adil, dan transparan (Lawfulness & Transparency)?", "article":"Pasal 5 & 6", "score_weight":5},
                    {"id":"GDPR-PR-02", "topic":"Prinsip Pemrosesan", "question":"Apakah pengumpulan data diminimalkan, akurat, dan dibatasi masa penyimpanannya?", "article":"Pasal 7-9", "score_weight":5},
                    {"id":"GDPR-PR-03", "topic":"Dasar Hukum", "question":"Apakah organisasi selalu memperoleh persetujuan (consent) yang sah secara ekplisit tertulis atau berdasar pada mekanisme sah lainnya?", "article":"Pasal 12", "score_weight":5},
                    {"id":"GDPR-HS-01", "topic":"Hak Subjek Data", "question":"Apakah tersedia mekanisme bagi subjek data untuk meminta hak akses, perbaikan, dan penghapusan data (Right to be Forgotten)?", "article":"Pasal 15-17", "score_weight":5},
                    {"id":"GDPR-HS-02", "topic":"Hak Subjek Data", "question":"Apakah organisasi mengakomodasi hak portabilitas data, pembatasan pemrosesan, dan penolakan otomatisasi (Automated Decision)?", "article":"Pasal 18-21", "score_weight":4},
                    {"id":"GDPR-TK-01", "topic":"Tata Kelola & DPO", "question":"Apakah organisasi memiliki kebijakan Akuntabilitas dan Data Protection by Design and by Default?", "article":"Pasal 11 & 22", "score_weight":5},
                    {"id":"GDPR-TK-02", "topic":"Tata Kelola & DPO", "question":"Apakah organisasi telah secara formal menunjuk seorang Data Protection Officer (DPO) yang independen?", "article":"Pasal 30-31", "score_weight":5},
                    {"id":"GDPR-RA-01", "topic":"DPIA & ROPA", "question":"Apakah organisasi memelihara dokumen Rekam Aktivitas Pemrosesan (Records of Processing / ROPA)?", "article":"Pasal 25", "score_weight":5},
                    {"id":"GDPR-RA-02", "topic":"DPIA & ROPA", "question":"Apakah Data Protection Impact Assessment (DPIA) wajib dilakukan sebelum operasional pemrosesan bersiko tinggi?", "article":"Pasal 29", "score_weight":5},
                    {"id":"GDPR-KM-01", "topic":"Keamanan & Insiden", "question":"Apakah sistem menjamin integritas, kerahasiaan, dan keamanan pemrosesan berstandar tinggi (enkripsi, anonimisasi)?", "article":"Pasal 10 & 26", "score_weight":5},
                    {"id":"GDPR-KM-02", "topic":"Keamanan & Insiden", "question":"Apakah terdapat prosedur insiden untuk memberitahukan otoritas regulasi maksimal 72 jam jika terjadi kebocoran (Breach Notification)?", "article":"Pasal 27-28", "score_weight":5},
                    {"id":"GDPR-TP-01", "topic":"Pihak Ketiga & Transfer", "question":"Apakah seluruh pihak ketiga (Processors/Vendor) telah terikat kontrak tertulis standar pelindungan (Data Processor Agreement)?", "article":"Pasal 24", "score_weight":5},
                    {"id":"GDPR-TP-02", "topic":"Pihak Ketiga & Transfer", "question":"Apakah transfer data keluar wilayah Uni Eropa dilindungi instrumen kuat seperti SCC (Standard Contractual Clauses) atau BCRs?", "article":"Pasal 32-34", "score_weight":4},
                    {"id":"GDPR-SP-01", "topic":"Data Khusus", "question":"Apakah ada perlindungan spesifik guna mengelola kategori data sensitif khusus serta perizinan pemrosesan anak-anak?", "article":"Pasal 13-14", "score_weight":4}
                ]',
                'updated_at' => now()
            ]
        );

        DB::table('regulation_frameworks')->updateOrInsert(
            ['code' => 'pdpa'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'Personal Data Protection Act (PDPA)',
                'country' => 'Singapore',
                'is_active' => true,
                'articles' => '[
                    {"id":"PDPA-PR-01", "topic":"Consent & Transparansi", "question":"Apakah persetujuan yang sah selalu didapatkan serta wajib diinformasikan tujuannya (Notification Obligation)?", "article":"Bagian 10, 11 & 17", "score_weight":5},
                    {"id":"PDPA-PR-02", "topic":"Tujuan & Akurasi", "question":"Apakah data pribadi hanya dikumpulkan dalam batas kewajaran relevansi dan dijaga tingkat akurasinya?", "article":"Bagian 12 & 13", "score_weight":5},
                    {"id":"PDPA-HS-01", "topic":"Hak Subjek Data", "question":"Apakah subjek data difasilitasi guna mengakses, mengoreksi, dan menarik kembali persetujuan (Withdrawal) mereka setiap saat?", "article":"Bagian 20-22", "score_weight":5},
                    {"id":"PDPA-TK-01", "topic":"Tata Kelola & Akuntabilitas", "question":"Apakah organisasi memegang prinsip akuntabilitas kebijakan, dan menunjuk setidaknya satu DPO spesifik perwakilan di Singapura?", "article":"Bagian 16 & 35", "score_weight":5},
                    {"id":"PDPA-KM-01", "topic":"Keamanan & Retensi", "question":"Apakah ada pengamanan teknis dan organisasi yang memadai mencegah akses ilegal serta perlindungan kebocoran (Protection Obligation)?", "article":"Bagian 15, 26, 31", "score_weight":5},
                    {"id":"PDPA-KM-02", "topic":"Keamanan & Retensi", "question":"Apakah organisasi memusnahkan dan membatasi penyimpanan apabila tujuan pemrosesan data telah usai (Retention Limitation)?", "article":"Bagian 14", "score_weight":5},
                    {"id":"PDPA-IN-01", "topic":"Manajemen Insiden", "question":"Apakah SOP dan tim telah siap melaporkan kebocoran secara proaktif (Notifiable Data Breaches) ke komisi (PDPC)?", "article":"Bagian 32-33", "score_weight":5},
                    {"id":"PDPA-TP-01", "topic":"Transfer Lintas Batas", "question":"Apakah transfer data ke luar area Singapura dipastikan mendapat ikatan standar hukum minimum (Transfer Limitation Obligation)?", "article":"Bagian 37-38", "score_weight":5},
                    {"id":"PDPA-RA-01", "topic":"Asesmen Risiko (DPIA)", "question":"Apakah organisasi telah melangsungkan DPIA dan pemeriksaan risiko terhadap skenario pengolahan datanya?", "article":"Bagian 34", "score_weight":4}
                ]',
                'updated_at' => now()
            ]
        );
    }
}
