<?php

namespace Database\Seeders;

use App\Models\KnowledgeBaseSection;
use Illuminate\Database\Seeder;

/**
 * Comprehensive Knowledge Base Seeder
 * -------------------------------------------------------------------
 * Grounding data untuk semua fitur AI di Privasimu Nexus. Dipakai oleh
 * KnowledgeBaseSection::findRelevant() via keyword-based RAG.
 *
 * Struktur tiap section:
 *   - module_key   : unique ID (feature or regulation slug)
 *   - title        : display name
 *   - summary      : 50-200 token — selalu di-inject untuk tight budget
 *   - content      : 500-2000 token — full detail, inject kalau budget allow
 *   - keywords     : comma-separated, aggressive variant coverage
 *   - feature_tags : AI feature yang butuh section ini
 *                    (ropa_autofill, dpia_autofill, contract_review,
 *                     policy_review, remediation, chat, tool_calling, dsr,
 *                     breach_response, vendor_screening, pii_scan)
 *   - category     : regulation|wizard|library|template|workflow|example
 *
 * Run:
 *   php artisan db:seed --class=KnowledgeBaseComprehensiveSeeder
 *
 * Idempotent — updateOrCreate by module_key.
 */
class KnowledgeBaseComprehensiveSeeder extends Seeder
{
    public function run(): void
    {
        $sections = array_merge(
            $this->regulatorySections(),
            $this->ropaSections(),
            $this->dpiaSections(),
            $this->gapSections(),
            $this->breachSections(),
            $this->dsrSections(),
            $this->consentSections(),
            $this->contractReviewSections(),
            $this->policyReviewSections(),
            $this->vendorSections(),
            $this->dataDiscoverySections(),
            $this->remediationSections(),
            $this->flowsAndSalesSections(),
            $this->technicalDeepDiveSections(),
        );

        $count = 0;
        foreach ($sections as $i => $section) {
            KnowledgeBaseSection::updateOrCreate(
                ['module_key' => $section['module_key']],
                array_merge($section, [
                    'sort_order' => ($section['sort_order'] ?? ($i + 100)),
                    'is_active' => true,
                ])
            );
            $count++;
        }

        $this->command->info("✅ Seeded {$count} KB sections across all AI features.");
    }

    // ======================================================================
    // 1. REGULATORY — UU PDP per-Pasal + POJK + KOMDIGI
    // ======================================================================
    private function regulatorySections(): array
    {
        return [
            [
                'module_key' => 'uu_pdp_prinsip_umum',
                'title' => 'UU PDP — Prinsip Pelindungan Data Pribadi (Pasal 16)',
                'category' => 'regulation',
                'feature_tags' => 'chat,ropa_autofill,dpia_autofill,policy_review,remediation',
                'keywords' => 'prinsip,principles,pdp,uu pdp,pasal 16,lawful,transparan,tujuan,minimisasi,akurat,retensi,keamanan,akuntabilitas',
                'summary' => 'UU PDP Pasal 16 atur 6 prinsip inti: (1) terbatas & spesifik, (2) sah & transparan, (3) akurat & mutakhir, (4) tujuan sesuai, (5) aman & rahasia, (6) akuntabel. Wajib dipenuhi semua aktivitas pemrosesan.',
                'content' => <<<'KB'
# Prinsip Pelindungan Data Pribadi (UU PDP Pasal 16)

Setiap pemrosesan data pribadi WAJIB memenuhi 6 prinsip berikut:

## 1. Terbatas dan Spesifik (Purpose Limitation)
Data dikumpulkan hanya untuk tujuan yang sah, jelas, dan secara spesifik diberitahukan kepada subjek data. Tidak boleh digunakan untuk tujuan lain tanpa persetujuan baru.

**Contoh pelanggaran**: Data nasabah diambil untuk KYC, tapi dipakai untuk marketing tanpa consent tambahan.

## 2. Sah dan Transparan (Lawfulness & Transparency)
Harus berdasarkan salah satu dari 6 dasar hukum (Pasal 20). Subjek data diberitahu secara terbuka tentang siapa, mengapa, dan bagaimana datanya diproses.

## 3. Akurat dan Mutakhir (Accuracy)
Data harus benar, up-to-date, dan ada mekanisme koreksi untuk subjek data (hak koreksi — Pasal 6).

## 4. Tujuan Sesuai (Data Minimization)
Hanya data yang benar-benar diperlukan yang boleh dikumpulkan. Over-collection = pelanggaran.

**Contoh**: Formulir pendaftaran event tidak boleh minta NIK, NPWP, golongan darah kalau tidak relevan dengan event.

## 5. Aman dan Rahasia (Integrity & Confidentiality)
Ada langkah teknis (enkripsi, access control, backup) dan organisasional (policy, training, audit) untuk lindungi data.

## 6. Akuntabel (Accountability)
Pengendali Data wajib bisa **membuktikan** kepatuhan terhadap 5 prinsip di atas. Ini kenapa RoPA, DPIA, Audit Log mandatory.

## Dampak Pelanggaran
- Administratif: teguran, denda hingga 2% omzet tahunan
- Pidana: penjara 4-6 tahun + denda
- Reputasional: subjek bisa publikasikan + media coverage

## Kaitan ke Fitur Privasimu
- **RoPA**: bukti akuntabilitas (Prinsip 6) untuk tujuan (Prinsip 1) + dasar hukum (Prinsip 2)
- **DPIA**: demonstrasi tujuan minimal (Prinsip 4) + keamanan (Prinsip 5)
- **Consent Management**: implementasi transparansi (Prinsip 2)
- **Audit Log**: bukti akuntabilitas (Prinsip 6)
KB,
            ],

            [
                'module_key' => 'uu_pdp_hak_subjek',
                'title' => 'UU PDP — Hak Subjek Data (Pasal 5–10)',
                'category' => 'regulation',
                'feature_tags' => 'chat,dsr,policy_review,remediation',
                'keywords' => 'hak subjek,data subject rights,pasal 5,pasal 6,pasal 7,pasal 8,pasal 9,pasal 10,akses,koreksi,hapus,portabilitas,objection,withdraw,menolak,penarikan,persetujuan',
                'summary' => 'Pasal 5-10 UU PDP: subjek data berhak (1) akses data, (2) koreksi/perbaikan, (3) penghapusan, (4) penarikan consent, (5) keberatan (objection), (6) portabilitas, (7) info processing. Pengendali WAJIB respons dalam 72 jam.',
                'content' => <<<'KB'
# Hak-hak Subjek Data (UU PDP Pasal 5-10)

## Pasal 5 — Hak Akses (Access)
Subjek data berhak mengetahui dan memperoleh salinan data pribadi miliknya yang sedang diproses.

## Pasal 6 — Hak Koreksi (Rectification)
Subjek berhak melengkapi, memperbarui, dan/atau memperbaiki kesalahan data pribadinya.

## Pasal 7 — Hak Penghapusan (Erasure / Right to be Forgotten)
Subjek berhak mengakhiri pemrosesan, menghapus, dan/atau memusnahkan data pribadi miliknya sesuai ketentuan peraturan perundang-undangan.

**Catatan**: Hak hapus tidak absolut — bisa ditolak kalau ada kewajiban hukum lain (mis. Pasal 65 UU 13/2003 tentang retensi data kepegawaian 5 tahun).

## Pasal 8 — Hak Penarikan Persetujuan (Withdraw Consent)
Subjek berhak menarik persetujuan yang telah diberikan kapan saja. Penarikan **tidak retroaktif** — pemrosesan sebelum penarikan tetap sah.

## Pasal 9 — Hak Mengajukan Keberatan (Object)
Subjek berhak mengajukan keberatan atas tindakan pengambilan keputusan yang semata-mata didasarkan pada pemrosesan otomatis (mis. profiling kredit AI, automated hiring).

## Pasal 10 — Hak Atas Informasi (Right to Information)
Subjek berhak memperoleh informasi tentang kejelasan identitas, tujuan, kategori, rentang waktu, dan sumber data.

## Deadline Response — 3x24 jam (72 jam)
Pasal 32 UU PDP mengatur: permintaan hak harus ditindaklanjuti dalam **3x24 jam (72 jam kerja)**.

## Tipe Request di Privasimu DSR Module
1. **Access** — berdasar Pasal 5
2. **Correction** — Pasal 6
3. **Deletion** — Pasal 7
4. **Portability** — Pasal 7 ayat (3)
5. **Withdraw Consent** — Pasal 8
6. **Objection** — Pasal 9
7. **Information Request** — Pasal 10

## Privasimu DSR Workflow
1. Subjek submit request via embed form / email / portal
2. Identity verification via OTP email
3. DPO review + approve scope
4. Scope picker → pilih Information System yang terdampak
5. SQL Generator keluarkan SELECT/UPDATE/DELETE
6. Admin eksekusi di platform klien sendiri (generate-only mode)
7. Upload evidence + mark completed
8. Auto PDF Completion Certificate signed by DPO
9. Notify subjek via email/WhatsApp
KB,
            ],

            [
                'module_key' => 'uu_pdp_pasal_20_dasar_pemrosesan',
                'title' => 'UU PDP — Dasar Pemrosesan Data (Pasal 20)',
                'category' => 'regulation',
                'feature_tags' => 'ropa_autofill,policy_review,chat,vendor_screening',
                'keywords' => 'dasar hukum,legal basis,pasal 20,persetujuan,kontrak,kewajiban hukum,kepentingan vital,tugas publik,kepentingan sah,legitimate interest,lawful basis',
                'summary' => 'Pasal 20 UU PDP: 6 dasar hukum pemrosesan data. (1) Persetujuan, (2) Pemenuhan kontrak, (3) Kewajiban hukum, (4) Kepentingan vital subjek, (5) Tugas publik, (6) Kepentingan sah (legitimate interest). Wajib pilih 1 untuk setiap aktivitas RoPA.',
                'content' => <<<'KB'
# Dasar Pemrosesan Data (UU PDP Pasal 20)

Setiap aktivitas pemrosesan WAJIB punya SATU dari 6 dasar hukum berikut:

## 1. Persetujuan Subjek Data (Consent) — Pasal 20(1)(a)
Subjek memberikan persetujuan eksplisit untuk pemrosesan dengan tujuan spesifik.

**Kapan dipakai:**
- Marketing email / push notification
- Cookie non-essential di website
- Data biometrik opsional (misal fitur premium)
- Sharing data ke 3rd party marketing

**Syarat:**
- Bebas (tidak dipaksa)
- Spesifik (per-tujuan)
- Informed (subjek tahu konsekuensi)
- Unambiguous (clear action, bukan pre-checked)

**Contoh pelanggaran**: Pre-checked consent box, bundling consent dengan syarat layanan.

## 2. Pemenuhan Kontrak — Pasal 20(1)(b)
Pemrosesan diperlukan untuk melaksanakan kontrak dengan subjek, atau mengambil tindakan pra-kontraktual atas permintaan subjek.

**Kapan dipakai:**
- Penggajian karyawan
- Pengiriman barang e-commerce
- Penyediaan layanan banking (transaksi, tagihan)
- Onboarding nasabah

**Catatan**: Batasi ke data yang benar-benar needed untuk kontrak — sisanya butuh basis lain.

## 3. Pemenuhan Kewajiban Hukum — Pasal 20(1)(c)
Pemrosesan diperlukan untuk memenuhi kewajiban hukum Pengendali Data.

**Kapan dipakai:**
- KYC untuk bank (UU Perbankan, POJK 12/2018)
- Pelaporan pajak karyawan (UU Pajak Penghasilan)
- Retensi rekam medis (UU 29/2004 Praktik Kedokteran)
- Compliance lapor transaksi mencurigakan (PPATK)

## 4. Pelindungan Kepentingan Vital — Pasal 20(1)(d)
Pemrosesan diperlukan untuk melindungi kepentingan vital subjek atau orang lain (hidup/mati).

**Kapan dipakai:**
- Emergency medical treatment tanpa consent sadar
- Lokasi pelacakan saat kecelakaan
- Disaster response

**Jarang dipakai di bisnis normal** — mostly medis/darurat.

## 5. Pelaksanaan Tugas Publik — Pasal 20(1)(e)
Untuk instansi pemerintah atau entitas menjalankan tugas publik berdasarkan UU.

**Kapan dipakai:**
- Kementerian olah data penduduk
- BUMN olah data customer berdasarkan kewenangan
- Lembaga penegakan hukum

## 6. Kepentingan Sah (Legitimate Interest) — Pasal 20(1)(f)
Pemrosesan diperlukan untuk kepentingan sah Pengendali atau pihak lain, asal tidak mengalahkan hak subjek data.

**Kapan dipakai:**
- Fraud detection
- Keamanan jaringan (SIEM log)
- Analytics internal (bukan marketing ke pihak 3)
- Direct marketing ke existing customer (soft opt-in)

**WAJIB lakukan LIA (Legitimate Interest Assessment) — 3 step balancing test.**

## Decision Tree — Pilih Dasar yang Tepat

```
Apakah subjek bisa decline tanpa kehilangan layanan?
├── YES → Kepentingan Sah atau Persetujuan (prefer KS kalau bisa)
└── NO  → Kontrak / Kewajiban Hukum / Kepentingan Vital
           └── Ada UU spesifik yang mewajibkan?
               ├── YES → Kewajiban Hukum
               └── NO  → Pemenuhan Kontrak
```

## Common Mistake
- Jangan pakai "Persetujuan" untuk pemrosesan yang sebenernya kontrak (e.g., data penggajian karyawan)
- Jangan pakai "Legitimate Interest" untuk marketing ke prospek baru (itu butuh Consent)
- Jangan pakai "Kewajiban Hukum" tanpa cite Pasal UU spesifik
KB,
            ],

            [
                'module_key' => 'uu_pdp_pasal_31_ropa',
                'title' => 'UU PDP — Kewajiban RoPA (Pasal 31)',
                'category' => 'regulation',
                'feature_tags' => 'ropa_autofill,chat,remediation,policy_review',
                'keywords' => 'pasal 31,ropa,record processing,catatan pemrosesan,registry,wajib,accountability,kewajiban pengendali',
                'summary' => 'Pasal 31 UU PDP mewajibkan Pengendali Data menyelenggarakan catatan (RoPA) atas setiap aktivitas pemrosesan data pribadi. Wajib berisi: nama+kontak pengendali, tujuan, kategori subjek+data, penerima, transfer, retensi, keamanan.',
                'content' => <<<'KB'
# Kewajiban RoPA — UU PDP Pasal 31

## Teks Pasal
> "Pengendali Data Pribadi wajib menyelenggarakan catatan Pemrosesan Data Pribadi."

## Kenapa Wajib?
Bukti akuntabilitas (Prinsip 6, Pasal 16). Tanpa RoPA, pengendali tidak bisa buktikan kepatuhan saat audit/pemeriksaan KOMDIGI.

## Isi Minimal RoPA (Penjelasan Pasal 31)
1. Nama dan kontak Pengendali Data Pribadi
2. Nama dan kontak Data Protection Officer (jika wajib — Pasal 53)
3. Tujuan pemrosesan
4. Kategori subjek data (e.g. karyawan, nasabah, pelamar)
5. Kategori data pribadi (e.g. identitas, kontak, finansial, biometrik)
6. Kategori penerima data (vendor, 3rd party, afiliasi)
7. Transfer lintas batas (kalau ada) — negara + safeguards
8. Jangka waktu retensi
9. Deskripsi umum langkah keamanan teknis + organisasional

## Format Privasimu — 7 Section Wizard
Privasimu RoPA wizard aligns dengan isi minimal + format KOMDIGI:

1. **Detail Pemrosesan** — nama aktivitas, divisi, unit kerja, entitas, deskripsi
2. **DPO / PIC Team** — penanggung jawab DPO + PIC operasional
3. **Informasi Pemrosesan** — tujuan, dasar hukum, kategori subjek
4. **Pengumpulan Data** — kategori data, sumber, jumlah subjek
5. **Penggunaan & Penyimpanan** — sistem informasi, akses, lokasi server
6. **Pengiriman Data** — penerima, transfer lintas batas, safeguards
7. **Retensi & Keamanan** — durasi, trigger pemusnahan, kontrol keamanan

## Siapa yang Wajib Punya RoPA?
Semua **Pengendali Data** yang memproses data pribadi WNI. Tidak ada pengecualian berdasar ukuran — UMKM juga wajib, hanya format bisa lebih sederhana.

## Update RoPA — Kapan?
- Aktivitas pemrosesan baru → buat RoPA baru
- Perubahan tujuan, kategori data, penerima → update + version diff
- Audit periodic minimal setahun sekali
- Setelah breach → review apakah RoPA miss something

## Sanksi Tidak Punya RoPA
- Administratif: peringatan tertulis, denda hingga 2% omzet (Pasal 57)
- Reputasional: fail audit ISO 27701, POJK, KOMDIGI
- Operational: susah response DSR karena tidak tahu data tersimpan dimana

## Privasimu RoPA — Fitur Utama
- **AI Auto-Fill 7 section** dari deskripsi aktivitas
- **Multi-DPO/PIC/System** — 1 RoPA banyak pejabat + sistem
- **Risk Level otomatis** dari 8 trigger wizard (data sensitif, transfer, profiling, dll)
- **Auto-trigger DPIA** kalau risk HIGH
- **Export PDF + DOCX** format KOMDIGI
- **Version Diff Viewer** untuk audit perubahan
KB,
            ],

            [
                'module_key' => 'uu_pdp_pasal_32_dsr_sla',
                'title' => 'UU PDP — Kewajiban Respons DSR 72 Jam (Pasal 32)',
                'category' => 'regulation',
                'feature_tags' => 'dsr,chat,remediation',
                'keywords' => 'pasal 32,72 jam,dsr deadline,sla,response time,3x24 jam,batas waktu,hak subjek,permintaan,data subject request',
                'summary' => 'Pasal 32 UU PDP: Pengendali WAJIB menindaklanjuti permintaan hak subjek dalam 3x24 jam (72 jam kerja). Tidak respons = pelanggaran + sanksi. Privasimu DSR module auto-countdown + alert DPO.',
                'content' => <<<'KB'
# SLA 72 Jam Hak Subjek Data — UU PDP Pasal 32

## Teks Pasal
> "Pengendali Data Pribadi wajib menindaklanjuti permintaan Subjek Data Pribadi paling lambat 3 x 24 jam (tiga kali dua puluh empat jam) sejak permintaan Subjek Data Pribadi diterima."

## Interpretasi
- **72 jam dari permintaan diterima** (bukan dari permintaan dibaca DPO)
- "Ditindaklanjuti" = at minimum acknowledge + set SLA expectation, bukan wajib selesai
- Kalau proses > 72 jam, MUST kirim interim response jelaskan alasan + ETA

## Tipe Permintaan (lihat Pasal 5-10)
1. Access — copy data
2. Correction
3. Deletion
4. Portability
5. Withdraw Consent
6. Objection
7. Information

## Privasimu DSR Workflow + Timer
```
T+0h     Permintaan diterima via email/embed/portal → auto-create DSR record
         → deadline_at = now + 72h
T+1h     Identity verification OTP dikirim
T+6h     DPO dapat notifikasi (in-app + email)
T+24h    Alert #1 kalau belum di-assign
T+48h    Alert #2 escalate ke DPO senior
T+60h    Critical alert ke Direksi
T+72h    DEADLINE — pelanggaran kalau belum respons
```

## Cara Hitung Hari Kerja
UU PDP tidak eksplisit apakah "72 jam" = kalender atau kerja. **Best practice konservatif: kalender 72 jam** — respons subjek data tidak boleh terhambat hari libur.

## Apa yang Bisa Di-Ekskalasi (>72h)?
Pasal 32(2): jangka waktu dapat diperpanjang paling lama 14 hari kerja jika:
- Permintaan kompleks (multi-sistem, data discovery butuh waktu)
- Permintaan banyak sekaligus dari 1 subjek

**WAJIB notify subjek tentang perpanjangan SEBELUM 72 jam habis**.

## Privasimu Fitur yang Memitigasi
- **Auto countdown**: visible di dashboard DPO
- **Multi-level alert**: in-app + email + Telegram + WhatsApp deep-link
- **Auto-escalation**: kalau DPO primary tidak respons, forward ke backup
- **Extension flow**: kalau butuh perpanjang, auto-generate email notif subjek + log alasan di audit trail
- **Completion Certificate**: auto-sign PDF + timestamp saat selesai — bukti SLA

## Sanksi Tidak Respons SLA
- Teguran tertulis (Pasal 57)
- Denda administratif sampai 2% omzet tahunan
- Pidana Pasal 67 (bocor data karena lalai): 4-6 tahun + denda

## Common Mistakes
- Hitung dari "baca email" bukan "email diterima" — SALAH
- Libur tidak dihitung — SALAH (kecuali ada kebijakan internal yang jelas dan diberitahu ke subjek)
- "Kami sedang proses" tanpa kasih ETA — sufficient saat T+24h, TIDAK sufficient saat T+71h
- Ignore permintaan yang "tidak valid" tanpa reply — wajib reply, sekalipun menolak
KB,
            ],

            [
                'module_key' => 'uu_pdp_pasal_34_dpia',
                'title' => 'UU PDP — Kewajiban DPIA (Pasal 34)',
                'category' => 'regulation',
                'feature_tags' => 'dpia_autofill,ropa_autofill,chat',
                'keywords' => 'pasal 34,dpia,data protection impact assessment,penilaian dampak,risk,tinggi,high risk,profiling,biometrik,skala besar,sensitif',
                'summary' => 'Pasal 34 UU PDP: DPIA WAJIB dilakukan sebelum pemrosesan berisiko tinggi — data sensitif, otomatisasi pengambilan keputusan, pemantauan sistematis skala besar, atau teknologi baru. RoPA risk=HIGH auto-trigger DPIA di Privasimu.',
                'content' => <<<'KB'
# Kewajiban DPIA — UU PDP Pasal 34

## Teks Pasal
> "Pengendali Data Pribadi wajib melakukan penilaian dampak Pelindungan Data Pribadi dalam hal Pemrosesan Data Pribadi memiliki potensi risiko tinggi terhadap Subjek Data Pribadi."

## Kapan DPIA WAJIB?
Pasal 34(2) menyebut kriteria "risiko tinggi" meliputi:
1. **Pengambilan keputusan otomatis** yang memberikan akibat hukum atau dampak signifikan (mis. AI scoring kredit, automated hiring)
2. **Pemrosesan data pribadi spesifik (sensitif)**:
   - Data kesehatan
   - Data biometrik
   - Data genetik
   - Data tindak pidana
   - Data anak (<18 tahun)
   - Data keuangan pribadi detail
3. **Pemantauan sistematis skala besar**: CCTV + analytics, tracking karyawan
4. **Teknologi baru** yang belum matang: facial recognition, voice cloning
5. **Transfer data ke negara tanpa adequacy** (kombinasi dengan Pasal 56)
6. **Big data subjek (>1.000)** dengan kombinasi faktor lain

## Format DPIA Privasimu — 21 Kategori Risiko
Privasimu DPIA cover 21 kategori standar UU PDP + ISO 27701:
1. Dasar Hukum Pemrosesan
2. Minimisasi Data
3. Pembatasan Penyimpanan (Retensi)
4. Integritas & Kerahasiaan
5. Transfer Lintas Batas
6. Enkripsi & Pseudonimisasi
7. Otentikasi & Akses Kontrol
8. Pencatatan Log & Audit
9. Backup & Disaster Recovery
10. Vendor / Processor Management
11. Pelatihan Karyawan
12. Incident Response
13. DPIA Review Schedule
14. Child Data Specific
15. Automated Decision-Making
16. Profiling
17. Consent Management
18. Data Subject Rights Facilitation
19. Notification & Transparency
20. Data Breach Notification
21. Cross-Border Safeguards

Tiap kategori diisi: Likelihood (1-5) × Impact (1-5) = Risk Score.

## Risk Matrix Privasimu
- Skor 1-4: Low (hijau)
- Skor 5-9: Medium (kuning)
- Skor 10-14: High (oranye)
- Skor 15-25: Critical (merah)

Mitigasi WAJIB untuk skor ≥10.

## Privasimu Auto-Trigger
RoPA dengan risk_level=HIGH **auto-create draft DPIA** dengan inherited wizard_data dari RoPA. Tim DPO tinggal lengkapi 21 kategori risk event.

## DPIA Output
- Risk Matrix 5×5 visual (color-coded PDF)
- Daftar Risk Event per kategori + mitigasi
- Residual Risk Score (setelah mitigasi)
- Rekomendasi: lanjut / ubah / hentikan pemrosesan
- Tanda tangan DPO + Direksi

## Siapa Review DPIA?
1. Draft by DPO/Compliance
2. Review by technical team (verify mitigation feasible)
3. Approval by Direksi (karena bisa impact bisnis decision)
4. Kalau residual risk masih HIGH → konsultasi ke KOMDIGI
KB,
            ],

            [
                'module_key' => 'uu_pdp_pasal_46_breach',
                'title' => 'UU PDP — Notifikasi Kebocoran Data (Pasal 46)',
                'category' => 'regulation',
                'feature_tags' => 'breach_response,chat,remediation',
                'keywords' => 'pasal 46,breach,kebocoran,notifikasi,72 jam,komdigi,lembaga,subjek,pelanggaran,insiden,ransomware,phishing',
                'summary' => 'Pasal 46 UU PDP: kebocoran data WAJIB diberitahukan ke Lembaga PDP (KOMDIGI) + subjek terdampak paling lama 3x24 jam sejak diketahui. Notifikasi ke subjek bisa collective kalau >100 orang.',
                'content' => <<<'KB'
# Notifikasi Kebocoran Data — UU PDP Pasal 46

## Teks Pasal
> "Pengendali Data Pribadi wajib memberitahukan secara tertulis paling lambat 3 x 24 jam kepada Subjek Data Pribadi dan Lembaga pelaksana UU PDP, terjadinya kegagalan pelindungan Data Pribadi."

## Yang Dianggap "Kebocoran" (Pasal 46(2))
1. Data diakses pihak tidak berwenang
2. Data hilang
3. Data rusak / tidak dapat digunakan
4. Data diungkap tanpa wewenang

## Isi Minimum Notifikasi (Pasal 46(3))
1. Data Pribadi yang terungkap
2. Kapan dan bagaimana Data Pribadi terungkap
3. Upaya penanggulangan dan pemulihan oleh Pengendali

## Deadline — 3x24 jam (72 jam)
Dari **saat diketahui** (bukan dari saat terjadi). Timer mulai saat pertama kali tim IT/SOC menyadari ada insiden.

## Lembaga Pelaksana
Saat ini: **KOMDIGI (Kementerian Komunikasi dan Digital)**, cq. Ditjen Aplikasi Informatika.

## Format Notifikasi KOMDIGI
Wajib tertulis, via:
- Email resmi ke `pdp@komdigi.go.id` (atau channel resmi saat itu)
- Surat fisik via pos (backup)
- Template disediakan Privasimu: "Surat Pemberitahuan Kebocoran Data ke KOMDIGI"

## Format Notifikasi Subjek
- Email individual (kalau data kontak ada)
- SMS / push (backup)
- Surat fisik (kalau email tidak tersedia)
- **Pemberitahuan umum via media** kalau > 100 subjek affected DAN tidak efisien notify individually

## Privasimu Breach Module Fitur
1. **15 SOP Containment Templates** — playbook per jenis insiden (Ransomware, Phishing, Insider, DDoS, dll)
2. **Containment Checklist adaptif** per kategori (isolation/forensics/legal/comm/remediation)
3. **RACI Matrix editable per-breach** — siapa Responsible/Accountable/Consulted/Informed per step
4. **72-jam KOMDIGI countdown** auto-escalation
5. **Multi-RoPA linkage** — 1 breach bisa affect banyak RoPA
6. **Auto-generate PDF pack**:
   - Surat Notifikasi ke KOMDIGI
   - Surat Himbauan ke Subjek Terdampak (template anti-churn)
   - Full Breach Report dengan timeline + RACI + RCA + remediation
7. **Telegram + WhatsApp alert** real-time ke channel DPO
8. **Subject notification template**: jangan reveal teknis penyebab (bikin panic + churn)

## Anti-Churn Notification Template
Komunikasi ke subjek harus:
- Faktual tapi tidak panic-inducing
- Assure langkah remediation sudah/sedang dilakukan
- Kasih kontak bantuan (hotline, email DPO)
- TIDAK reveal root cause teknis (misal "password admin lemah")
- Fokus ke langkah protektif yang subjek bisa lakukan (ganti password, monitor transaksi)

## Sanksi Tidak Notify
- Pasal 67: 4-6 tahun penjara + denda miliar
- Denda administratif 2% omzet (Pasal 57)
- Potensi class action subjek (Pasal 65 hak gugat)
KB,
            ],

            [
                'module_key' => 'uu_pdp_pasal_53_dpo',
                'title' => 'UU PDP — Kewajiban DPO (Pasal 53)',
                'category' => 'regulation',
                'feature_tags' => 'chat,remediation,policy_review',
                'keywords' => 'pasal 53,dpo,data protection officer,pejabat,penunjukan,wajib,kriteria,tugas,independen',
                'summary' => 'Pasal 53 UU PDP: DPO wajib ditunjuk kalau pengendali (a) instansi publik, (b) pemrosesan skala besar, atau (c) data sensitif. DPO bertugas advisory, monitor kepatuhan, POC ke KOMDIGI + subjek data.',
                'content' => <<<'KB'
# Data Protection Officer — UU PDP Pasal 53

## Kapan DPO WAJIB?
Pengendali Data WAJIB tunjuk DPO jika:
1. **Instansi publik / pemerintah** (semua ukuran)
2. Pemrosesan data dalam **skala besar** (benchmark: >10.000 subjek data atau kontinyu)
3. Pemrosesan data **spesifik (sensitif)** sebagai core activity

## Tugas DPO (Pasal 53(3))
1. Memberikan saran (advisory) tentang kepatuhan UU PDP
2. Memantau + memastikan kepatuhan pemrosesan
3. Menjadi **point-of-contact (POC)** untuk:
   - Subjek data (untuk DSR)
   - Lembaga pelaksana UU PDP (KOMDIGI)
4. Mengoordinasikan pelaksanaan DPIA
5. Training + awareness staff
6. Lead incident response saat breach

## Kriteria DPO (Best Practice)
- Pengetahuan UU PDP, GDPR, POJK (untuk financial)
- Latar belakang legal atau ITSec/compliance
- **Independen** — tidak conflict of interest (tidak merangkap Direktur IT/Marketing/Legal yang decision-maker)
- Bisa komunikasi langsung ke top management
- Bilingual (Indonesia + English untuk regulator asing)

## DPO Bisa Internal atau Eksternal
- **Internal**: karyawan full-time, dedicated role
- **Eksternal**: konsultan atau DPaaS (DPO-as-a-Service) — untuk UMKM atau startup

## Penunjukan DPO
- Formal via SK Direksi
- Nama + kontak DPO dipublikasikan (website, privacy notice)
- Didaftarkan ke KOMDIGI (via portal pelaporan)

## Common Mistakes
- Rangkap jabatan DPO + Legal Director → conflict of interest
- DPO pakai email generic (dpo@company.com) tanpa assigned person → gagal respons DSR
- DPO cuma paper title, tidak dikasih budget/authority → tidak efektif

## Privasimu Mendukung Peran DPO
- Dashboard compliance real-time
- Auto-alert saat ada DSR incoming
- Breach countdown + response wizard
- Audit log untuk semua action
- Export report untuk rapat Direksi
- DPO seat terpisah di role hierarchy + notification channel
KB,
            ],

            [
                'module_key' => 'uu_pdp_pasal_56_cross_border',
                'title' => 'UU PDP — Transfer Data Lintas Batas (Pasal 56)',
                'category' => 'regulation',
                'feature_tags' => 'ropa_autofill,chat,policy_review,vendor_screening',
                'keywords' => 'pasal 56,transfer lintas batas,cross border,luar negeri,scc,bcr,adequacy,negara,konsen,pelindungan,overseas',
                'summary' => 'Pasal 56 UU PDP: transfer data keluar Indonesia butuh (a) adequacy dari KOMDIGI, (b) safeguards (SCC/BCR), atau (c) consent eksplisit subjek. Wajib ada perjanjian + dokumentasi.',
                'content' => <<<'KB'
# Transfer Data Lintas Batas — UU PDP Pasal 56

## Ketentuan Dasar
Pengendali dapat transfer data Pribadi ke luar wilayah hukum Indonesia dengan syarat (salah satu):
1. **Negara tujuan punya tingkat perlindungan setara atau lebih tinggi** dari Indonesia (adequacy assessment oleh KOMDIGI)
2. **Adanya persyaratan atau perjanjian** yang menjamin perlindungan — Standard Contractual Clauses (SCC), Binding Corporate Rules (BCR), sertifikasi internasional
3. **Persetujuan eksplisit** dari Subjek Data setelah diberitahu risiko

## Adequacy Status (Perkiraan 2026)
Belum ada daftar resmi adequacy KOMDIGI per April 2026. Best practice: asumsikan **belum ada negara adequacy** — gunakan safeguards (SCC/BCR).

## Safeguards Options
### A. Standard Contractual Clauses (SCC)
- Template kontrak antar Pengendali ↔ Processor lintas negara
- Berisi klausul: tujuan, keamanan, hak subjek, audit, breach notification
- Wajib disetujui kedua pihak + signed
- Privasimu sediakan template SCC di `/document-templates`

### B. Binding Corporate Rules (BCR)
- Untuk group multinasional — internal policy yang bindings ke semua entity
- Butuh approval KOMDIGI (atau setara di yurisdiksi lain)
- Valid across all subsidiaries

### C. Certification / Code of Conduct
- APEC CBPR (Cross-Border Privacy Rules)
- ISO 27701 certification
- Industry code (mis. cloud provider certifications)

### D. Explicit Consent (Last Resort)
- Subjek eksplisit setuju setelah diberitahu risiko
- **Hati-hati**: consent bisa ditarik (Pasal 8), jadi fallback basis-nya rapuh

## Contoh Transfer Scenarios
| Skenario | Basis Recommended |
|---|---|
| HR outsource ke Singapore | SCC + DPA kontrak |
| Cloud hosting AWS US | SCC + AWS DPA |
| Group company di US | BCR (kalau sudah punya) atau SCC |
| SaaS analytics (Google Analytics) | Consent eksplisit + SCC + anonymization |
| Partner marketing di UAE | Consent eksplisit OR hentikan (best) |

## Dokumentasi Wajib
Setiap transfer harus tercatat di:
1. **RoPA Section 6 (Pengiriman Data)** — list penerima, negara, safeguards
2. **TIA (Transfer Impact Assessment)** — analisis risiko spesifik negara tujuan
3. **DPA (Data Processing Agreement)** — kontrak dengan penerima
4. **Registry Cross-Border Transfer** di Privasimu

## Country Risk Scoring
Privasimu built-in scoring berdasarkan:
- Adequacy dengan Indonesia (kalau sudah dinyatakan)
- Ada/tidaknya privacy law serupa GDPR
- Track record enforcement + keamanan
- Government access regime (US CLOUD Act, China cyber law, dll)

## Common Mistakes
- Asumsikan "subsidiary = 1 entity" → salah, transfer antar country masih bound Pasal 56
- Anggap SCC cukup untuk semua use case → beberapa negara restrictions lebih tinggi
- Transfer ke US via cloud tanpa SCC → pelanggaran
- Transfer ke vendor kecil tanpa DPA → pelanggaran
KB,
            ],
        ];
    }

    // ======================================================================
    // 2. RoPA — Wizard Section Detail + Library
    // ======================================================================
    private function ropaSections(): array
    {
        return [
            [
                'module_key' => 'ropa_wizard_overview',
                'title' => 'RoPA Wizard — 7 Step Overview',
                'category' => 'wizard',
                'feature_tags' => 'ropa_autofill,chat',
                'keywords' => 'ropa wizard,7 step,section,pemrosesan,urutan,langkah,format komdigi',
                'summary' => 'Privasimu RoPA wizard 7 step sinkron format KOMDIGI: (1) Detail Pemrosesan, (2) DPO/PIC, (3) Informasi Pemrosesan, (4) Pengumpulan Data, (5) Penggunaan & Penyimpanan, (6) Pengiriman Data, (7) Retensi & Keamanan.',
                'content' => <<<'KB'
# RoPA Wizard 7-Step — Privasimu

## Section 1: Detail Pemrosesan
**Tujuan**: identifikasi aktivitas. Input: nama, divisi, unit kerja, entitas, deskripsi singkat, kategori pemrosesan.

## Section 2: DPO / PIC Team
**Tujuan**: tentukan penanggung jawab. Multi-DPO + multi-PIC bisa per 1 RoPA. Data: nama, email, jabatan, phone.

## Section 3: Informasi Pemrosesan
**Tujuan**: tujuan + dasar hukum. Pilih dari 6 legal basis Pasal 20. Kategori subjek: karyawan, nasabah, pelamar, customer, dll.

## Section 4: Pengumpulan Data
**Tujuan**: data apa, dari mana, berapa banyak. **Section paling sensitif** — trigger auto risk HIGH kalau data sensitif terdeteksi (biometrik, kesehatan, anak, dll). Kategori data: 15 jenis Indonesian PII.

## Section 5: Penggunaan & Penyimpanan
**Tujuan**: sistem apa yang simpan, lokasi server, akses. Multi-system support — 1 RoPA bisa refer banyak Information System.

## Section 6: Pengiriman Data
**Tujuan**: siapa penerima. Internal (divisi lain), eksternal (vendor, 3rd party), transfer lintas batas (Pasal 56).

## Section 7: Retensi & Keamanan
**Tujuan**: berapa lama simpan + langkah keamanan. Retensi bisa pakai Master Retention Policy (reusable). Security measures: encryption, access control, backup, audit log.

## Auto-Risk Trigger (Wizard scan)
1. Penggunaan AI penuh untuk keputusan → HIGH
2. Otomatisasi pengambilan keputusan dampak signifikan → HIGH
3. Pemrofilan / profiling → HIGH
4. Teknologi baru belum matang → MEDIUM/HIGH
5. Subjek data > 1.000 → MEDIUM boost
6. Data spesifik (kesehatan/biometrik/anak/keuangan) → HIGH
7. Transfer lintas batas → MEDIUM boost
8. Pernah ada insiden → HIGH

Score HIGH → auto-generate draft DPIA inherited wizard_data.

## Common AI Auto-Fill Mistakes to Avoid
- **Jangan** isi "dasar hukum" dengan narasi panjang — wajib pilih dari 6 enum
- **Jangan** campur kategori data sensitif dengan umum di 1 field — separate list
- **Jangan** skip DPO/PIC — walau wizard optional, compliance mandatory
- **Jangan** isi retensi "permanent" tanpa legal basis — Pasal 35 atur pembatasan retensi
KB,
            ],

            [
                'module_key' => 'ropa_legal_basis_library',
                'title' => 'RoPA Legal Basis Library — 6 Jenis + Contoh Industri',
                'category' => 'library',
                'feature_tags' => 'ropa_autofill,chat,remediation',
                'keywords' => 'legal basis,dasar hukum,consent,persetujuan,kontrak,contract,kewajiban hukum,legal obligation,kepentingan vital,tugas publik,legitimate interest,kepentingan sah,industri contoh',
                'summary' => 'Library 6 dasar hukum Pasal 20 UU PDP dengan contoh aktivitas industri banking, healthcare, fintech, e-commerce, HR. Dipakai AI Auto-Fill untuk pilih basis yang tepat.',
                'content' => <<<'KB'
# RoPA Legal Basis Library

## 1. Persetujuan (Consent) — Pasal 20(1)(a)
**Banking**: Consent untuk marketing produk investasi via email newsletter.
**Healthcare**: Consent untuk berbagi hasil lab ke peneliti (non-treatment).
**Fintech**: Consent untuk sharing credit score ke partner asuransi.
**E-commerce**: Cookie non-essential tracking untuk analytics.
**HR**: Consent untuk publikasi foto karyawan di website perusahaan.

## 2. Pemenuhan Kontrak — Pasal 20(1)(b)
**Banking**: Pembukaan rekening giro (kontrak bank-nasabah).
**Healthcare**: Pendaftaran pasien rawat inap (kontrak layanan kesehatan).
**Fintech**: Pinjaman online — data KTP, slip gaji untuk kontrak kredit.
**E-commerce**: Pengiriman order — alamat, kontak buat fulfillment.
**HR**: Penggajian karyawan — rekening, NPWP untuk kontrak kerja.

## 3. Kewajiban Hukum — Pasal 20(1)(c)
**Banking**: KYC — UU Perbankan + POJK 12/2018. Data identitas wajib.
**Healthcare**: Rekam medis 30 tahun — UU 29/2004 Praktik Kedokteran.
**Fintech**: Pelaporan transaksi mencurigakan ke PPATK — UU TPPU.
**E-commerce**: Pajak penghasilan penjual — UU PPh.
**HR**: Pelaporan pajak karyawan ke DJP + BPJS — UU PPh + UU BPJS.

## 4. Kepentingan Vital — Pasal 20(1)(d)
**Banking**: Contact emergency keluarga nasabah saat deteksi transaksi penipuan besar.
**Healthcare**: Treatment emergency pasien tidak sadarkan diri.
**Fintech**: Jarang — bisa untuk fraud alert sangat ekstrem.
**E-commerce**: Recall produk food yang berbahaya (kontak buyer terkait).
**HR**: Kontak darurat saat karyawan kecelakaan kerja.

## 5. Tugas Publik — Pasal 20(1)(e)
**Banking**: BUMN Perbankan menjalankan kebijakan inklusi keuangan (KUR).
**Healthcare**: RSUD memproses data pasien sesuai tupoksi pelayanan publik.
**Fintech**: (Jarang — fintech umumnya private).
**E-commerce**: BUMN e-commerce distribusi sembako program pemerintah.
**HR**: Kementerian proses data ASN.

## 6. Kepentingan Sah (Legitimate Interest) — Pasal 20(1)(f)
**Banking**: Fraud detection pattern analysis customer transactions.
**Healthcare**: Audit internal quality of care (anonymized analytics).
**Fintech**: AI credit scoring berdasarkan perilaku transaksi existing customer.
**E-commerce**: Rekomendasi produk berdasar purchase history existing customer (soft marketing, tidak ke prospek baru).
**HR**: Monitoring email kerja untuk investigasi tindak pidana in-house.

**LIA wajib untuk basis ini** — lihat LIA module di Privasimu.

## Cara Auto-Fill Memilih
AI harus scan deskripsi aktivitas dan jawab pertanyaan:
1. Apakah ada UU spesifik yang mewajibkan? → Kewajiban Hukum
2. Apakah butuh buat melaksanakan layanan yang subjek request? → Kontrak
3. Apakah emergency hidup-mati? → Kepentingan Vital (jarang)
4. Apakah untuk kepentingan bisnis yang tidak mengalahkan hak subjek, dan existing customer? → Kepentingan Sah + LIA
5. Kalau tidak ada di atas, dan subjek bisa decline tanpa kehilangan layanan → Persetujuan
KB,
            ],

            [
                'module_key' => 'ropa_data_category_library',
                'title' => 'RoPA Data Category — 15 Jenis PII Indonesia',
                'category' => 'library',
                'feature_tags' => 'ropa_autofill,dpia_autofill,pii_scan,data_discovery',
                'keywords' => 'kategori data,data category,nik,npwp,ktp,email,telepon,alamat,rekening,biometrik,genetik,kesehatan,anak,agama,politik,orientasi seksual,pii indonesia',
                'summary' => 'Library 15 kategori data pribadi spesifik Indonesia — NIK, NPWP, KTP, rekening, biometrik, kesehatan, anak, agama, dll. Dipakai auto-classify PII saat scanning + auto-fill RoPA Section 4.',
                'content' => <<<'KB'
# Kategori Data Pribadi Indonesia — Library

## Data Pribadi Umum (Pasal 4 ayat 2)
1. **NIK (Nomor Induk Kependudukan)** — 16 digit. UU 24/2013 Adminduk. Highest identifier.
2. **Nama lengkap + panggilan** — dari akta atau KTP
3. **Jenis kelamin** — Pria/Wanita (per UU Indonesia)
4. **Tanggal dan tempat lahir**
5. **Alamat domisili / KTP** — sampai RT/RW/Kelurahan
6. **Nomor telepon** — format +62 Indonesia
7. **Email**
8. **NPWP** — 15/16 digit format Direktorat Jenderal Pajak
9. **Nomor rekening bank** — 10-16 digit, prefix kode bank

## Data Pribadi Spesifik / Sensitif (Pasal 4 ayat 1)
10. **Data kesehatan** — diagnosa, obat, rekam medis, BPJS
11. **Data biometrik** — sidik jari, iris, foto wajah, suara
12. **Data genetik** — DNA, hasil pemeriksaan genetik
13. **Data anak** — subjek di bawah 18 tahun (Pasal 26 Permenkominfo 20/2016)
14. **Data keuangan pribadi** — saldo, pinjaman, credit score
15. **Catatan kejahatan**
16. **Data orientasi seksual**
17. **Pandangan politik**
18. **Data agama / kepercayaan**

## Regex Patterns (untuk PII Detector)
- NIK: `^[0-9]{16}$`
- NPWP: `^[0-9]{2}\.[0-9]{3}\.[0-9]{3}\.[0-9]-[0-9]{3}\.[0-9]{3}$` (formatted) atau `^[0-9]{15,16}$` (raw)
- Telepon ID: `^(\+62|62|0)8[0-9]{8,12}$`
- Email: standard RFC 5322
- Rekening BCA: `^[0-9]{10}$`
- Rekening Mandiri: `^[0-9]{13}$`
- Nomor BPJS: `^[0-9]{13}$`

## Context Clues
- Kolom DB bernama `nik`, `nomor_ktp`, `identity_number` → NIK
- Kolom `dob`, `tgl_lahir`, `tanggal_lahir` → tanggal lahir
- Kolom `address`, `alamat`, `domicile` → alamat
- Kolom `diagnosis`, `icd_code`, `medical_record` → data kesehatan
- Kolom `fingerprint_hash`, `biometric_*` → biometrik

## Auto-Risk Trigger
Kalau RoPA Section 4 pilih salah satu dari **10-18 (data sensitif)** → RoPA.risk_level otomatis HIGH.

## Privasimu PII Scanner (ContentPiiScanner service)
- Scan kolom DB + sample data (statistical confidence)
- Flag kolom dengan >80% match pattern sebagai PII-bearing
- Auto-classify kategori (NIK, NPWP, dll)
- Feedback loop — DPO bisa override classification
KB,
            ],

            [
                'module_key' => 'ropa_golden_examples_banking',
                'title' => 'RoPA Golden Examples — Banking Industry',
                'category' => 'example',
                'feature_tags' => 'ropa_autofill',
                'keywords' => 'contoh ropa bank,banking,kyc,rekening,pinjaman,credit scoring,nasabah,transaksi,atm',
                'summary' => 'Golden examples RoPA untuk 5 aktivitas banking umum: e-KYC onboarding, pinjaman konsumer, kartu kredit, transaksi pembayaran, AI credit scoring. Dipakai AI Auto-Fill sebagai few-shot reference.',
                'content' => <<<'KB'
# RoPA Golden Examples — Banking

## Example 1: Onboarding Nasabah (e-KYC)
```json
{
  "nama_pemrosesan": "Verifikasi Identitas Nasabah Baru via e-KYC",
  "divisi": "Customer Onboarding",
  "tujuan": "Memverifikasi identitas calon nasabah untuk membuka rekening sesuai UU Perbankan + POJK 12/2018",
  "dasar_hukum": "Kewajiban Hukum (POJK 12/2018 + UU TPPU)",
  "kategori_subjek": ["Calon Nasabah", "Nasabah"],
  "kategori_data": ["NIK", "Nama", "TTL", "Alamat KTP", "Foto Wajah (biometrik)", "Sidik Jari (biometrik)", "NPWP"],
  "sumber_data": ["Input langsung dari subjek", "Dukcapil API"],
  "jumlah_subjek_estimasi": 50000,
  "sistem_informasi": ["Core Banking", "e-KYC Platform", "Dukcapil Gateway"],
  "penerima_data": ["Dukcapil", "PPATK (reporting STR)", "Biro Kredit OJK"],
  "transfer_lintas_batas": false,
  "retensi": "10 tahun setelah rekening ditutup (UU TPPU Pasal 44)",
  "keamanan": ["AES-256 at rest", "TLS 1.3 in transit", "Biometric vault", "Access log immutable"],
  "risk_level": "HIGH",
  "trigger_high_risk": ["biometrik", "volume >10k"]
}
```

## Example 2: AI Credit Scoring
```json
{
  "nama_pemrosesan": "AI-Powered Credit Scoring untuk Pinjaman Konsumer",
  "divisi": "Consumer Credit Risk",
  "tujuan": "Menghitung skor kelayakan kredit calon peminjam via model ML",
  "dasar_hukum": "Kepentingan Sah (+ LIA dilampirkan)",
  "kategori_subjek": ["Calon Debitur", "Debitur existing"],
  "kategori_data": ["Data finansial (saldo, transaksi)", "Credit history biro OJK", "Gaji", "Utang existing"],
  "sistem_informasi": ["Credit Scoring Engine", "Data Warehouse"],
  "penerima_data": ["Biro Kredit OJK (Sistem Informasi Debitur)"],
  "transfer_lintas_batas": false,
  "retensi": "5 tahun setelah pinjaman lunas atau ditolak",
  "keamanan": ["Model explainability log", "No single-decision automation (human-in-loop)"],
  "risk_level": "HIGH",
  "trigger_high_risk": ["AI penuh", "otomatisasi keputusan", "profiling"],
  "catatan": "Wajib provide explainability ke subjek (Pasal 9 Hak Objection)"
}
```

## Example 3: Transaksi Kartu Kredit
```json
{
  "nama_pemrosesan": "Pemrosesan Transaksi Kartu Kredit",
  "divisi": "Credit Card Operations",
  "tujuan": "Authorize + settle transaksi kartu kredit",
  "dasar_hukum": "Pemenuhan Kontrak (CC agreement)",
  "kategori_subjek": ["Cardholder"],
  "kategori_data": ["PAN (card number)", "CVV (hashed)", "Merchant info", "Amount", "Location transaksi"],
  "sistem_informasi": ["Card Management System", "VISA/Mastercard Network Gateway"],
  "penerima_data": ["VISA/Mastercard", "Merchant acquirer bank"],
  "transfer_lintas_batas": true,
  "safeguards": ["PCI DSS certification", "Tokenization"],
  "retensi": "Transaksi: 7 tahun (UU Perbankan). CVV: never stored.",
  "risk_level": "HIGH",
  "trigger_high_risk": ["data finansial", "transfer lintas batas"]
}
```

## Key Patterns AI Auto-Fill Harus Ingat
- Banking selalu **Kewajiban Hukum** untuk KYC (bukan Consent)
- Biometrik triggers HIGH risk + auto DPIA
- Data finansial = sensitif → risk HIGH
- Transfer VISA/Mastercard = cross-border dengan PCI DSS safeguards
- Retensi bank umumnya 5-10 tahun (UU TPPU, UU Perbankan)
KB,
            ],

            [
                'module_key' => 'ropa_golden_examples_healthcare',
                'title' => 'RoPA Golden Examples — Healthcare',
                'category' => 'example',
                'feature_tags' => 'ropa_autofill',
                'keywords' => 'contoh ropa healthcare,rumah sakit,rs,rekam medis,pasien,klinik,lab,apotek,telemedicine',
                'summary' => 'Golden examples RoPA untuk healthcare: rekam medis, pendaftaran pasien, telemedicine, lab results sharing, farmasi. Wajib pertimbangkan UU Praktik Kedokteran + data kesehatan sensitif.',
                'content' => <<<'KB'
# RoPA Golden Examples — Healthcare

## Example 1: Rekam Medis Pasien
```json
{
  "nama_pemrosesan": "Pengelolaan Rekam Medis Pasien Rawat Inap",
  "divisi": "Medical Records",
  "tujuan": "Menyimpan riwayat medis untuk continuity of care",
  "dasar_hukum": "Kewajiban Hukum (UU 29/2004 Praktik Kedokteran + Permenkes 269/2008)",
  "kategori_subjek": ["Pasien", "Wali pasien (untuk minor)"],
  "kategori_data": ["NIK", "Rekam medis (diagnosis, obat, tindakan)", "Hasil lab", "Allergy list", "BPJS"],
  "sistem_informasi": ["SIMRS (Sistem Informasi Manajemen RS)", "EMR"],
  "penerima_data": ["BPJS Kesehatan (claim)", "Kementerian Kesehatan (reporting wabah)"],
  "retensi": "30 tahun (Permenkes 269/2008)",
  "keamanan": ["Pseudonymization patient ID", "Audit access per-dokter", "Break-the-glass emergency access log"],
  "risk_level": "HIGH",
  "trigger_high_risk": ["data kesehatan"]
}
```

## Example 2: Telemedicine Consultation
```json
{
  "nama_pemrosesan": "Konsultasi Dokter via Telemedicine",
  "divisi": "Telemedicine",
  "tujuan": "Konsultasi jarak jauh + rekam penyakit",
  "dasar_hukum": "Pemenuhan Kontrak (kontrak konsultasi)",
  "kategori_subjek": ["Pasien pengguna aplikasi"],
  "kategori_data": ["Video call recording (opsional consent)", "Chat history", "Keluhan", "Diagnosis dokter", "Obat resep"],
  "sistem_informasi": ["Telemedicine App", "Video CDN (cloud)"],
  "penerima_data": ["Apotek partner (e-resep)", "Payment Gateway"],
  "transfer_lintas_batas": true,
  "safeguards": ["AWS SG/Jakarta region only", "SCC with AWS", "End-to-end encryption for video"],
  "retensi": "Konsultasi: 5 tahun. Video: 1 tahun (consent-based)",
  "risk_level": "HIGH"
}
```

## Example 3: Farmasi — Rekam Resep
```json
{
  "nama_pemrosesan": "Pencatatan Resep Obat di Apotek",
  "divisi": "Pharmacy Operations",
  "tujuan": "Dispense obat + rekam histori untuk drug interaction check",
  "dasar_hukum": "Kewajiban Hukum (Permenkes 73/2016 Standar Pelayanan Kefarmasian)",
  "kategori_subjek": ["Pasien pembeli obat"],
  "kategori_data": ["Nama pasien", "Resep dokter", "Obat + dosis", "Alergi", "Riwayat pembelian"],
  "retensi": "5 tahun (Permenkes 73/2016)",
  "risk_level": "HIGH"
}
```

## Key Patterns
- **Retensi panjang** (10-30 tahun) karena UU spesifik kesehatan
- **Break-the-glass access** untuk emergency → wajib di-log terpisah
- **Data kesehatan = sensitif** (Pasal 4 UU PDP) → auto HIGH risk
- Integrasi BPJS hampir selalu ada → listed sebagai penerima
KB,
            ],
        ];
    }

    // ======================================================================
    // 3. DPIA — 21 Kategori + Risk Events
    // ======================================================================
    private function dpiaSections(): array
    {
        return [
            [
                'module_key' => 'dpia_21_categories',
                'title' => 'DPIA 21 Kategori Risiko — Definition + Event Examples',
                'category' => 'wizard',
                'feature_tags' => 'dpia_autofill,chat',
                'keywords' => 'dpia,21 kategori,risk event,mitigasi,privasi,risk matrix,skor',
                'summary' => '21 kategori DPIA Privasimu: dasar hukum, minimisasi, retensi, keamanan, transfer, enkripsi, auth, log, backup, vendor, training, IR, child data, automation, profiling, consent, DSR, breach, cross-border, dll. Tiap kategori ada 3-5 risk event template.',
                'content' => <<<'KB'
# DPIA 21 Kategori Risiko — Library

## Kategori 1: Dasar Hukum Pemrosesan
**Risk Events**:
- Tidak ada dasar hukum yang jelas → Likelihood 3, Impact 5
- Dasar hukum pilihan salah (mis. pakai KS untuk marketing prospek) → L2 I4
**Mitigasi**: Document legal basis per aktivitas, review kuartal.

## Kategori 2: Minimisasi Data (Data Minimization)
**Risk Events**:
- Over-collection field tidak relevan → L3 I3
- Duplicate storage across systems → L4 I3
**Mitigasi**: Data map audit, column-level review, purge unused fields.

## Kategori 3: Pembatasan Penyimpanan (Retention Limits)
**Risk Events**:
- Data disimpan indefinitely tanpa kebijakan → L4 I3
- Policy retensi tidak di-enforce di sistem → L5 I3
**Mitigasi**: Automated purge job, retention policy per-system, annual audit.

## Kategori 4: Integritas & Kerahasiaan
**Risk Events**:
- Data tampering tanpa log → L2 I5
- Shared account tanpa accountability → L4 I4
**Mitigasi**: Immutable audit log, individual account, checksum.

## Kategori 5: Transfer Lintas Batas
**Risk Events**:
- Transfer tanpa SCC/BCR → L3 I5
- Cloud vendor di yurisdiksi tanpa adequacy → L3 I4
**Mitigasi**: SCC + TIA, prefer local region, encryption pre-transfer.

## Kategori 6: Enkripsi & Pseudonimisasi
**Risk Events**:
- Data at-rest tidak encrypted → L3 I5
- PII di-log plaintext → L4 I4
**Mitigasi**: AES-256 at-rest, pseudonymization ID, log masking.

## Kategori 7: Otentikasi & Akses Kontrol
**Risk Events**:
- Password lemah + no MFA → L4 I5
- Over-privileged accounts → L4 I4
**Mitigasi**: MFA wajib, RBAC, quarterly access review.

## Kategori 8: Pencatatan Log & Audit
**Risk Events**:
- Log tidak lengkap atau bisa dimanipulasi → L3 I4
- Log tidak di-review (shelved) → L5 I3
**Mitigasi**: SIEM integration, log retention 90+ hari, weekly review.

## Kategori 9: Backup & Disaster Recovery
**Risk Events**:
- Backup tidak di-test → L3 I5
- Backup exposed (mis. S3 bucket public) → L2 I5
**Mitigasi**: Quarterly restore test, backup encrypted + access-controlled.

## Kategori 10: Vendor / Processor Management
**Risk Events**:
- Vendor tanpa DPA → L4 I4
- Vendor tidak di-audit → L3 I3
**Mitigasi**: DPA signed, annual vendor audit, TPRM module.

## Kategori 11: Pelatihan Karyawan
**Risk Events**:
- Karyawan belum pelatihan PDP → L4 I3
- Phishing awareness rendah → L4 I4
**Mitigasi**: Mandatory annual training, phishing drill quarterly.

## Kategori 12: Incident Response
**Risk Events**:
- IR plan tidak ada → L3 I5
- IR tim tidak latihan → L3 I4
**Mitigasi**: IR plan documented, annual tabletop exercise, Privasimu Breach Simulation.

## Kategori 13: DPIA Review Schedule
**Risk Events**:
- DPIA stale (>1 tahun) → L3 I3
**Mitigasi**: Annual DPIA refresh, trigger on system change.

## Kategori 14: Child Data Specific
**Risk Events**:
- Proses data <18 tanpa parental consent → L3 I5
- Tidak ada age-gate → L4 I4
**Mitigasi**: Age verification, consent parental, anonymize after aggregation.

## Kategori 15: Automated Decision-Making
**Risk Events**:
- Keputusan full-auto tanpa human review → L3 I5
- No explainability ke subjek → L4 I4
**Mitigasi**: Human-in-loop, explainability UI, objection workflow.

## Kategori 16: Profiling
**Risk Events**:
- Profiling tanpa consent → L4 I4
- Profiling diskriminatif (gender/agama/ras) → L2 I5
**Mitigasi**: Consent explicit, bias testing, sensitive attribute exclusion.

## Kategori 17: Consent Management
**Risk Events**:
- Consent pre-checked → L4 I4
- No withdraw mechanism → L3 I4
**Mitigasi**: Unchecked by default, Preference Center, auto-cascade withdraw.

## Kategori 18: Data Subject Rights Facilitation
**Risk Events**:
- Tidak respons 72h → L3 I4
- DSR workflow manual → L4 I3
**Mitigasi**: Privasimu DSR module, auto-assign DPO.

## Kategori 19: Notification & Transparency
**Risk Events**:
- Privacy notice tidak jelas → L3 I3
- Tidak update saat perubahan → L4 I3
**Mitigasi**: AI Policy Review, version history, notification email saat update.

## Kategori 20: Data Breach Notification
**Risk Events**:
- Tidak ada countdown 72h → L3 I5
- Template notifikasi kacau → L3 I4
**Mitigasi**: Privasimu Breach module, pre-approved templates, drill.

## Kategori 21: Cross-Border Safeguards
**Risk Events**:
- SCC expired / tidak signed → L3 I5
- TIA tidak dilakukan → L3 I4
**Mitigasi**: SCC tracker, annual TIA review, country risk monitoring.
KB,
            ],

            [
                'module_key' => 'dpia_risk_matrix_5x5',
                'title' => 'DPIA Risk Matrix 5×5 — Interpretasi & Actions',
                'category' => 'library',
                'feature_tags' => 'dpia_autofill,chat',
                'keywords' => 'risk matrix,5x5,likelihood,impact,skor risiko,warna,hijau,kuning,oranye,merah,low,medium,high,critical',
                'summary' => 'Risk matrix 5×5 Privasimu: Low (1-4 hijau), Medium (5-9 kuning), High (10-14 oranye), Critical (15-25 merah). Mitigasi wajib untuk skor ≥10. Residual risk post-mitigasi harus ≤9 untuk lanjut pemrosesan.',
                'content' => <<<'KB'
# DPIA Risk Matrix 5×5

## Dimensions
- **Likelihood**: 1=Sangat Jarang, 2=Jarang, 3=Kadang, 4=Sering, 5=Hampir Pasti
- **Impact**: 1=Diabaikan, 2=Minor, 3=Moderate, 4=Major, 5=Catastrophic

## Risk Score = Likelihood × Impact

```
Impact →    1    2    3    4    5
Likelihood
    1       1    2    3    4    5   Low
    2       2    4    6    8   10   Medium
    3       3    6    9   12   15   High/Critical
    4       4    8   12   16   20
    5       5   10   15   20   25
```

## Zona + Warna
| Skor | Label | Warna | Action |
|---|---|---|---|
| 1-4 | Low | 🟢 Hijau | Monitor, no action needed |
| 5-9 | Medium | 🟡 Kuning | Mitigasi optional, revisit 1 tahun |
| 10-14 | High | 🟠 Oranye | **Mitigasi wajib** sebelum deploy |
| 15-25 | Critical | 🔴 Merah | **Stop or redesign** — escalate ke Direksi |

## Interpretasi Impact
### Impact 1 — Diabaikan
- Data tidak sensitif
- 1-10 subjek
- Recovery <1 hari

### Impact 2 — Minor
- Data umum bocor
- 10-100 subjek
- Recovery 1-7 hari, no notification

### Impact 3 — Moderate
- Data umum + partial sensitive
- 100-10k subjek
- Notification ke subjek required

### Impact 4 — Major
- Data sensitif bocor
- 10k-100k subjek
- KOMDIGI + subjek notification, media coverage likely

### Impact 5 — Catastrophic
- Data sensitif massal
- >100k subjek
- Nasional news, regulator probe, class action

## Residual Risk
Setelah mitigasi, calculate **Residual Risk Score**:
```
Residual = Inherent × (1 - Effectiveness)
```
Where Effectiveness = 0-100% confidence mitigasi kerja.

**Target**: Residual ≤ 9 (Medium) sebelum pemrosesan dilanjut.

Kalau residual tetap ≥10 setelah best-effort mitigasi:
- **Opsi A**: Redesign pemrosesan (minimisasi data, pseudonymize)
- **Opsi B**: Konsultasi ke KOMDIGI (opt-in review)
- **Opsi C**: Hentikan pemrosesan
KB,
            ],
        ];
    }

    // ======================================================================
    // 4. GAP Assessment
    // ======================================================================
    private function gapSections(): array
    {
        return [
            [
                'module_key' => 'gap_assessment_structure',
                'title' => 'GAP Assessment — 33 Pertanyaan UU PDP Structure',
                'category' => 'wizard',
                'feature_tags' => 'remediation,chat',
                'keywords' => 'gap assessment,33 pertanyaan,kepatuhan,scoring,tata kelola,siklus proses,uu pdp indikator,audit,skor',
                'summary' => 'GAP Assessment Privasimu = 33 pertanyaan resmi UU PDP, 2 kategori besar (Tata Kelola + Siklus Proses PDP). Scoring: Sudah=1, Sebagian=0.5, Belum=0. Total 33. AI Remediation Plan generate dari jawaban "Belum/Sebagian".',
                'content' => <<<'KB'
# GAP Assessment Structure

## Kategori 1: Tata Kelola (Governance) — 14 pertanyaan
1. Apakah sudah ditunjuk DPO?
2. Apakah DPO punya latar belakang / sertifikasi PDP?
3. Apakah ada Privacy Policy / Privacy Notice publik?
4. Apakah policy di-review berkala (minimal 1 tahun)?
5. Apakah sudah dilaksanakan pelatihan PDP untuk seluruh karyawan?
6. Apakah ada SOP Incident Response?
7. Apakah ada Data Processing Agreement (DPA) dengan semua vendor?
8. Apakah ada kontrak Joint Controller / Controller-Processor formal?
9. Apakah pernah dilakukan audit internal PDP?
10. Apakah hasil audit di-tindaklanjuti?
11. Apakah ada Records of Processing Activities (RoPA) lengkap?
12. Apakah RoPA di-update setiap aktivitas baru?
13. Apakah ada budget compliance yang ring-fenced?
14. Apakah Direksi aware dan mendukung program PDP?

## Kategori 2: Siklus Proses PDP — 19 pertanyaan

### Pengumpulan (Collection) — 4
15. Apakah collection point teridentifikasi (mis. form, app, CCTV)?
16. Apakah ada mekanisme consent yang valid?
17. Apakah ada notice kepada subjek saat collection?
18. Apakah retention period ditetapkan saat collection?

### Penggunaan (Use) — 3
19. Apakah penggunaan data terbatas pada tujuan asli?
20. Apakah ada mekanisme accountability (log penggunaan)?
21. Apakah purpose limitation ter-enforce di sistem?

### Penyimpanan (Storage) — 3
22. Apakah data encrypted at-rest?
23. Apakah ada policy klasifikasi data?
24. Apakah backup encrypted dan di-test?

### Pengiriman (Transfer) — 3
25. Apakah transfer internal tercatat di RoPA?
26. Apakah transfer ke 3rd party ada DPA?
27. Apakah cross-border transfer ada SCC/BCR?

### Penghapusan (Deletion) — 3
28. Apakah ada automated purge job retensi?
29. Apakah DSR Deletion request bisa dieksekusi end-to-end?
30. Apakah ada certificate of destruction untuk physical media?

### Perlindungan (Security) — 3
31. Apakah MFA wajib untuk akses data pribadi?
32. Apakah ada Security Operations Center (SOC) monitoring?
33. Apakah penetration test dilakukan rutin (min 1x/tahun)?

## Scoring
- Sudah → 1 point (HIJAU)
- Sebagian → 0.5 point (KUNING)
- Belum → 0 point (MERAH)
- Total max = 33

## Compliance Level
| Total Score | Level | Warna |
|---|---|---|
| 28-33 | High | 🟢 |
| 16-27 | Medium | 🟡 |
| 0-15 | Low | 🔴 |

## Remediation Plan Generation
AI fokus ke question dengan jawaban **Belum** (prioritas 1) dan **Sebagian** (prioritas 2). Output:
- Step-by-step action items
- PIC recommendation (DPO/Legal/IT/HR)
- Estimated effort (hours/days)
- Regulatory reference (Pasal UU PDP)
- Prioritas berdasar dampak hukum + ease of fix
KB,
            ],
        ];
    }

    // ======================================================================
    // 5. BREACH — 15 SOP + Workflow
    // ======================================================================
    private function breachSections(): array
    {
        return [
            [
                'module_key' => 'breach_15_sop_overview',
                'title' => 'Breach Containment — 15 SOP Templates',
                'category' => 'workflow',
                'feature_tags' => 'breach_response,chat,remediation',
                'keywords' => 'breach,15 sop,containment,ransomware,phishing,unauthorized,insider,ddos,data leak,misconfig,physical,supply chain,cloud,malware,social engineering,cryptojacking,brute force',
                'summary' => '15 SOP containment Privasimu: Ransomware, Phishing, Unauthorized Access, Insider, DDoS, Data Leak, Misconfiguration, Physical Loss, Supply Chain, Cloud Breach, Malware, Social Engineering, Cryptojacking, Brute Force, Other. Tiap SOP ada 5-10 step checklist + RACI.',
                'content' => <<<'KB'
# Breach Containment — 15 SOP Templates

## 1. Ransomware
Steps: (1) Isolate affected hosts, (2) Identify strain via IoC, (3) Preserve evidence, (4) Check backup integrity, (5) Decide pay/not (Direksi call), (6) Restore from backup, (7) RCA, (8) Remediation password/MFA/EDR, (9) Notify KOMDIGI, (10) Notify subjects if PII leaked.

## 2. Phishing
Steps: (1) Identify phished account(s), (2) Disable account, (3) Revoke all sessions/tokens, (4) Password reset forced, (5) Check email rules for data exfil, (6) Identify data accessed, (7) Awareness training recap, (8) Notify.

## 3. Unauthorized Access (Insider/External)
Steps: (1) Identify source IP/account, (2) Revoke access, (3) Preserve logs, (4) Identify data accessed, (5) Forensics deep-dive, (6) Legal action consideration, (7) Notify.

## 4. Insider Threat (Malicious)
Steps: (1) Suspend account (HR partnership), (2) Preserve all evidence, (3) Forensics on devices, (4) Legal investigation, (5) Identify data taken, (6) Revoke external access (partner/client), (7) Police report if criminal, (8) Notify.

## 5. DDoS
Steps: (1) Activate DDoS mitigation (Cloudflare/AWS Shield), (2) Geo-block source, (3) Monitor for correlation (DDoS as cover), (4) Check if data breach concurrent, (5) Post-mortem.

## 6. Data Leak (Public Exposure)
Steps: (1) Identify leak source, (2) Takedown (DMCA, legal), (3) Identify data scope, (4) Track downstream copies, (5) Public statement prep, (6) Notify KOMDIGI + subjects.

## 7. Misconfiguration (S3/DB Exposed)
Steps: (1) Close public access immediately, (2) Check access logs for unauthorized download, (3) Rotate credentials if exposed, (4) Audit all configs, (5) Notify if data accessed.

## 8. Physical Loss (Laptop/Document)
Steps: (1) Report to physical security + police, (2) Remote wipe if MDM, (3) Identify data on device, (4) Revoke tokens/certs from device, (5) Notify if sensitive data.

## 9. Supply Chain Breach (Vendor)
Steps: (1) Confirm incident with vendor, (2) Identify data shared with vendor, (3) Rotate credentials shared, (4) Review DPA enforcement, (5) Notify if our subjects affected.

## 10. Cloud Breach (IaaS/SaaS)
Steps: (1) Contact cloud provider SOC, (2) Preserve CloudTrail/audit log, (3) Identify resources accessed, (4) Rotate IAM, (5) Review shared responsibility.

## 11. Malware (non-ransomware)
Steps: (1) Quarantine host, (2) Identify malware family, (3) Scope infection (lateral movement check), (4) Clean/re-image, (5) Patch vulnerability exploited.

## 12. Social Engineering (non-phishing)
Steps: (1) Identify deception vector, (2) Identify data/resources compromised, (3) Reverse action if possible (mis. wire transfer recall), (4) Re-train staff, (5) Process control improvement.

## 13. Cryptojacking
Steps: (1) Identify affected hosts (CPU anomaly), (2) Clean mining software, (3) Forensics on initial entry, (4) Usually no PII impact but check, (5) Remediate root cause.

## 14. Brute Force
Steps: (1) Identify target account/service, (2) Rate-limit / lock, (3) Check if successful, (4) Force password reset, (5) Add MFA if missing, (6) Audit log for post-compromise.

## 15. Other
Freeform checklist — DPO lead kasus spesifik.

## Semua SOP WAJIB:
- 72h KOMDIGI countdown aktif
- RACI matrix per-step (editable)
- Evidence upload per-step
- Skipped flag dengan alasan
- Auto PDF breach report saat close
KB,
            ],

            [
                'module_key' => 'breach_subject_notification_template',
                'title' => 'Breach Subject Notification — Anti-Churn Template',
                'category' => 'template',
                'feature_tags' => 'breach_response',
                'keywords' => 'breach notification,subjek,template,email,anti churn,himbauan,komunikasi,apology,tone',
                'summary' => 'Template notifikasi subjek saat breach — fokus fakta + langkah protektif + contact bantuan. HINDARI reveal teknis root cause (bikin churn). Ini template Privasimu yang pre-approved.',
                'content' => <<<'KB'
# Breach Subject Notification — Template Anti-Churn

## Template Email (Indonesia)

```
Subjek: Informasi Penting Terkait Data Akun Anda

Kepada Yth. [Nama Subjek],

Dengan hormat,

Kami dari [Nama Perusahaan] ingin menyampaikan informasi penting terkait keamanan data pribadi Anda.

[Tanggal Insiden], kami mendeteksi adanya akses tidak berwenang terhadap sebagian sistem kami. Setelah investigasi awal, kami menemukan bahwa data berikut mungkin terdampak:

• [Kategori data — mis. Email, Nama]
• [Kategori data lainnya]

Yang TIDAK terdampak:
• Kata sandi Anda (tersimpan terenkripsi dan tidak terbaca)
• Data pembayaran / kartu kredit (tersimpan di sistem terpisah)
• [data lain yang tidak terdampak]

**Langkah yang telah kami lakukan:**
• Akses tidak berwenang telah dihentikan
• Tim keamanan internal + konsultan eksternal melakukan investigasi penuh
• Peningkatan kontrol keamanan telah diterapkan
• KOMDIGI telah diberi tahu sesuai ketentuan UU PDP

**Rekomendasi untuk Anda:**
• Ganti kata sandi akun Anda sebagai langkah preventif
• Aktifkan verifikasi 2 langkah (2FA) jika belum
• Pantau aktivitas akun Anda secara berkala
• Waspadai email/SMS phishing yang mungkin memanfaatkan insiden ini

Kami memahami bahwa kepercayaan Anda adalah hal paling berharga. Tim kami siap membantu jika Anda memiliki pertanyaan atau kekhawatiran:

📧 Email: [dpo@company.com]
📞 Hotline: [xxx-xxx-xxxx] (08.00–20.00 WIB)
🌐 FAQ: [company.com/insiden-faq]

Kami berkomitmen untuk terus memperkuat perlindungan data Anda.

Salam,
[Nama DPO]
Data Protection Officer
[Nama Perusahaan]
```

## Prinsip Komunikasi Anti-Churn

### DO ✅
- Fokus **fakta**: apa yang terjadi, kapan, data apa
- Kasih **actionable steps** untuk subjek (ganti password, enable 2FA)
- Sediakan **kontak bantuan** (hotline, email)
- **Acknowledge concern** tanpa over-apologize
- Highlight **apa yang TIDAK terdampak** (password hashed, CC terpisah)

### DON'T ❌
- **Jangan reveal teknis root cause** ("SQL injection di endpoint login") — bikin panic + meme material
- **Jangan blame** 3rd party tanpa bukti (legal risk)
- **Jangan janji false**: "ini tidak akan terjadi lagi" (tidak bisa 100%)
- **Jangan bundling promosi** ("maaf, tapi ada promo!")
- **Jangan panjang sampai 1000 kata** — skimmable (max 300 kata)

## Timing Delivery
- **Jam kerja** (09.00-17.00 WIB) — bukan malam hari (bikin panic overnight)
- **Hindari Jumat sore** — weekend customer service tidak ada
- **Sebar dalam batch** kalau >50k subjek (rate limit mail gateway)

## Follow-Up
- **H+7**: email recap progress remediation
- **H+30**: post-mortem summary (kalau appropriate)
- **Annual**: transparency report include insiden ini (best practice)
KB,
            ],
        ];
    }

    // ======================================================================
    // 6. DSR
    // ======================================================================
    private function dsrSections(): array
    {
        return [
            [
                'module_key' => 'dsr_7_types_workflow',
                'title' => 'DSR — 7 Tipe Request + Workflow per Tipe',
                'category' => 'workflow',
                'feature_tags' => 'dsr,chat,remediation',
                'keywords' => 'dsr,hak subjek,access,correction,deletion,portability,withdraw,objection,information,workflow,sql generator',
                'summary' => 'DSR 7 tipe request dengan workflow berbeda: Access (copy data), Correction (update), Deletion (hapus/anonymize), Portability (export machine-readable), Withdraw Consent (flag flip), Objection (stop processing), Information (disclosure).',
                'content' => <<<'KB'
# DSR 7 Tipe Request — Workflow

## 1. Access (Akses) — Pasal 5
**Workflow**:
1. Identity verification via OTP
2. Scope: tentukan system mana yang query
3. SQL Generator: `SELECT col1, col2 FROM table WHERE subject_id=?`
4. Admin eksekusi SQL di platform mereka
5. Admin upload CSV/JSON hasil
6. Privasimu package hasil dalam PDF (signed DPO) + kirim ke subjek
**Deadline**: 72 jam

## 2. Correction (Koreksi) — Pasal 6
**Workflow**:
1. Identity verification
2. Subjek specify field + new value
3. Validate (e.g. email format)
4. SQL Generator: `UPDATE table SET col=? WHERE subject_id=?`
5. Admin eksekusi
6. Log audit trail perubahan
7. Confirm ke subjek
**Deadline**: 72 jam

## 3. Deletion (Penghapusan) — Pasal 7
**Workflow**:
1. Identity verification
2. Check legal obligation to retain (mis. UU TPPU 10 tahun)
3. Scope: system mana yang hapus
4. SQL Generator:
   - True delete: `DELETE FROM table WHERE subject_id=?`
   - Anonymize: `UPDATE table SET email=NULL, nik=NULL WHERE subject_id=?`
   - Tombstone: soft-delete flag
5. Admin eksekusi per shard/system
6. Certificate of Deletion signed by DPO
**Deadline**: 72 jam (atau justifikasi tolak dengan legal basis)

## 4. Portability (Portabilitas) — Pasal 7(3)
**Workflow**:
1. Similar ke Access
2. Output format: machine-readable (JSON, CSV, XML) — bukan PDF
3. Standardized format untuk kemudahan transfer ke platform lain
4. Include metadata: source, timestamp, data types
**Deadline**: 72 jam

## 5. Withdraw Consent — Pasal 8
**Workflow**:
1. Subject portal Preference Center / embed widget
2. Find consent record
3. UPDATE consent.withdrawn_at, withdrawn_reason
4. **Cascade**: trigger consent.on_withdraw (stop marketing, remove from list)
5. Confirm email ke subjek
**Deadline**: Immediate (not 72h — harus cepat)
**Catatan**: Withdraw tidak retroaktif — pemrosesan sebelum withdraw tetap sah

## 6. Objection (Keberatan) — Pasal 9
**Workflow**:
1. Subjek objectt spesifik activity (mis. profiling credit)
2. DPO review case-by-case
3. **Kalau valid**: stop processing, delete automated decision output
4. **Kalau tidak valid**: justify dengan legal obligation, reply formal
5. Provide human review alternative (for automated decisions)
**Deadline**: 72 jam

## 7. Information Request — Pasal 10
**Workflow**:
1. Subjek tanya: siapa data saya, untuk apa, berapa lama
2. Privasimu generate report dari RoPA + consent log
3. Output: PDF ringkasan per-subjek
4. Tidak butuh SQL ke production DB — hanya metadata lookup
**Deadline**: 72 jam

## SQL Generator Pattern (Phase K)
Privasimu TIDAK eksekusi SQL di DB klien. Generate SQL pack:
```
DSR-2026-042__SYS01_shard_01__deletion.sql
DSR-2026-042__SYS01_shard_02__deletion.sql
...
```
Admin bank eksekusi di DB mereka, balik upload bukti + rows_affected.

## Common Rejections
- Subjek minta delete data yang wajib retained (UU TPPU, UU Kesehatan) → reject + explain
- Subjek tidak bisa verify identity → reject + retry flow
- Request bulan ini sudah fulfilled → dedupe
- Scope terlalu broad ("hapus semua") → clarification needed
KB,
            ],
        ];
    }

    // ======================================================================
    // 7. CONSENT
    // ======================================================================
    private function consentSections(): array
    {
        return [
            [
                'module_key' => 'consent_collection_patterns',
                'title' => 'Consent Collection Point — Valid Patterns',
                'category' => 'workflow',
                'feature_tags' => 'chat,remediation,policy_review',
                'keywords' => 'consent,persetujuan,collection point,opt in,opt out,unchecked,preference center,withdraw',
                'summary' => 'Valid consent menurut UU PDP: bebas, spesifik, informed, unambiguous. Pre-checked box = invalid. Bundling consent = invalid. Preference Center untuk withdraw. Privasimu track per-subjek + version T&C.',
                'content' => <<<'KB'
# Consent Collection — Valid Patterns

## 4 Syarat Consent Valid (Pasal 22-25)
1. **Bebas** — subjek bisa decline tanpa konsekuensi layanan utama
2. **Spesifik** — per tujuan, bukan bundling "setuju semua"
3. **Informed** — subjek tahu data apa, untuk apa, berapa lama, siapa pihak 3
4. **Unambiguous** — clear action (centang bukan pre-checked, submit button terpisah)

## Valid Pattern ✅
- **Unchecked checkbox** dengan label jelas: "Saya setuju data saya digunakan untuk marketing promo via email"
- **Double opt-in** untuk email subscriber — click link konfirmasi
- **Granular checkbox** per tujuan: ☐ Marketing promo  ☐ Newsletter mingguan  ☐ Riset produk

## Invalid Pattern ❌
- ☑ Pre-checked box "Saya setuju T&C + privacy + marketing"
- Bundling: subjek tidak bisa daftar tanpa centang marketing
- Implicit consent: "Dengan menggunakan situs ini, Anda setuju..."
- Dark pattern: tombol "No" di-hide / kecil / color-blend

## Withdraw Mechanism (Pasal 8)
Wajib sediakan:
- **Preference Center publik** — `/preference-center?token=...`
- **Unsubscribe link** di setiap email marketing
- **In-app settings** untuk mobile app
- **Cookie Editor** iframe embed di website
- **Contact DPO** untuk subjek yang tidak melek tech

Withdraw harus **sama mudahnya** dengan consent initially (Pasal 8).

## Version Tracking
Setiap perubahan T&C / Privacy Notice = version baru. Subjek lama:
- **Minor edit** (typo): tidak perlu re-consent
- **Substantive change** (tujuan baru, pihak ke-3 baru): **wajib re-consent**

Privasimu Consent Module track:
```
consent_records:
  - subject_id
  - consent_version_id (FK → consent_items.version)
  - granted_at
  - withdrawn_at (nullable)
  - proof: ip_address, user_agent, screenshot_url
  - collection_point_id
```

## Consent Bukti Evidence
Saat audit, wajib bisa tunjukkan untuk setiap consent:
1. Versi T&C yang disetujui saat itu (snapshot)
2. Timestamp
3. IP + user agent
4. Collection point (form mana)
5. Screenshot UI (opsional, best practice)

## Common Mistakes
- Centralize consent storage ke 1 table tapi tidak track versi T&C saat itu
- Withdraw cascade tidak aktif (subjek withdraw tapi masih dapat email)
- Consent anak <18 tanpa parental confirmation
- Consent untuk data kesehatan tanpa eksplisit mention "data sensitif"
KB,
            ],
        ];
    }

    // ======================================================================
    // 8. CONTRACT REVIEW
    // ======================================================================
    private function contractReviewSections(): array
    {
        return [
            [
                'module_key' => 'contract_review_dpa_checklist',
                'title' => 'Contract Review — DPA Klausul Kritis Checklist',
                'category' => 'library',
                'feature_tags' => 'contract_review,chat,vendor_screening',
                'keywords' => 'dpa,data processing agreement,klausul,checklist,kontrak,vendor,review,liability,audit,breach notification,subprocessor',
                'summary' => 'DPA (Data Processing Agreement) wajib punya 10 klausul kritis: role definition, scope, security, sub-processor, cross-border, breach notification SLA, audit rights, termination, liability, return/delete data. Privasimu Contract Review AI scan kontrak vs checklist.',
                'content' => <<<'KB'
# DPA Klausul Kritis — Checklist Review

## 1. Role Definition
**Wajib ada**: clarification siapa Pengendali (Controller) vs Pemroses (Processor).
**Red flag**: tidak eksplisit, atau Pemroses claim punya "own purpose" (jadi Controller).

## 2. Scope of Processing
**Wajib ada**:
- Kategori subjek (karyawan, nasabah, dll)
- Kategori data (NIK, finansial, biometrik, dll)
- Tujuan spesifik
- Durasi pemrosesan

**Red flag**: bahasa terlalu broad ("semua data yang diberikan") — invite overreach.

## 3. Security Measures
**Wajib ada**:
- Encryption at-rest + in-transit (AES-256, TLS 1.3)
- Access control (MFA, RBAC)
- Audit logging
- Incident response capability
- Training karyawan

**Red flag**: generic "reasonable security measures" tanpa detail.

## 4. Sub-Processor (Vendor-nya Vendor)
**Wajib ada**:
- List sub-processor saat ini
- Notification kalau ganti sub-processor (min 30 hari sebelumnya)
- Right to object untuk Controller
- DPA cascade (sub-processor signed equivalent DPA)

**Red flag**: "Processor boleh tunjuk sub-processor kapan saja tanpa notifikasi".

## 5. Cross-Border Transfer
**Wajib ada**:
- List negara tujuan data
- Safeguard mechanism (SCC, BCR, adequacy)
- TIA requirement

**Red flag**: "data disimpan di cloud global" tanpa spesifikasi region.

## 6. Breach Notification SLA
**Wajib ada**:
- Pemroses notify Pengendali **dalam 24 jam** dari discovery (max 48h)
- Format notifikasi minimum
- Cooperation untuk notify subjek + regulator

**Red flag**: "reasonable promptness" (no time commitment) atau >72 jam.

## 7. Audit Rights
**Wajib ada**:
- Controller berhak audit minimal 1x/tahun
- On-site atau remote
- Sub-processor juga bisa di-audit (extended right)
- Biaya audit arrangement (usually Processor bear reasonable cost)

**Red flag**: "audit dibatasi sertifikasi 3rd party" (kalau hanya SOC2 tanpa right to audit sendiri = weak).

## 8. Termination
**Wajib ada**:
- Notice period (30/60/90 hari)
- Right to terminate karena breach material
- Survival clause (confidentiality + return/delete data)

**Red flag**: auto-renew tanpa opt-out window pendek.

## 9. Return or Delete Data Post-Termination
**Wajib ada**:
- Dalam 30 hari setelah termination
- Option: return ke Controller atau securely delete
- Certificate of destruction signed

**Red flag**: "Processor keep data for backup reasons" — no time limit.

## 10. Liability & Indemnification
**Wajib ada**:
- Liability cap yang reasonable (usually 1-3x annual fee)
- Indemnification untuk breach karena Processor fault
- Carve-outs: gross negligence, willful misconduct

**Red flag**:
- Liability cap terlalu rendah (mis. Rp 10 juta untuk kontrak besar)
- Total exclusion of liability

## Risk Scoring Matrix

AI Contract Review output per kontrak:
| Klausul | Status | Risk |
|---|---|---|
| Role Definition | ✅ Jelas | 🟢 |
| Security Measures | ⚠️ Generic | 🟡 |
| Breach Notification | ❌ "Reasonable" tanpa waktu | 🔴 |
| Sub-Processor | ⚠️ Notice 7 hari (lemah) | 🟡 |
| ...

Overall contract risk = max of individual klausul.

## AI Auto-Fill Prompt Pattern
User upload DPA PDF → system:
1. Text extract via pdfplumber atau VLM
2. Chunks ke 2000 token paragraph
3. Untuk setiap klausul di checklist, ask LLM:
   "Find clause about '{klausul}'. Return: present (y/n), excerpt, risk (high/med/low), recommended improvement."
4. Aggregate 10 checklist result → summary report

## Common Bad Clauses (Red Flag Library)
- "Force majeure excluding Processor responsibility for breach" — RED
- "Controller acknowledges no guarantee of data security" — RED
- "Processor may use data for its own analytics with anonymization" — RED (still data use)
- "Governing law: Cayman Islands" dengan subjek data Indonesia — RED (hard to enforce)
KB,
            ],
        ];
    }

    // ======================================================================
    // 9. POLICY REVIEW
    // ======================================================================
    private function policyReviewSections(): array
    {
        return [
            [
                'module_key' => 'policy_review_uu_pdp_mapping',
                'title' => 'Policy Review — Mapping ke UU PDP Pasal',
                'category' => 'library',
                'feature_tags' => 'policy_review,chat,remediation',
                'keywords' => 'policy review,kebijakan privasi,privacy policy,syarat ketentuan,t&c,sop,uu pdp mapping,gap policy',
                'summary' => 'Policy Review AI scan Privacy Policy / T&C / SOP terhadap UU PDP per-Pasal. Check 15 area: identitas pengendali, DPO, dasar hukum per aktivitas, hak subjek, cara withdraw, kontak DPO, retensi, transfer, dll.',
                'content' => <<<'KB'
# Policy Review — UU PDP Mapping Checklist

## Required Elements in Privacy Policy

### 1. Identitas Pengendali Data (Pasal 31)
- Nama resmi perusahaan
- Alamat kantor
- Email + telepon
- Legal entity type (PT, CV, Yayasan)

### 2. Data Protection Officer (Pasal 53)
- Nama DPO (kalau wajib appoint)
- Email DPO dedicated (dpo@company.com)
- Kalau DPaaS, nama konsultan + kontrak reference

### 3. Kategori Data yang Dikumpulkan (Pasal 16 — transparency)
- List per kategori (identitas, kontak, finansial, dll)
- Link ke definisi yang jelas
- Sumber data (subjek direct, scraped, partner)

### 4. Tujuan Pemrosesan (Pasal 16 — purpose limitation)
- Per kategori data, sebutkan tujuan
- Jangan generic "untuk meningkatkan layanan"

### 5. Dasar Hukum per Tujuan (Pasal 20)
- Pilih dari 6 enum
- Kalau Legitimate Interest, sebutkan interest-nya

### 6. Retensi Data (Pasal 16 — storage limitation)
- Durasi per kategori
- Trigger pemusnahan
- Legal basis retention (UU mana yang mewajibkan retain)

### 7. Penerima Data / 3rd Party (Pasal 31)
- List pihak ke-3 + kategori (vendor cloud, analytics, marketing)
- Transfer lintas batas + safeguard

### 8. Hak Subjek Data (Pasal 5-10)
- List 7 hak
- Cara exercise (email DPO, portal, form)
- Expected response time (72 jam)

### 9. Withdraw Consent Mechanism (Pasal 8)
- URL Preference Center
- Unsubscribe link di email
- Cookie editor
- Cara cabut consent via email DPO

### 10. Security Measures (Pasal 35-39)
- Technical: encryption, access control, backup
- Organizational: training, audit, policy
- High-level tidak perlu detail bikin roadmap attacker

### 11. Cookie Policy (Pasal 16 — transparency)
- Kategori cookie (essential, analytics, marketing)
- Cara opt-out
- 3rd party cookies listed

### 12. Children's Data (Pasal 26 Permenkominfo 20/2016)
- Kalau layanan bisa diakses minor, butuh age-gate + parental consent
- Kalau TIDAK melayani minor, state it

### 13. Cross-Border Transfer (Pasal 56)
- List negara tujuan
- Safeguard mechanism
- Cara subjek object

### 14. Breach Notification (Pasal 46)
- Commitment notify subjek dalam 72h
- Channel notifikasi (email, SMS, web banner)

### 15. Policy Update Mechanism
- Versioning
- Notification channel saat ada perubahan material
- Effective date each version

## Common Gaps (High Frequency)
1. ❌ Tidak sebut DPO contact
2. ❌ Retensi "sesuai kebutuhan" (tidak spesifik)
3. ❌ Dasar hukum generic (tidak per tujuan)
4. ❌ Withdraw mechanism tidak jelas
5. ❌ List 3rd party tidak lengkap
6. ❌ Cross-border transfer tidak disebut walau pakai cloud US
7. ❌ Children's data policy absent
8. ❌ Security measures terlalu vague atau terlalu detail

## AI Policy Review Prompt Pattern
```
Policy text: [INPUT]

Cek compliance terhadap UU PDP per-Pasal:
1. Pasal 31 identitas pengendali — present? complete?
2. Pasal 53 DPO — disebutkan? kontak dedicated?
3. Pasal 20 dasar hukum — per aktivitas? specific enum?
... (15 checks)

Return JSON:
{
  "overall_compliance": 0-100,
  "gaps": [
    {"area": "DPO contact", "severity": "high", "recommendation": "..."}
  ],
  "strengths": [...]
}
```
KB,
            ],
        ];
    }

    // ======================================================================
    // 10. VENDOR
    // ======================================================================
    private function vendorSections(): array
    {
        return [
            [
                'module_key' => 'vendor_assessment_questionnaire',
                'title' => 'Vendor Assessment — 50+ Question Library',
                'category' => 'library',
                'feature_tags' => 'vendor_screening,contract_review,chat',
                'keywords' => 'vendor,tprm,third party,assessment,questionnaire,pertanyaan,risk scoring,dpa,soc2,iso 27001',
                'summary' => 'Library 50+ pertanyaan assessment vendor — security, privacy, compliance, financial stability, operational. Scoring otomatis: High/Medium/Low risk. Dipakai TPRM module saat onboard vendor baru.',
                'content' => <<<'KB'
# Vendor Risk Assessment — Question Library

## A. Security (15 questions)
1. ISO 27001 certified? (cert ID + expiry)
2. SOC 2 Type II certified? (latest report date)
3. Penetration test last performed? (scope + findings summary)
4. Encryption at-rest (algorithm)?
5. Encryption in-transit (TLS version)?
6. MFA enforced for all internal staff?
7. SSO integration available?
8. DDoS mitigation in place?
9. WAF deployed?
10. EDR / SOC monitoring 24/7?
11. Vulnerability management cadence?
12. Patch SLA for critical (days)?
13. Security training frequency?
14. Phishing awareness program?
15. Background check for employees handling data?

## B. Privacy (10 questions)
16. DPA template available for review?
17. GDPR compliant? (for EU customers)
18. UU PDP Indonesia compliant?
19. DPO appointed? (name + contact)
20. RoPA maintained for vendor's own processing?
21. Sub-processor list disclosed?
22. Cross-border transfer safeguard (SCC/BCR/adequacy)?
23. Data retention policy documented?
24. Data deletion SLA post-termination?
25. Privacy breach notification SLA to customer?

## C. Compliance (8 questions)
26. PCI DSS (if handles card data)?
27. HIPAA (if handles health)?
28. Industry certifications (POJK, KOMDIGI, etc)?
29. Regulatory penalty history (past 3 years)?
30. Audit rights granted in contract?
31. Sub-contracts transparency?
32. Data localization compliance?
33. Export control compliance?

## D. Operational (10 questions)
34. Availability SLA? (99.5%, 99.9%, etc)
35. RTO / RPO targets?
36. Disaster recovery plan tested?
37. Business continuity plan?
38. Incident response team structure?
39. Customer notification channel for incidents?
40. Support hours + language?
41. Service credits for SLA breach?
42. Insurance coverage (cyber, liability)?
43. Financial stability (D&B rating, audited financials)?

## E. Jurisdictional (5 questions)
44. Primary data center location?
45. Backup data center location?
46. Legal jurisdiction for disputes?
47. Government access request policy?
48. Transparency report published?

## F. Vendor Relationship (2 questions)
49. How many similar customers (reference)?
50. Willing to sign DPA + SCC upon onboarding?

## Scoring Rubric
- **Critical** (must-have): Q1, Q2, Q4, Q16, Q17, Q18, Q33, Q42
- **High**: Q3, Q6, Q19, Q20, Q22, Q23, Q25, Q30, Q35, Q36
- **Medium**: most others
- **Nice-to-have**: Q48, Q49

Score per question:
- Yes with evidence = 2
- Yes tanpa evidence = 1
- No = 0
- N/A = skip (don't factor)

## Risk Tier Assignment
- **Green** (Low Risk): Critical all Yes + total >80% max
- **Yellow** (Medium): 1-2 Critical No + total 60-80%
- **Red** (High): 3+ Critical No OR total <60%

## Reassessment Cadence
- Green: 12 bulan
- Yellow: 6 bulan
- Red: 3 bulan atau auto-terminate contract
KB,
            ],
        ];
    }

    // ======================================================================
    // 11. DATA DISCOVERY / PII
    // ======================================================================
    private function dataDiscoverySections(): array
    {
        return [
            [
                'module_key' => 'pii_indonesia_patterns',
                'title' => 'PII Detection — Indonesian Patterns Library',
                'category' => 'library',
                'feature_tags' => 'pii_scan,data_discovery,ropa_autofill',
                'keywords' => 'pii indonesia,nik,npwp,ktp,sim,paspor,rekening bank,bpjs,regex,pattern,klasifikasi',
                'summary' => 'Library pattern PII spesifik Indonesia untuk PII Detector service — regex, context clues, validation rules. NIK, NPWP, KK, SIM, paspor, rekening per-bank, BPJS, NIP, NRP, dll. Dipakai saat Data Discovery scan schema.',
                'content' => <<<'KB'
# PII Indonesia — Detection Patterns

## 1. NIK (Nomor Induk Kependudukan)
- **Format**: 16 digit
- **Struktur**: [PPKKCC][DDMMYY][NNNN]
  - PP = kode provinsi
  - KK = kode kabupaten
  - CC = kode kecamatan
  - DDMMYY = tanggal lahir (DD+40 untuk perempuan)
  - NNNN = nomor urut
- **Regex**: `^\d{16}$`
- **Validation**: kode provinsi harus valid (11-94)
- **Context clues**: "nik", "nomor ktp", "identity number", "no_identitas"

## 2. NPWP
- **Format**: 15 digit (baru 2024: 16 digit NIK-based)
- **Formatted**: `XX.XXX.XXX.X-XXX.XXX`
- **Regex raw**: `^\d{15,16}$`
- **Regex formatted**: `^\d{2}\.\d{3}\.\d{3}\.\d-\d{3}\.\d{3}$`
- **Context**: "npwp", "tax id", "no_pajak"

## 3. KK (Kartu Keluarga)
- **Format**: 16 digit (sama NIK tapi identifier keluarga)
- **Regex**: `^\d{16}$`
- **Context**: "kk", "no_kk", "kartu_keluarga", "nomor_kk"
- **Distinguish dari NIK**: biasanya per-kolom terpisah di DB

## 4. SIM (Surat Izin Mengemudi)
- **Format**: 12 digit alphanumeric
- **Pattern**: `^\d{12}$`
- **Context**: "sim", "driving_license"

## 5. Paspor
- **Format**: 1 huruf + 7-8 digit
- **Regex**: `^[A-Z]\d{7,8}$`
- **Context**: "paspor", "passport_no"

## 6. Rekening Bank
### BCA
- Format: 10 digit
- Regex: `^\d{10}$`
- Prefix umum: 6xxx-7xxx-8xxx
### Mandiri
- Format: 13 digit
- Regex: `^\d{13}$`
### BRI
- Format: 15 digit
- Regex: `^\d{15}$`
### BNI
- Format: 10 digit
- Regex: `^\d{10}$`

**Context**: "rekening", "account_no", "no_rek"

## 7. BPJS
- **Kesehatan**: 13 digit (prefix 00)
- **Ketenagakerjaan**: 11 digit
- **Regex**: `^\d{11,13}$`
- **Context**: "bpjs", "asuransi_kesehatan"

## 8. NIP (Nomor Induk Pegawai — PNS)
- **Format**: 18 digit (ASN baru)
- **Pattern**: YYYYMMDDYYYYMMSNNN
- **Context**: "nip", "pns"

## 9. NRP (Nomor Registrasi Pokok — TNI/Polri)
- **Format**: 8-10 digit
- **Context**: "nrp", "prajurit"

## 10. Telepon Indonesia
- **Format**: +62 / 0 diikuti kode operator
- **Mobile**: `^(\+62|62|0)8[0-9]{8,12}$`
- **Landline**: `^(\+62|62|0)[2-9][0-9]{6,10}$`

## 11. Email
- Standard RFC 5322
- Context: "email", "mail"

## 12. Alamat
- Detect: mengandung kata "Jl.", "Jalan", "RT", "RW", "Kel.", "Kec.", kode pos 5 digit
- Hard to regex — gunakan NER model

## 13. Tanggal Lahir
- Format umum: `DD/MM/YYYY`, `DD-MM-YYYY`, `YYYY-MM-DD`
- Context: "dob", "tgl_lahir", "birth_date"

## 14. Data Kesehatan
- ICD-10 codes: `^[A-Z]\d{2}(\.\d{1,2})?$`
- Obat nama: matching against Indonesian drug database (BPOM)
- Context: "diagnosis", "icd", "medical_record", "resep"

## 15. Biometrik
- **Fingerprint hash**: base64 string 44+ char
- **Face embedding**: float array 128-512 dim
- Context: "fingerprint", "face_encoding", "iris_scan", "biometric_*"

## Confidence Scoring
PII Detector return confidence per kolom:
- **100%**: regex match + context clue + sample validation (mis. NIK check sum provinsi)
- **90%**: regex + context clue
- **70%**: regex only
- **50%**: context clue only (column name "nik" tapi data test)
- **<50%**: skip classification

## Sample Size
- Scan minimal **100 baris** per kolom untuk statistical confidence
- Kalau null rate >50%, skip (kemungkinan optional field)
- Kalau unique count = 1, skip (kemungkinan default value)
KB,
            ],
        ];
    }

    // ======================================================================
    // 12. REMEDIATION PATTERNS
    // ======================================================================
    private function remediationSections(): array
    {
        return [
            [
                'module_key' => 'remediation_priority_framework',
                'title' => 'Remediation Plan — Priority Framework',
                'category' => 'workflow',
                'feature_tags' => 'remediation,chat',
                'keywords' => 'remediation,roadmap,prioritas,effort,pic,action plan,gap,fix,perbaikan',
                'summary' => 'Framework prioritisasi remediation dari GAP Assessment: scoring impact × ease × urgency. Kategori P0 (legal/critical, <7 hari), P1 (high risk, <30 hari), P2 (medium, <90 hari), P3 (nice-to-have, <180 hari).',
                'content' => <<<'KB'
# Remediation Plan — Priority Framework

## Dimensi Scoring
Setiap gap di-score dalam 3 dimensi:

### 1. Impact (1-5)
- 5 = Legal violation langsung (Pasal UU PDP dilanggar clear)
- 4 = Major compliance gap (audit failure)
- 3 = Operational risk tinggi
- 2 = Reputational risk
- 1 = Nice-to-have

### 2. Ease of Fix (1-5, reverse)
- 5 = Quick win, <1 hari (kebijakan tertulis)
- 4 = Small effort <1 minggu (config change)
- 3 = Medium <1 bulan (process redesign)
- 2 = Big <3 bulan (system integration)
- 1 = Huge >3 bulan (tooling replacement)

### 3. Urgency (1-5)
- 5 = Audit coming up, tender requirement
- 4 = Breach risk imminent
- 3 = Quarterly deadline
- 2 = Yearly roadmap
- 1 = No deadline

## Priority Formula
```
Priority Score = Impact × Ease × Urgency
```

Range: 1-125.

## Priority Tier
- **P0 Critical** (score ≥75): <7 hari, escalate Direksi
- **P1 High** (score 40-74): <30 hari
- **P2 Medium** (score 20-39): <90 hari
- **P3 Low** (score <20): <180 hari atau backlog

## Action Template
Untuk setiap gap, AI generate:
```markdown
### Gap: [Question dari GAP]
**Current State**: [Belum/Sebagian + narasi]
**Target State**: [Sudah, specific + measurable]
**Priority**: P[0-3]
**Regulatory Reference**: [Pasal UU PDP]
**Impact if Not Fixed**: [consequence]

**Action Steps**:
1. [Step 1 - specific, actionable]
2. [Step 2]
3. [Step 3]

**PIC Recommended**: [DPO / Legal / IT Security / HR]
**Estimated Effort**: [hours/days]
**Success Criteria**: [measurable outcome]
**Evidence Required**: [document type]
```

## Common Remediation Patterns

### Gap: "Belum ada DPO"
- P0 Critical
- Action: (1) Assess apakah DPO wajib (Pasal 53), (2) Kalau ya, appoint internal atau hire DPaaS, (3) Public notice DPO contact, (4) Register ke KOMDIGI
- PIC: Direksi + HR
- Effort: 2-4 minggu hire + onboard
- Evidence: SK Direksi, email DPO aktif, website privacy page updated

### Gap: "Retention policy tidak ada"
- P1 High
- Action: (1) Inventory aktivitas pemrosesan, (2) Riset UU retention per jenis data, (3) Draft policy, (4) Approval Direksi, (5) Publikasi, (6) Implement automated purge
- PIC: DPO + Legal + IT
- Effort: 1-3 bulan
- Evidence: Policy document signed, purge job script

### Gap: "Karyawan belum pelatihan PDP"
- P1 High
- Action: (1) Design curriculum 1-day, (2) Sourcing trainer (internal/konsultan), (3) Schedule rollout per batch, (4) Record attendance, (5) Post-test
- PIC: HR + DPO
- Effort: 2-4 minggu design + rolling rollout
- Evidence: Attendance list, test scores, certificate

### Gap: "Encryption at-rest belum"
- P1 High
- Action: (1) Identify DB/storage yang blum encrypted, (2) Backup existing, (3) Enable encryption (TDE for SQL, LUKS for file), (4) Verify, (5) Document
- PIC: IT Security
- Effort: 1-2 minggu per DB
- Evidence: Config export, encryption key rotation policy

### Gap: "DPA tidak ada untuk vendor cloud"
- P0 Critical
- Action: (1) List vendor yang handle PII, (2) Contact vendor untuk DPA template, (3) Review + negotiate, (4) Signed by legal, (5) Register di TPRM module
- PIC: Legal + DPO
- Effort: 1-3 bulan per vendor
- Evidence: Signed DPA PDFs

## Anti-Patterns
- "Hire consultant" as action — too generic
- No PIC assignment
- Effort "TBD" — estimate wajib
- Regulatory reference hilang
- Success criteria subjektif ("improve compliance") bukan measurable
KB,
            ],
        ];
    }

    // ======================================================================
    // 13. FEATURE FLOWS + SALES FAQ
    //
    // Sections ini untuk jawab pertanyaan "bagaimana cara kerja", "berapa
    // lama", "apa keunggulan", "apa beda", "bagaimana flow". Dipakai oleh
    // AI Agent untuk sales demo + technical questions.
    // ======================================================================
    private function flowsAndSalesSections(): array
    {
        return [
            // -------------------------------------------------------------
            // Data Discovery — flow, advantages, duration, views
            // -------------------------------------------------------------
            [
                'module_key' => 'data_discovery_scan_method',
                'title' => 'Data Discovery — Metode Scanning',
                'category' => 'workflow',
                'feature_tags' => 'chat,data_discovery,pii_scan,sales_faq',
                'keywords' => 'data discovery,scan,scanning,metode,cara kerja,flow,langkah,proses,mapping,pii detection,klasifikasi,deteksi,database,schema,csv,cloud storage,s3,minio',
                'summary' => 'Data Discovery Privasimu scan schema + sample data di Information System klien (MySQL/PostgreSQL/MongoDB/MSSQL/Oracle/cloud storage). Dua mode: Live Scan (connect langsung) atau Generate-Only (admin register schema manual). PII Detector + AI classification auto-tag kolom sensitive.',
                'content' => <<<'KB'
# Data Discovery — Metode Scanning Privasimu Nexus

## Dua Mode Operasi

### Mode 1: Live Scan (Self-hosted / SaaS trusted)
Platform connect langsung ke database klien dengan credential terenkripsi (AES-256-CBC). Langkah:

1. **Register Information System** → masukkan connection config (host, port, db name, credential terenkripsi)
2. **Test Connection** → platform ping + list schema
3. **Schema Discovery** → enumerate tabel + kolom via `INFORMATION_SCHEMA` atau equivalent
4. **Sample Scan** → ambil 100-1000 baris per tabel untuk pattern detection (tidak bulk download)
5. **PII Classification** → `PiiDetector` service scan value via regex + context clues
6. **Statistical Confidence** → kolom dengan >80% match pattern → flag sebagai PII-bearing
7. **AI Classification (AI view)** → LLM analyze column name + sample untuk tentukan kategori (NIK, NPWP, email, biometrik, kesehatan, dll) + assign risk level
8. **Save to Data Catalog** → store metadata ke tabel `schema_tables` + `schema_columns`

### Mode 2: Generate-Only (Bank / Fintech ketat)
Klien tidak izinkan third-party connect ke prod DB. Flow:

1. **Register Information System** dengan `generate_only=true` flag (tanpa credential)
2. **Import Schema Manual**:
   - Upload DDL (`CREATE TABLE ...`)
   - Upload CSV schema definition
   - Input manual via UI
3. **Admin annotate** kolom per kolom (mark PII-bearing, kategori)
4. **Platform zero koneksi** ke DB klien — semua metadata
5. DSR flow pakai **SQL Generator** (platform generate SELECT/UPDATE/DELETE, admin eksekusi sendiri)

## Supported Source Types
- **Relational DB**: MySQL 5.7+, PostgreSQL 13+, MSSQL 2016+, Oracle 19+
- **NoSQL**: MongoDB 5.0+
- **Cloud Storage**: AWS S3, MinIO, Google Cloud Storage, Azure Blob
- **SaaS**: via API connector (Salesforce, HubSpot — Phase 2)
- **File upload**: CSV, Excel, DDL script

## Durasi Scanning
| Size Database | Mode | Estimated Time |
|---|---|---|
| Small (<10 tabel, <1M rows) | Live | 5-15 menit |
| Medium (10-100 tabel, <100M rows) | Live | 30 menit - 2 jam |
| Large (>100 tabel, >100M rows) | Live | 2-8 jam (off-peak recommended) |
| Sharded multi-DB | Live parallel | Paralel per-shard + konsolidasi |
| Any size | Generate-only | Tergantung kecepatan admin input (1-3 hari untuk 50 tabel) |

Scan tidak blocking — incremental. Admin bisa pause + resume. Progress tracker real-time di UI.

## Split / Sharded Database Support
1 logical system = N physical shards. Contoh: bank dengan 30 juta nasabah dipartisi ke 4 server.

Register via `is_sharded=true` + `shards[]` array:
```json
{
  "name": "Core Banking",
  "is_sharded": true,
  "shards": [
    {"name": "shard_01", "note": "APAC region, customer 1-10M"},
    {"name": "shard_02", "note": "customer 10-20M"},
    {"name": "shard_03", "note": "customer 20-30M"},
    {"name": "shard_04", "note": "customer 30M+"}
  ]
}
```

Scan dijalankan paralel per shard. DSR SQL Generator keluarkan file SQL terpisah per shard.

## Standard View vs AI View

### Standard View (Rule-Based)
- Pattern matching via regex library (NIK, NPWP, email, telepon)
- Context clue via column name (kolom `nik` → NIK, kolom `email` → email)
- Confidence score 0-100%
- Fast, deterministic, no LLM cost
- Bisa run offline, no internet dependency
- **Cocok untuk**: high-volume batch scan, cost-sensitive, air-gapped deployment

### AI View (LLM-Powered)
- bge-m3 embedding semantic classification
- LLM reason about ambiguous column names (e.g. `cust_ref` apakah itu PII?)
- Handle kolom bahasa non-Inggris / typo
- Suggest kategori yang tidak ada di rule-based (mis. data agama dari field `religion_code`)
- Generate human-readable description per kolom
- Risk assessment via LLM (high-sensitivity alert)
- **Cocok untuk**: kompleks schema, tenant dengan konvensi nama kolom unik, high-accuracy requirement

### Best Practice: Dual View
Jalankan Standard View dulu (fast, cheap) → flagging obvious PII. Run AI View second pass on uncertain columns (confidence < 80%) untuk second opinion.

## Changelog & Drift Detection
Setiap scan bikin snapshot schema. Antar scan, platform detect:
- Tabel baru yang muncul
- Tabel yang dihapus
- Kolom baru yang di-add
- Type change (mis. `VARCHAR(50)` → `TEXT`)
- PII classification change (previously not-PII, now detected as NIK)

Alert ke DPO kalau drift signifikan — potensi RoPA perlu di-update.

## Integration Output
Hasil Data Discovery auto-sync ke:
- **RoPA Section 4 & 5**: "jenis data dikumpulkan" + "sistem informasi" otomatis populate
- **DPIA Category 2 (Minimisasi)**: list kolom redundant untuk remediation
- **DSR Scope Picker**: subjek request scope-able per Information System
- **Posture Score**: contribute ke overall compliance metric
KB,
            ],

            [
                'module_key' => 'data_discovery_advantages',
                'title' => 'Data Discovery — Keunggulan Privasimu vs Competitor',
                'category' => 'example',
                'feature_tags' => 'chat,sales_faq,data_discovery',
                'keywords' => 'keunggulan,advantage,competitor,kompetitor,bedanya,unggul,onetrust,bigid,varonis,microsoft purview,benefit,value proposition,usp,differensiasi',
                'summary' => 'Keunggulan Data Discovery Privasimu: (1) dua mode live/generate-only khusus bank, (2) sharded DB support, (3) AI classification dengan konteks UU PDP, (4) native integration RoPA/DPIA/DSR, (5) on-prem deployment, (6) value proposition khusus konteks Indonesia vs OneTrust/BigID.',
                'content' => <<<'KB'
# Keunggulan Data Discovery Privasimu

## vs OneTrust / BigID / Varonis / Microsoft Purview

### 1. Dua Mode Operasi (UNIQUE)
Kompetitor semua **butuh live connection** ke DB klien. Bank top-tier Indonesia umumnya tolak ini karena:
- Compliance POJK — no third-party access ke production DB
- Risk IP banned / firewall policy
- Audit concern — foreign process running query di core banking

**Privasimu "Generate-Only Mode"** = platform zero connection, admin register schema manual. Pure SQL generator untuk DSR.

**Nilai jual ke bank**: "Kami tidak pernah akses data produksi Anda. Credential DB tidak pernah disimpan. Audit-clean, legally defensible."

### 2. Sharded Database Support (UNIQUE for Indo market)
30 juta nasabah bank biasanya dipartisi 4-10 server. Kompetitor asing tidak punya mental model buat ini.

**Privasimu** native support shards array di metadata. DSR keluarkan SQL terpisah per shard. Scan paralel.

### 3. AI Classification Konteks UU PDP
Kompetitor generic (GDPR-focused). Privasimu trained ke:
- UU PDP Pasal 4 kategori data spesifik (agama, orientasi, dll — Indonesian-specific)
- PII Indonesia patterns (NIK 16 digit, NPWP format, BPJS, rekening per-bank)
- Industry-specific patterns (banking CIF, healthcare BPJS, fintech loan ID)

### 4. Native Integration RoPA/DPIA/DSR
Kompetitor modular — data discovery jual terpisah dari GRC platform. Integrasi butuh kustom.

**Privasimu one-platform**:
- Data Discovery → auto-fill RoPA Section 4-5
- Data Discovery → feed DPIA Category "Minimisasi Data"
- Data Discovery → DSR scope picker native
- Cross-linkage otomatis, no integration project

### 5. On-Prem Deployment
OneTrust/BigID cloud-only atau hybrid-limited. Bank Indonesia sering wajib on-prem.

**Privasimu** bisa deploy fully self-hosted (Docker + Kubernetes). Termasuk stack AI on-prem (L40S 48GB atau H100 80GB) = air-gap compliant.

### 6. Value Proposition untuk Pasar Indonesia
Fitur setara dengan kompetitor global, tapi lebih cocok konteks regulasi Indonesia (UU PDP, POJK, KOMDIGI) + operational nuance lokal (sharded bank DB, air-gap compliance).

**Detail pricing tidak dibahas di sini** — hubungi tim sales Privasimu untuk proposal custom sesuai skala tenant + requirement klien.

### 7. Bahasa Indonesia Native
UI + dokumentasi + AI response semua Indonesia. Tim compliance lokal onboarding 5x lebih cepat.

### 8. White-Label Ready
Bank / BUMN yang mau rebrand ke "ABC Privacy Manager" bisa. Template dokumen, palet warna, logo, email sender semua per-tenant customizable.

### 9. Holding Support
Struktur induk-anak perusahaan dengan cross-tenant aggregated dashboard. Kompetitor asing treat setiap subsidiary independent — butuh multi-license.

### 10. AI Agent Compliance Assistant
Chat bot dengan function calling — langsung create RoPA, update DPIA, trigger breach workflow dari natural language. Kompetitor masih stuck di manual click-based.
KB,
            ],

            [
                'module_key' => 'data_discovery_standard_vs_ai_view',
                'title' => 'Data Discovery — Standard View vs AI View Comparison',
                'category' => 'example',
                'feature_tags' => 'chat,sales_faq,data_discovery',
                'keywords' => 'standard view,ai view,perbedaan,beda,compare,regex,rule based,llm,bge-m3,klasifikasi,confidence,semantic',
                'summary' => 'Standard View = rule-based classification (regex + column name). AI View = LLM-powered semantic understanding. Standard: cepat, murah, deterministic. AI: akurat untuk ambiguous kolom, handle non-Inggris, kasih explanation. Best: dual-run — Standard first, AI second pass untuk uncertain.',
                'content' => <<<'KB'
# Standard View vs AI View — Data Discovery

## Standard View (Rule-Based)

### Cara Kerja
1. Platform enumerate tabel + kolom
2. Untuk setiap kolom, cek:
   - **Column name match**: `email`, `nik`, `telepon` → direct match
   - **Regex match** pada 100-1000 sample value: NIK 16 digit, NPWP format, email RFC 5322
   - **Statistical confidence**: >80% value match pattern = flag

### Output
- Kategori: NIK / NPWP / Email / Telepon / Alamat / etc
- Confidence: 0-100%
- Source: "pattern" / "column_name" / "combined"

### Keunggulan
- ⚡ **Super cepat** — 1000 kolom dalam <30 detik
- 💰 **Zero cost** — no LLM API call
- 🔒 **Deterministic** — input sama = output sama, auditable
- 📴 **Offline-capable** — air-gap safe
- 🎯 **Precise untuk pattern jelas** — NIK pasti 16 digit, tidak ambigu

### Limitasi
- ❌ Tidak handle kolom dengan nama aneh: `cust_ref_v2`, `x_data_1`, `legacy_col`
- ❌ Tidak handle bahasa non-Inggris kalau rule library Inggris: kolom `namaLengkap` mungkin miss
- ❌ Tidak detect data dengan format non-standar: NIK disimpan dengan space / dash
- ❌ Tidak kasih explanation / reasoning

## AI View (LLM-Powered)

### Cara Kerja
1. Platform enumerate kolom (sama seperti Standard)
2. Untuk kolom uncertain (confidence < 80%) atau all columns (opsional):
   - Kirim ke LLM: kolom name + sample 10-20 value + table context + surrounding columns
   - LLM prompt: "Apakah kolom ini berisi PII? Kalau ya, kategori apa? Reasoning?"
   - LLM output: kategori + confidence + explanation

### Output
- Kategori (termasuk kategori non-regex: agama, orientasi, politik)
- Confidence
- **Explanation**: "Kolom `rel_code` berisi value A/B/C/D — dari sample context (kolom sebelah 'religion_name' = Islam/Kristen/Hindu/Budha), ini kemungkinan data agama dengan kode"
- Risk assessment UU PDP: flag sebagai data sensitif → trigger RoPA HIGH risk

### Keunggulan
- 🧠 **Semantic understanding** — tahu `cust_ref` mungkin customer ID → PII
- 🌐 **Multilingual** — kolom `namaLengkap`, `nomorTelp`, Chinese column names handled
- 📖 **Explainable** — DPO bisa audit reasoning LLM
- 🎯 **Context-aware** — lihat tabel + kolom sekitar untuk tentukan konteks
- 🎓 **UU PDP aware** — tagged kategori spesifik Indonesia (biometrik, agama, anak) untuk risk assessment
- 🔄 **Improving** — model update → classification quality naik tanpa rule engineering manual

### Limitasi
- ⏱️ **Slower** — 1-2 detik per kolom (vs milisecond Standard)
- 💰 **API cost** — token LLM per column scan (small, tapi ada)
- 🎲 **Non-deterministic** — confidence 88% run sekarang, 85% run 5 menit lagi (margin small)
- 🔌 **Butuh stack AI** — on-prem Qwen/embedding running atau cloud API

## Comparison Table

| Aspek | Standard View | AI View |
|---|---|---|
| **Speed per 1000 kolom** | <30 detik | 30-60 menit |
| **Cost per scan** | Zero (local compute) | Tergantung volume token LLM — biasanya minor ops cost |
| **Accuracy obvious PII** | 95% | 98% |
| **Accuracy ambiguous kolom** | 50% | 90% |
| **Explainability** | Rule triggered | Full reasoning |
| **Offline** | ✅ | ⚠️ kalau stack on-prem ada |
| **Multi-language** | Terbatas | Excellent |
| **Deterministic** | ✅ | ⚠️ confidence varies ±5% |

## Best Practice: Dual-Run
1. **First pass**: Standard View — fast, cheap, cover 80-90% pasti
2. **Second pass**: AI View pada kolom confidence <80% — targeted, cost-controlled
3. **DPO review**: override kalau perlu, feedback loop untuk improve

## Kapan Pilih Standard Only?
- Batch scan harian volume besar (>10k kolom per run)
- Budget sangat tight
- Air-gap deployment tanpa AI stack
- Data konvensi naming sangat konsisten (bank legacy yang strict)

## Kapan Pilih AI View?
- Schema modern / eksperimental naming
- Multi-language (Indo + Inggris + Chinese)
- Tenant tidak yakin field apa PII (minta AI kasih opinion)
- One-off deep audit untuk compliance report

## Kapan Pilih Dual-Run?
- Production standard — balance antara cost dan accuracy
- Enterprise tier klien — deliverable quality tinggi
- **Recommended default Privasimu**
KB,
            ],

            // -------------------------------------------------------------
            // DSR SQL Generator walkthrough
            // -------------------------------------------------------------
            [
                'module_key' => 'dsr_sql_generator_walkthrough',
                'title' => 'DSR — SQL Generator Walkthrough (DSAR Dengan SQL)',
                'category' => 'workflow',
                'feature_tags' => 'dsr,chat,sales_faq,data_discovery',
                'keywords' => 'dsar,dsr,sql generator,squell,sql,cari data,search,deletion,access,correction,eksekusi,generate,pack,file sql,zip,shard',
                'summary' => 'DSAR/DSR pakai SQL Generator Privasimu: platform generate SELECT/UPDATE/DELETE berdasar schema registry, admin bank eksekusi di DB mereka (platform ZERO execution di prod data klien). Output: .sql file per shard, .zip pack lengkap + README. Safety header wajib + rows_affected tracking.',
                'content' => <<<'KB'
# DSR SQL Generator — Walkthrough

## Kenapa SQL Generator?
Bank / BUMN / instansi pemerintah umumnya **tidak mengizinkan third-party platform eksekusi langsung ke production DB**. Alasan:
- Compliance internal
- Risk credential leak
- Audit requirement — semua query tercatat di log DB klien
- Legal boundary — jelas Privasimu "tool provider", bukan "data processor"

Solusi: Privasimu **generate SQL pack**, admin klien eksekusi sendiri, balik upload bukti + rows_affected.

## Flow Lengkap

### Step 1: Permintaan DSR Masuk
Subjek submit request via:
- Embed form di website klien
- Portal langsung Privasimu
- Email ke inbox DPO

Sistem auto-create DSR record dengan deadline T+72h (Pasal 32 UU PDP).

### Step 2: Identity Verification
OTP email ke subjek. Kalau verify → lanjut. Kalau tidak verify dalam 24 jam → auto-reject.

### Step 3: Scope Selection
DPO pilih **Information System mana** yang terdampak dari request. Contoh:
- Request: "Hapus akun saya di aplikasi mobile banking"
- Scope: Core Banking + Mobile Banking Platform + Analytics Warehouse (3 system)

Kalau system sharded, semua shard dalam system itu auto-included.

### Step 4: Mode Selection
Pilih dari 7 tipe request:
- **Access** → SELECT
- **Correction** → UPDATE per field
- **Deletion** → DELETE / UPDATE (anonymize)
- **Portability** → SELECT + JSON export format
- **Withdraw** → UPDATE consent flag
- **Objection** → UPDATE stop processing flag
- **Information** → metadata report only (no SQL)

### Step 5: Generate SQL Pack
Platform backend (`DsrSqlGeneratorService`):
1. Read Schema Registry untuk system yang dipilih
2. Identify kolom PII-bearing
3. Generate SQL statement per-table per-shard:

```sql
-- ⚠️  GENERATED BY PRIVASIMU NEXUS — Review before executing
-- DSR: DSR-2026-042 | Mode: deletion | System: Core Banking | Shard: shard_01
-- Subject: {hash_identifier} | Generated: 2026-04-25 14:22:00 WIB
-- Estimated rows affected: 127 (based on scan sample)
-- REVIEW: verify WHERE clause matches your CIF convention

BEGIN;

-- Anonymize PII columns (tombstone approach)
UPDATE customers
SET nik = NULL,
    email = NULL,
    telepon = NULL,
    alamat = '[DELETED]',
    nama_lengkap = SHA2(CONCAT('DEL-', id, '-', UUID()), 256)
WHERE cif = '1234567890';

UPDATE transactions
SET subject_identifier = NULL,
    description = REGEXP_REPLACE(description, '[A-Za-z0-9._%+-]+@', '[email]@')
WHERE cif = '1234567890';

DELETE FROM customer_sessions
WHERE cif = '1234567890';

DELETE FROM marketing_preferences
WHERE cif = '1234567890';

COMMIT;

-- Expected rows affected: 127
-- If satisfied, report execution back to Privasimu Nexus DSR log.
```

### Step 6: Download Pack
Output struktur:
```
DSR-2026-042-SQLPack.zip
├── README.md                                   ← instruksi eksekusi untuk admin
├── 01_SYS01_Core_Banking_shard_01__deletion.sql
├── 02_SYS01_Core_Banking_shard_02__deletion.sql
├── 03_SYS01_Core_Banking_shard_03__deletion.sql
├── 04_SYS01_Core_Banking_shard_04__deletion.sql
├── 05_SYS02_Mobile_Banking__deletion.sql
└── 06_SYS03_Analytics_Warehouse__deletion.sql
```

README.md isi:
- Deadline reminder (T+Xh remaining)
- Urutan eksekusi yang recommended (isolated shards dulu, then aggregate)
- Rollback strategy
- Contact Privasimu engineering kalau error

### Step 7: Admin Eksekusi di Platform Klien
Admin bank:
1. Login ke internal DB management tool (pgAdmin, MySQL Workbench, dll)
2. Open .sql file
3. **Review manual** — cek WHERE clause, identifier match
4. Eksekusi query
5. Catat rows_affected dari output

### Step 8: Upload Bukti ke Privasimu
Admin kembali ke Privasimu DSR module:
1. Per file SQL, klik "Mark as Executed"
2. Input `rows_affected` (angka dari DB output)
3. Upload bukti: screenshot DB output, export audit log, atau file backup
4. Notes (opsional): "Shard 03 error pada table `legacy_ledger`, di-retry manual untuk 23 row"
5. Status berubah `executed` atau `failed` atau `partial`

### Step 9: DSR Completion
Kalau **semua** DsrSqlExecution status `executed/skipped`:
- DSR overall status → `completed`
- Auto-generate **Certificate of Completion** PDF signed by DPO
- Email subjek: "Permintaan Anda telah diproses. Lampiran: Completion Certificate"
- Close DSR record

## Safety Features

### Dry-Run Preview
Sebelum eksekusi, admin bisa generate **preview-only** variant:
```sql
-- DRY-RUN: query SELECT untuk validate scope SEBELUM DELETE
SELECT COUNT(*) as affected_customers FROM customers WHERE cif = '1234567890';
SELECT COUNT(*) as affected_transactions FROM transactions WHERE cif = '1234567890';
```

### Transaction Wrapping
Setiap SQL file wrapped dalam `BEGIN; ... COMMIT;` — atomic. Kalau error di tengah, bisa ROLLBACK full.

### Warning Comments
Platform auto-inject warning di SQL:
- "WARNING: DELETE cascading ke N child table — review foreign key"
- "WARNING: Kolom ini referenced dari backup — retention policy conflict"
- "WARNING: Customer masih ada active loan — deletion mungkin melanggar UU TPPU"

### Audit Log
Setiap generate + execution tracked di `audit_logs`:
- Siapa generate SQL (userid + timestamp)
- Siapa eksekusi (input dari admin)
- Rows affected
- Evidence file_id
- Full cycle traceable untuk audit

## Common Questions

### "Apakah platform Privasimu bisa TIDAK SENGAJA eksekusi SQL?"
**TIDAK.** Platform tidak punya credential DB klien. Physically impossible untuk eksekusi.

### "Kalau admin lupa eksekusi, bagaimana?"
SLA timer aktif — alert di T+48h ke DPO. Di T+60h alert Direksi. Di T+72h pelanggaran terlog.

### "Bagaimana kalau SQL tidak jalan di DB klien (syntax incompatible)?"
Platform generate dengan ANSI SQL + varian MySQL, PostgreSQL, MSSQL. Admin pilih dialect saat generate. Fallback: modify manual + catat di notes.

### "Sharded DB dengan 4 server — harus login 4 kali?"
Ya, satu file .sql per shard. Tapi Privasimu kasih instruksi parallel execution di README. Admin bisa pakai script untuk auto-loop.

### "Bagaimana verify data bener-bener dihapus?"
Post-execution verification: admin bisa request SELECT pada identifier yang sama, kalau return 0 row = confirmed deletion.
KB,
            ],

            // -------------------------------------------------------------
            // Sales FAQ — general platform
            // -------------------------------------------------------------
            [
                'module_key' => 'sales_faq_platform_overview',
                'title' => 'Sales FAQ — Platform Overview Privasimu Nexus',
                'category' => 'example',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'privasimu,nexus,platform,sales,jual,demo,fitur,keunggulan,kompetitor,tier,lisensi,deployment,on premise,saas,cloud',
                'summary' => 'Sales FAQ Privasimu Nexus: platform compliance UU PDP end-to-end untuk bank/fintech/healthcare/BUMN. 13 modul (RoPA/DPIA/GAP/DSR/Breach/Consent/Vendor/Data Discovery/AI/Docs/Admin/Holding/Docs Import). Deployment SaaS atau on-prem (Docker). Untuk detail pricing hubungi tim sales.',
                'content' => <<<'KB'
# Sales FAQ — Privasimu Nexus

## Q: Apa itu Privasimu Nexus?
Platform manajemen kepatuhan UU PDP (UU 27/2022) end-to-end untuk Data Protection Officer, Legal, Compliance, dan IT-Security. Satu workspace, multi-tenant, AI-powered.

## Q: Target pasar utama?
- Bank (umum, digital, BPR)
- Fintech (lending, e-money, crowdfunding)
- Healthcare (RS besar, klinik, farmasi, asuransi)
- Telco
- E-commerce besar
- BUMN
- Instansi pemerintah pusat + daerah
- Enterprise holding / conglomerate

## Q: Apa saja modulnya?
13 modul utama:
1. **RoPA** — Records of Processing Activities (wizard 7 step)
2. **DPIA** — Data Protection Impact Assessment (21 kategori + 5×5 matrix)
3. **GAP Assessment** — 33 pertanyaan UU PDP + multi-regulation (GDPR, PDPA, ISO 27701)
4. **DSR** — Data Subject Rights (7 tipe, 72h SLA, SQL Generator)
5. **Breach Management** — 15 SOP containment, RACI, 72h KOMDIGI countdown
6. **Consent Management** — Collection Points, Preference Center, embed widget
7. **Vendor Risk (TPRM)** — 50+ question assessment
8. **Data Discovery** — live scan + generate-only mode, PII detector
9. **AI Features** — Agent, Auto-Fill, Contract Review, Policy Review, Remediation Plan
10. **Document Templates** — white-label PDF + DOCX, 10 preset styling
11. **Admin** — User, SSO, Notifications, Audit, Branding, License, Menu, Custom Fields
12. **Holding Dashboard** — cross-tenant aggregated (enterprise tier)
13. **Document Import** — bulk migrate legacy RoPA/DPIA

## Q: Apa keunggulan vs OneTrust / BigID / TrustArc?
1. **End-to-end UU PDP** — satu-satunya di Indonesia yang lengkap RoPA → DPIA → DSR → Breach → Consent dalam satu panel
2. **Indonesian-native** — UI + dokumen + AI response 100% Bahasa Indonesia
3. **Generate-only SQL** untuk bank — kompetitor butuh live connect ke DB klien
4. **Value proposition** cocok konteks Indonesia (POJK, KOMDIGI, sharded bank DB)
5. **On-prem full** — Docker + AI stack air-gapped, bank-approved
6. **Holding support** — struktur induk-anak native
7. **AI Agent** — natural language compliance assistant dengan tool calling
8. **White-label** — klien bisa rebrand full

## Q: Model deployment?
- **SaaS multi-tenant**: `app.privasimu.com` — quick setup, auto-update, managed infra
- **Self-hosted Docker**: klien jalankan di infra sendiri — fully customizable, air-gap compliant
- **Hybrid**: frontend cloud, data processing on-prem (kalau compliance require)

## Q: Berapa harga / pricing Privasimu Nexus?
Untuk informasi harga + proposal custom sesuai skala tenant Anda, silakan **hubungi tim sales Privasimu**:
- Email: **sales@privasimu.com**
- Demo + pricing discussion tersedia via request
- Custom proposal disesuaikan dengan: jumlah user, modul yang dibutuhkan, deployment model (SaaS / on-prem / hybrid), volume AI credits, support tier, dan requirement spesifik klien

Tim sales akan membantu:
1. Assessment kebutuhan compliance tenant
2. Demo fitur yang relevan
3. Scope proposal (commercial + technical)
4. Negosiasi term + SLA
5. Pilot program 30 hari (untuk qualified prospect)

## Q: Lisensi tersedia tier apa saja?
5 tier lisensi (Basic, Professional, AI, AI Agent, Enterprise Perpetual). Setiap tier berbeda di: jumlah user included, AI credits allocation, modul yang aktif, level support, deployment option.

**Detail scope per tier + commercial terms** — hubungi tim sales.

## Q: Bagaimana klien onboard?
1. **Demo session** — 1 jam walk-through modul relevan
2. **Pilot 30 hari** — free tier untuk test dengan real data tenant klien
3. **Kick-off** — deploy + data migration + user training
4. **Go-live** — full production
5. **Ongoing support** — email + slack + monthly review

## Q: Security & Compliance Certification?
- ISO 27001 (in progress Q2 2026)
- SOC 2 Type II (targeting Q4 2026)
- UU PDP compliant (self-assessment + external audit)
- POJK compliant (untuk klien bank)
- Semua PII encrypted AES-256-CBC at rest

## Q: Support level?
- **Basic**: email only, 2 hari kerja response
- **Professional**: email + Slack, 1 hari kerja response
- **AI / AI Agent**: 4-jam response, phone support
- **Enterprise**: dedicated Slack channel, 1-hour critical response, TAM assigned

## Q: Ada versi demo?
Ya — https://demo.privasimu.com. Login sandbox account, explore full fitur dengan dummy data. Sales bisa guide per-modul 30-60 menit.

## Q: Bagaimana AI feature nya tidak halu?
- KB grounding — 50+ section UU PDP + industry knowledge auto-retrieved
- Tool calling validation — JSON schema check sebelum execute
- Approval gate — user confirm sebelum AI create/update/delete
- Audit log per AI action
- Retry + fallback kalau LLM return invalid
- Hallucination rate tested <2% untuk Qwen3-32B AWQ (benchmark internal)

## Q: Data tenant aman dari tenant lain?
Multi-tenant dengan `org_id` enforced di setiap query. Tidak ada endpoint yang miss filtering. Semua test coverage. Audit trail per-tenant terpisah.
KB,
            ],

            // -------------------------------------------------------------
            // RoPA Flow end-to-end
            // -------------------------------------------------------------
            [
                'module_key' => 'ropa_flow_end_to_end',
                'title' => 'RoPA — Flow End-to-End Platform Privasimu',
                'category' => 'workflow',
                'feature_tags' => 'chat,ropa_autofill,sales_faq',
                'keywords' => 'ropa flow,ropa workflow,create ropa,bagaimana,cara buat,step by step,urutan,approval,submit,review',
                'summary' => 'RoPA flow Privasimu: (1) intent modal pilih manual/AI/batal, (2) wizard 7 step, (3) auto-risk dari 8 trigger, (4) kalau HIGH auto-draft DPIA, (5) approval multi-level, (6) publish + export PDF/DOCX. Waktu: manual 30-60 menit, AI Auto-Fill 2-5 menit.',
                'content' => <<<'KB'
# RoPA — Flow End-to-End

## Step 1: Inisiasi RoPA (30 detik)
Klik "+ RoPA Baru" → Intent Modal muncul dengan 3 pilihan:
- 🖋️ **Isi Manual** — buka wizard blank
- 🤖 **Auto Fill AI** — ketik deskripsi aktivitas, AI generate 7 section
- ❌ **Batal** — tutup modal

Sebelum pilih, isi field wajib: nama pemrosesan, divisi, kategori pemrosesan, assign group (All / Division / User).

## Step 2: Wizard 7 Step (15-30 menit manual, 2-5 menit AI)

### Section 1: Detail Pemrosesan
- Nama aktivitas pemrosesan
- Divisi penanggung jawab
- Unit kerja operasional
- Entitas legal
- Deskripsi singkat

### Section 2: DPO / PIC Team
- DPO assigned (multi-select, default tenant DPO)
- PIC operasional (multi-select dari user aktif)

### Section 3: Informasi Pemrosesan
- Tujuan pemrosesan (deskripsi)
- Dasar hukum (pilih 1 dari 6: Consent/Kontrak/Kewajiban Hukum/Kepentingan Vital/Tugas Publik/Kepentingan Sah)
- Kategori subjek data (karyawan/nasabah/pelamar/customer/vendor/dll)

### Section 4: Pengumpulan Data ⚠️ TRIGGER HIGH RISK
- Kategori data (checklist 15+ jenis PII Indonesia)
- Jumlah subjek estimasi
- Sumber data
- Frekuensi pengumpulan

**Auto-trigger HIGH risk** kalau:
- Pilih data sensitif (biometrik, kesehatan, anak, keuangan detail, agama, politik, orientasi)
- Jumlah subjek > 1.000
- Pemrofilan / AI full / automated decision

### Section 5: Penggunaan & Penyimpanan
- Information System (multi-select dari Data Discovery catalog)
- Lokasi penyimpanan (Indonesia / luar negeri)
- Control akses
- Lama retensi (reusable dari Master Retention)

### Section 6: Pengiriman Data
- Penerima internal (divisi lain)
- Penerima eksternal (vendor, 3rd party)
- Transfer lintas batas (dengan safeguard SCC/BCR)
- Integration dengan TPRM vendor

### Section 7: Retensi & Keamanan
- Durasi retensi (day/month/year/indefinite)
- Trigger pemusnahan
- Metode pemusnahan (delete/anonymize/archive)
- Security measures (encryption/MFA/audit log/backup)

## Step 3: Auto-Risk Calculation
Platform scan wizard_data → calculate risk level:
- HIGH (≥1 trigger)
- MEDIUM (2-3 factor moderate)
- LOW (simple data, small scale)

Kalau HIGH → **auto-create draft DPIA** inherit wizard_data.

## Step 4: Submit for Approval
User klik Submit → status `pending_review` → ke DPO queue.

## Step 5: Approval Workflow
- **Level 1**: DPO review (content check)
- **Level 2**: Legal review (kalau required per tenant config)
- **Level 3**: Direksi approve (final sign-off untuk HIGH risk)

Reject → kembali ke maker dengan notes.
Approve semua level → status `approved` + auto-generate RoPA Number (RoPA-YYYY-NNN).

## Step 6: Publish + Export
Approved RoPA:
- Public dalam RoPA List (searchable, filterable)
- Export PDF / DOCX dengan template canonical atau custom
- AI Continuous Monitoring mulai track
- Jadi input untuk Compliance Posture Score

## Step 7: Review Cycle
- Annual review wajib (auto-remind)
- Trigger review saat:
  - Sistem terkait berubah (Data Discovery drift detect)
  - Insiden breach yang terkait
  - Perubahan regulasi

## Timing Benchmark
| Metode | Waktu |
|---|---|
| Manual expert (pengalaman) | 30 menit |
| Manual first-timer | 60-90 menit |
| **AI Auto-Fill + review** | **2-5 menit Auto-Fill + 5-10 menit review = 10-15 menit total** |
| Approval cycle (DPO+Legal+Direksi) | 2-5 hari kerja |

## Integrasi Ke Modul Lain
- RoPA HIGH → auto-draft DPIA
- RoPA reference Information System → link ke Data Discovery
- RoPA reference vendor eksternal → link ke TPRM
- RoPA data category → feed ke DSR scope picker
- RoPA audit log → Audit module
- RoPA approval flow → Approval Workflow module
KB,
            ],

            // -------------------------------------------------------------
            // Breach flow end-to-end
            // -------------------------------------------------------------
            [
                'module_key' => 'breach_flow_end_to_end',
                'title' => 'Breach Response — Flow End-to-End Privasimu',
                'category' => 'workflow',
                'feature_tags' => 'chat,breach_response,sales_faq',
                'keywords' => 'breach flow,breach workflow,incident response,bagaimana,step by step,72 jam,containment,notifikasi,komdigi,subjek',
                'summary' => 'Breach response Privasimu: detect → classify (15 SOP) → containment checklist + RACI → 72h countdown → notify KOMDIGI + subjek → remediation → RCA → close + PDF report. Semua tertrack real-time dengan Telegram alert.',
                'content' => <<<'KB'
# Breach Response — Flow End-to-End

## Step 1: Detection (T+0h)
Source detection:
- SOC alert (SIEM integration)
- Manual report karyawan
- Subjek data complaint
- Threat intel feed (via webhook)
- Vendor notification (supply chain breach)

Klik "+ Incident Baru" → wizard classification.

## Step 2: Classification (T+0h - T+1h)
Pilih jenis insiden dari 15 SOP template:
Ransomware, Phishing, Unauthorized Access, Insider Threat, DDoS, Data Leak, Misconfiguration, Physical Loss, Supply Chain, Cloud Breach, Malware, Social Engineering, Cryptojacking, Brute Force, Other.

Tiap SOP auto-load:
- Containment checklist template (5-10 step)
- RACI matrix preset (editable)
- Notification template (KOMDIGI + subjek)
- Timeline expectation

## Step 3: Containment (T+1h - T+24h)
Follow checklist per-step:
- Isolation (disconnect infected host)
- Forensics (preserve evidence, IoC collection)
- Legal (consult counsel, law enforcement kalau criminal)
- Communication (internal escalation)
- Remediation (patch, rotate credential, re-train)

Tiap step:
- Evidence upload (screenshot, log export, forensics report)
- Assignee + timestamp
- Skipped flag + alasan
- RACI: R/A/C/I per role

## Step 4: 72-jam Timer (T+0h - T+72h)
Countdown visible di dashboard. Multi-level alert:
- T+6h: DPO in-app + email
- T+24h: alert #1 (manage progress)
- T+48h: escalate ke DPO senior
- T+60h: critical alert Direksi
- T+72h: **KOMDIGI deadline** — trigger notification wajib

## Step 5: RoPA Linkage
1 breach → affect multiple RoPA. Klik "Link RoPA":
- Search RoPA by name / number
- Multi-select
- Auto-calculate subject count affected

Information System mana saja yang terkena → feed ke DSR scope (kalau subjek minta deletion setelah breach).

## Step 6: KOMDIGI Notification (T<72h)
Auto-generate PDF:
- **Surat Pemberitahuan Kebocoran** format resmi
- Isi auto-populate dari wizard + containment checklist
- Data yang bocor (kategori)
- Upaya penanggulangan
- Contact DPO

Submit via:
- Email ke `pdp@komdigi.go.id`
- Printed version via pos (backup)

Upload konfirmasi receipt KOMDIGI ke modul.

## Step 7: Subject Notification (T<72h, atau after KOMDIGI first untuk sensitive breach)
Generate per-subjek atau broadcast kalau >100:
- Email template anti-churn
- SMS (backup)
- Web banner (kalau breach publik)
- WhatsApp deep-link (opsional via Click-to-Chat)

Template Privasimu pre-approved — focus fakta, actionable steps, kontak bantuan.

## Step 8: RCA + Lessons Learned (T+72h+)
Setelah containment stabilize:
- Root Cause Analysis
- Timeline reconstruction
- Attack vector identification
- Control gap analysis
- Remediation action plan
- Update RoPA / DPIA / Breach SOP based on findings

## Step 9: Full Breach Report (final)
Auto-generate comprehensive PDF:
- Executive summary
- Timeline visualization
- Containment log with RACI
- RCA + attack chain
- Data impact assessment
- Remediation plan + status
- Lessons learned
- Appendix: evidence files, KOMDIGI correspondence

Signed by DPO + CISO + Direksi (ada workflow signed).

## Step 10: Close + Audit
- Status `closed` dengan resolution notes
- Simpan ke archive
- Link ke dashboard posture score (breach_history metric)
- Feed ke Breach Simulation module untuk drill reference

## Telegram + WhatsApp Alert
Integration real-time:
- Alert channel DPO saat incident created
- Update setiap status change
- Daily digest selama incident open
- Post-mortem summary saat closed

## Timing Benchmark
| Phase | Typical Duration |
|---|---|
| Detection → Classification | <1 hour |
| Containment completion | 6-24 hours |
| RCA completion | 3-7 hari |
| KOMDIGI notification | <72 jam (hard deadline) |
| Subject notification | <72 jam |
| Full report signed | 14 hari |
| Close | 30 hari setelah last remediation action |
KB,
            ],

            [
                'module_key' => 'pricing_defer_to_sales',
                'title' => 'Informasi Harga — Hubungi Tim Sales Privasimu',
                'category' => 'example',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'harga,price,pricing,biaya,cost,tarif,bayar,berapa,mahal,murah,hemat,diskon,discount,proposal,quote,quotation,tco,roi,tier,paket,package,lisensi,license,budget,anggaran,fee,cost of ownership,annual,yearly,monthly,bulanan,tahunan,one time,sekali bayar,perhitungan',
                'summary' => 'Untuk SEMUA pertanyaan harga/pricing/biaya Privasimu Nexus: AI defer ke tim sales. Kontak: sales@privasimu.com. AI TIDAK boleh sebutkan angka rupiah, diskon, tier pricing, atau komparasi harga kompetitor. Let sales handle pricing discussion + proposal custom.',
                'content' => <<<'KB'
# Informasi Harga — Selalu Defer ke Tim Sales

## Policy AI: Pricing Defer-to-Sales

Untuk **semua** pertanyaan terkait harga, biaya, lisensi, pricing, tarif, diskon, atau perbandingan commercial Privasimu Nexus — AI Agent **WAJIB** respond dengan defer ke tim sales.

## Template Response

"Terima kasih atas pertanyaannya. Untuk informasi detail mengenai harga, pricing, dan proposal custom yang disesuaikan dengan skala kebutuhan Anda, silakan hubungi langsung tim sales Privasimu:

📧 **Email**: sales@privasimu.com

Tim sales kami akan membantu:
- Assessment kebutuhan compliance tenant Anda
- Demo fitur yang paling relevan
- Scope proposal (modul + deployment + support tier)
- Diskusi commercial terms + SLA
- Opsi pilot program 30 hari (untuk qualified prospect)

Setiap proposal disusun custom karena pricing Privasimu disesuaikan dengan: jumlah user, modul yang diaktifkan, deployment model (SaaS / on-prem / hybrid), volume AI credits, level support, dan requirement spesifik lainnya. Tim sales adalah channel yang tepat untuk diskusi ini."

## Kontak Sales
- **Email**: sales@privasimu.com
- **Website**: privasimu.com (request demo form)
- **Demo**: demo.privasimu.com

## Yang AI TIDAK BOLEH Sebutkan

❌ Angka rupiah spesifik per tier (Basic/Professional/AI/AI Agent/Enterprise)
❌ Perbandingan harga vs kompetitor (OneTrust, BigID, Varonis, dll)
❌ Persentase saving / discount
❌ Total Cost of Ownership (TCO) calculation
❌ Add-on pricing (on-prem hardware, training, integration)
❌ Negotiation room / custom discount
❌ Historical pricing / pricing trend
❌ Revenue / margin speculation

## Kenapa Defer?

1. **Pricing context-dependent** — setiap tenant punya scale + requirement beda, proposal harus custom
2. **Competitive intelligence** — hindari competitor scrape harga via query AI
3. **Klien experience** — tim sales kasih service yang lebih personal + negotiation-friendly
4. **Legal** — commercial terms butuh formal quotation, bukan AI statement
5. **Accuracy** — pricing bisa berubah per quarter, AI response berisiko outdated

## Kalau User Insist "Tolong kasih range aja"

Tetap defer:
"Saya paham ingin dapat gambaran cepat, tetapi Privasimu tidak punya fixed price list yang bisa saya share. Setiap proposal disusun sesuai kebutuhan tenant. Tim sales bisa respond dalam 1 hari kerja untuk ballpark quote — silakan hubungi sales@privasimu.com dengan info singkat: jumlah user approximate + modul minat + deployment preference."

## Kalau User Tanya "Apakah Privasimu lebih murah dari OneTrust?"

Hindari komparasi harga spesifik. Alihkan ke value:
"Value proposition Privasimu fokus di konteks Indonesia (UU PDP, POJK, Bahasa Indonesia native, integrasi bank-friendly). Untuk detail commercial comparison, tim sales bisa bantu build business case side-by-side dengan OneTrust. Hubungi sales@privasimu.com."
KB,
            ],

            [
                'module_key' => 'sales_faq_response_questions',
                'title' => 'Sales Q&A — Common Objections + Response Library',
                'category' => 'example',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'sales,objection,questioning,jawaban,pertanyaan,penjualan,tim bisnis,demo,competitor,pros cons',
                'summary' => 'Library jawaban untuk common sales objection: "kami sudah pakai Excel", "mahal", "data kami sensitif", "sudah pakai OneTrust", "butuh training", "belum yakin UU PDP". Privasimu punya answer pattern + bukti untuk setiap objection.',
                'content' => <<<'KB'
# Sales Q&A — Common Objection Library

## Objection 1: "Kami sudah pakai Excel/Google Sheets untuk RoPA"
**Response pattern**:
"Memang Excel bisa dipakai awal. Tapi challenge muncul saat:
1. Multiple DPO edit sekaligus — konflik version
2. Approval workflow manual (email back-and-forth)
3. Audit trail — Excel tidak track siapa ubah apa kapan
4. DSR request masuk — tidak bisa link RoPA ke sistem mana yang kena impact
5. Breach terjadi — tidak bisa cepat identify RoPA affected

Privasimu automate semua ini + native integrasi ke DSR/Breach/DPIA. Klien kami yang migrasi dari Excel biasanya save 60% waktu compliance team."

**Bukti**:
- Case study: Bank X migrasi dari Excel, waktu approval RoPA turun dari 14 hari ke 3 hari
- Time-saving calculator: input jumlah RoPA + frekuensi update → dapat estimasi jam saved/bulan

## Objection 2: "Lisensi Privasimu mahal" / "Berapa pricing-nya?"
**Response pattern**:
"Untuk detail harga + proposal custom yang match skala tenant Anda, mohon hubungi tim sales Privasimu langsung:

📧 **sales@privasimu.com**

Tim sales akan:
- Assessment kebutuhan compliance spesifik Anda
- Demo fitur relevan
- Scope proposal (modul + deployment + support tier)
- Negosiasi commercial terms
- Opsi pilot program 30 hari untuk qualified prospect

Secara umum yang bisa saya sampaikan: Privasimu design untuk deliver value proposition yang cocok dengan konteks regulasi dan operational nuance Indonesia — bukan one-size-fits-all solution global. ROI biasanya terlihat dari:
- Reduksi waktu compliance cycle (RoPA approval, DSR response, breach notification)
- Avoid administrative penalty UU PDP (hingga 2% omzet tahunan)
- Breach prevention value
- Audit readiness (ISO 27701, POJK, KOMDIGI)

Tim sales bisa bantu build business case untuk tim finance Anda."

**Tidak boleh dibahas AI**:
- Angka rupiah spesifik per tier
- Perbandingan harga vs kompetitor
- TCO calculation spesifik
- Diskon / negotiation room

**Defer semua pricing discussion ke sales@privasimu.com**.

## Objection 3: "Data kami sangat sensitif, tidak bisa pakai SaaS"
**Response pattern**:
"Pas sekali — kami punya deployment on-prem. Full stack di infra Anda:
- Docker Compose + Kubernetes support
- Database di server Anda
- AI inference lokal
- Zero data leaves your network
- Air-gap compliant (opsional internet disconnect setelah setup)

Untuk klien bank top-tier, kami juga ada 'Generate-Only Mode' untuk Data Discovery: platform tidak pernah touch production DB Anda. Admin register schema manual, kami generate SQL pack, Anda eksekusi sendiri."

**Bukti**:
- On-prem architecture diagram
- List bank klien yang pakai on-prem
- Security certification + audit report

## Objection 4: "Kami sudah pakai OneTrust, why switch?"
**Response pattern**:
"Understood — OneTrust mature product. Tapi beberapa klien switch ke kami karena:
1. **Konteks Indonesia**: UU PDP, POJK, KOMDIGI — kami natively support, OneTrust generik GDPR
2. **Bahasa**: UI + dokumen + AI response Bahasa Indonesia sepenuhnya
3. **Value proposition**: fitur setara untuk konteks lokal — detail commercial comparison via tim sales
4. **Integration**: OneTrust modular (jual per modul), kami one-platform
5. **Local support**: tim engineering kami di Jakarta, response lebih cepat

Kami bisa setup pilot parallel dengan OneTrust untuk compare real use case selama 30 hari. Commitment-free. Hubungi sales@privasimu.com untuk detail."

## Objection 5: "Tim kami belum aware UU PDP secara dalam"
**Response pattern**:
"Itu justru salah satu problem yang Privasimu solve. Platform include:
- **AI Remediation Plan** — generate compliance roadmap otomatis
- **Knowledge Base** internal — panduan UU PDP per-pasal
- **Training module** (Phase 2) — video + quiz
- **GAP Assessment** — langsung identify gap awareness tim
- **AI Agent** — tim bisa tanya 'apa dasar hukum pemrosesan data penggajian' → dapat jawaban instant

Plus saya include 5-day onboarding training gratis untuk semua tier di atas Basic."

## Objection 6: "Butuh training berapa lama untuk adopt?"
**Response pattern**:
"Privasimu design for non-technical users. Typical onboarding:
- **DPO**: 2 hari training = fully productive
- **Maker (staff compliance)**: 1 hari = can start inputting RoPA
- **Direksi/reviewer**: 2 jam = approve workflow
- **Subject data**: zero training — embed form + preference center self-service

Total tim 25 orang onboard dalam 1 minggu. Kami juga kasih 'training the trainer' — 3 internal champion yang bisa roll out ke lain."

## Objection 7: "AI-nya bikin kita mandi hallucination?"
**Response pattern**:
"Good question — concern sahih. Privasimu mitigate dengan:
1. **RAG grounding** — 50+ section knowledge base UU PDP + industry, auto-inject ke AI context
2. **Tool calling validation** — AI bilang mau create RoPA, user review + approve SEBELUM execute
3. **Internal benchmark**: halu rate <2% untuk Qwen3-32B + grounding
4. **Audit log** per AI action — traceable kalau error
5. **Fallback mode** — AI down? Manual flow tetap jalan

Dan kami kasih opsi: pake AI boleh full, atau hanya untuk suggestion, atau turn off sama sekali. Klien in control."

## Objection 8: "Integration ke sistem existing kami?"
**Response pattern**:
"Privasimu terbuka untuk integrate via:
- **REST API** — full CRUD all module via Developer API Hub
- **Webhook** — push event ke sistem Anda (RoPA created, breach detected, etc)
- **SIEM Integration** — send log ke Splunk/QRadar/Elastic
- **SSO** — SAML + OIDC
- **IMAP** — DSR intake dari email
- **Slack / Teams** — alert + approval via chat
- **SQL Server / Oracle / MySQL** — data discovery direct
- **Custom** — engineering team bisa develop connector baru

Typical integration project: 2-4 minggu dengan tim Anda + kami."

## Objection 9: "Apa yang bikin Privasimu tidak jadi outdated dalam 6 bulan?"
**Response pattern**:
"Release cycle Privasimu: update minor tiap bulan, major 3 bulan, paradigm shift 1 tahun.

Regulatory update:
- KOMDIGI release aturan baru → Privasimu update dalam 2 minggu
- Regulasi sektor (POJK, Permenkes) → auto-add ke framework library
- Update bisa seamless (SaaS) atau version control manual (on-prem)

Plus roadmap transparan — kami share ke klien tiap kuartal. Feature request dari klien ditampung di backlog dengan voting. Top-voted feature diprioritize."

## Objection 10: "Kalau Privasimu bangkrut, data kami ke mana?"
**Response pattern**:
"Fair concern. Proteksi di kontrak:
1. **Data ownership**: SELALU tenant Anda, bukan kami
2. **Escrow agreement**: source code di-escrow ke 3rd party, kalau Privasimu shutdown, Anda dapat code
3. **Export format**: semua data bisa di-export JSON + PDF bulk
4. **Portability**: schema data documented, bisa migrate ke platform lain
5. **Offboarding SLA**: 14 hari untuk full export + data wipe certificate

Plus Privasimu keberlangsungan: kami bootstrapped profitable (not burning VC), revenue diversified (banyak klien, bukan 1 whale), team senior compliance + engineering."
KB,
            ],

            // -------------------------------------------------------------
            // Policy Review — Current Feature Detail (refreshed)
            // -------------------------------------------------------------
            [
                'module_key' => 'policy_review_feature_current',
                'title' => 'Policy Review — Feature Detail (Privasimu)',
                'category' => 'workflow',
                'feature_tags' => 'chat,sales_faq,policy_review',
                'keywords' => 'policy review,kebijakan privasi,sop,peraturan perusahaan,upload,analisis,audit kebijakan,gap policy,compliance level,recommendation,priority action,cara kerja policy review,fitur policy review,how,bagaimana',
                'summary' => 'Policy Review Privasimu: upload dokumen (PDF/DOCX) atau paste text → AI analyze compliance UU PDP → output compliance score + per-section analysis (score, gap, recommendation, UU PDP reference) + missing elements + strengths + priority action plan. Support 7 doc type: Kebijakan Privasi, SOP Data Handling, SOP Breach Response, SOP DSR, SOP Retensi, Peraturan Perusahaan, Other.',
                'content' => <<<'KB'
# Policy Review — Feature Detail

## Apa Itu Policy Review di Privasimu?

Fitur AI untuk review internal policy + SOP terhadap kepatuhan UU PDP. Platform scan isi dokumen, bandingkan dengan checklist UU PDP per Pasal, kasih gap report + rekomendasi konkrit.

## 7 Tipe Dokumen Supported

1. **🔒 Kebijakan Privasi** (kebijakan_privasi) — Privacy Policy publik
2. **📋 SOP Penanganan Data** (sop_data_handling) — internal data handling
3. **🚨 SOP Breach Response** (sop_breach_response) — incident response plan
4. **🏢 Peraturan Perusahaan** (peraturan_perusahaan) — PP formal
5. **👤 SOP Hak Subjek Data** (sop_dsr) — DSR handling procedure
6. **🗄️ SOP Retensi Data** (sop_retensi) — retention + disposal
7. **📄 Lainnya** (other) — kustom policy

## Input Method

### Method 1: Upload File
- Supported: PDF, DOCX, DOC (max 10 MB)
- File parsed via `DocumentParserService`:
  - PDF digital-born → pdfplumber text extraction
  - PDF scan → PaddleOCR fallback
  - DOCX → PHPWord extract
- Text auto-normalized (strip formatting, preserve structure)

### Method 2: Paste Text
- Direct paste ke textarea (min 50 karakter)
- Markdown atau plain text support
- Cocok untuk quick check policy section tertentu

## Analysis Process

```
Dokumen → text extraction → chunk ke 2000 token →
AI Policy Review engine (LLM + RAG grounding UU PDP) →
Structured output JSON
```

System prompt inject KB sections:
- `uu_pdp_prinsip_umum` (Pasal 16)
- `uu_pdp_hak_subjek` (Pasal 5-10)
- `uu_pdp_pasal_31_ropa`
- `uu_pdp_pasal_32_dsr_sla`
- `uu_pdp_pasal_46_breach`
- `policy_review_uu_pdp_mapping`

AI assess dokumen per-section + compare vs 15 required elements.

## Output Structure

```json
{
  "overall_score": 72,
  "compliance_level": "Medium",
  "summary": "Kebijakan Privasi memenuhi basic UU PDP tapi beberapa area kritis perlu diperbaiki.",
  "sections": [
    {
      "section_title": "Identifikasi Pengendali Data",
      "status": "compliant",
      "score": 100,
      "gap_description": "Lengkap + email DPO tersedia",
      "recommendation": "Pertahankan struktur saat ini",
      "uu_pdp_reference": "Pasal 31"
    },
    {
      "section_title": "Hak Subjek Data",
      "status": "partial",
      "score": 60,
      "gap_description": "Hanya disebut hak akses dan koreksi. Tidak sebut hak portabilitas, hak objection, hak withdraw consent.",
      "recommendation": "Tambahkan section tentang 7 hak subjek (Pasal 5-10) dengan cara exercise-nya (email DPO, portal, form).",
      "uu_pdp_reference": "Pasal 5-10"
    },
    ...
  ],
  "missing_elements": [
    "Kontak DPO dedicated (dpo@company.com)",
    "Retention period per kategori data",
    "Cross-border transfer disclosure + safeguards",
    "Mekanisme withdraw consent",
    "Children's data policy (kalau service bisa diakses minor)"
  ],
  "strengths": [
    "Struktur dokumen well-organized",
    "Bahasa jelas, mudah dipahami subjek awam",
    "Include contact information yang jelas"
  ],
  "priority_actions": [
    {
      "action": "Tambahkan section lengkap 7 hak subjek data dengan mekanisme exercise",
      "priority": "critical",
      "deadline_suggestion": "2 minggu"
    },
    {
      "action": "Dedicate email DPO (dpo@company.com) dan publikasikan",
      "priority": "high",
      "deadline_suggestion": "1 minggu"
    },
    ...
  ]
}
```

## Compliance Score Levels

| Skor | Level | Warna | Makna |
|---|---|---|---|
| 85-100 | High | 🟢 | Sangat compliant, minor tweaks |
| 65-84 | Medium | 🟡 | Compliant dasar, ada area improvement |
| 40-64 | Low | 🟠 | Major gap, butuh revisi signifikan |
| 0-39 | Critical | 🔴 | Non-compliant risk tinggi, redesign |

## Per-Section Status

Setiap dokumen di-check terhadap 15 required elements (lihat `policy_review_uu_pdp_mapping`):
1. Identitas Pengendali Data (Pasal 31)
2. Data Protection Officer (Pasal 53)
3. Kategori Data Dikumpulkan (Pasal 16)
4. Tujuan Pemrosesan (Pasal 16)
5. Dasar Hukum per Tujuan (Pasal 20)
6. Retensi Data (Pasal 16)
7. Penerima Data / 3rd Party (Pasal 31)
8. Hak Subjek Data (Pasal 5-10)
9. Withdraw Consent Mechanism (Pasal 8)
10. Security Measures (Pasal 35-39)
11. Cookie Policy (Pasal 16)
12. Children's Data (Permenkominfo 20/2016)
13. Cross-Border Transfer (Pasal 56)
14. Breach Notification (Pasal 46)
15. Policy Update Mechanism

Status per section:
- **compliant** (score 85-100): requirement fully met
- **partial** (score 40-84): sebagian ada tapi kurang lengkap
- **missing** (score 0-39): tidak ada atau salah total
- **not_applicable**: tidak relevan untuk doc_type ini

## Priority Action Levels

AI kategorikan remediation:
- **critical** — legal violation, deadline <2 minggu
- **high** — major gap, deadline <1 bulan
- **medium** — moderate improvement, deadline <3 bulan
- **low** — nice-to-have, deadline <6 bulan

## List View & History

Setelah analysis, record tersimpan di `/policy-reviews`:
- Title, doc_type, risk_score, status, tanggal
- Click untuk view detail
- Re-analyze (setelah revisi policy) untuk track improvement
- Soft-delete + trash + restore + force-delete

## Workflow Typical

```
Step 1: Upload draft Privacy Policy v1
  → Score 52% (Low)
  → 8 missing elements, 12 priority actions

Step 2: DPO revisi policy berdasar recommendation

Step 3: Upload v2
  → Score 78% (Medium)
  → 3 missing elements, 4 priority actions (down from 12)

Step 4: Iterate sampai score >85%

Step 5: Publish final version
  → Export bersih untuk website
  → Version history preserved
```

## Integration dengan Module Lain

- **Knowledge Base**: Policy Review contribute ke posture score
- **GAP Assessment**: Priority action dari Policy Review jadi input Remediation Plan
- **Audit Log**: setiap upload + analysis tercatat
- **AI Remediation Plan**: aggregate gap dari Policy + GAP Assessment

## Timing Benchmark

| Metric | Typical |
|---|---|
| Upload + parse file (PDF 10 hal) | 5-15 detik |
| AI analysis + structured output | 30-90 detik |
| Total end-to-end review | 1-2 menit |

## Cost Consideration (AI Credits)

- Setiap analysis konsumsi token:
  - Input: policy text (2k-15k token)
  - RAG grounding: 3k-5k token
  - Output: structured JSON (2k-4k token)
- Per review: ~10k-25k token
- AI Credits tenant ter-decremnet sesuai pricing provider

## Permissions

- **Read** (`policy_review:read`): lihat list + detail + history
- **Write** (`policy_review:write`): upload + analyze + soft-delete
- **Force delete**: admin/superadmin only

## API Endpoints

```
GET    /api/policy-reviews                # list
GET    /api/policy-reviews/trashed        # trash
GET    /api/policy-reviews/{id}           # detail
POST   /api/policy-reviews/analyze        # upload + analyze
DELETE /api/policy-reviews/{id}           # soft-delete
POST   /api/policy-reviews/{id}/restore   # restore
DELETE /api/policy-reviews/{id}/force     # hard-delete
```

## Use Case per Tipe Dokumen

### Privacy Policy (Website Publik)
Wajib yang di-review pertama. Frekuensi: tiap major revisi product / feature baru / perubahan pihak 3.

### SOP Data Handling (Internal)
Review tahunan. Saat onboarding processor baru. Setelah incident / audit finding.

### SOP Breach Response
Review kuartal. Setelah real incident (update lesson learned). Setelah drill yang reveal gap.

### SOP DSR
Review saat DSR volume spike. Saat ada regulator inquiry. Saat platform tooling change.

### SOP Retensi
Review saat regulasi baru rilis (misal perubahan UU retention). Saat coverage RoPA berubah.
KB,
            ],
        ];
    }

    // ======================================================================
    // 14. TECHNICAL DEEP DIVE
    //
    // Sections untuk jawab pertanyaan teknis DPO/IT klien (sales tidak jawab
    // langsung). Cover: PII detection method, DB connectivity, AI arch,
    // API, security, deployment, audit log, backup, OCR.
    // ======================================================================
    private function technicalDeepDiveSections(): array
    {
        return [
            [
                'module_key' => 'tech_data_discovery_detection',
                'title' => 'Data Discovery — PII Detection Method (Technical)',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq,data_discovery,pii_scan',
                'keywords' => 'regex,pattern,deteksi,detection,pii detector,algoritma,contentpiiscanner,akurasi,confidence,sample,metode teknis,cara kerja,nlp,ner,entity recognition',
                'summary' => 'PII Detection Privasimu hybrid 4-layer: (1) Regex pattern Indonesia (NIK, NPWP, rekening), (2) Context clue dari column name, (3) Statistical confidence dari sample 100-1000 rows, (4) AI View via bge-m3 embedding + LLM classification untuk kolom ambiguous. Akurasi 95-98% obvious PII, 85-90% ambiguous.',
                'content' => <<<'KB'
# Data Discovery — PII Detection Method

## Pertanyaan DPO Umum
"Metode scan pakai apa? Regex? ML? Akurasi berapa?"

## Layer 1: Regex Pattern (ContentPiiScanner + PiiDetector)

Regex library untuk PII Indonesia:
- NIK: `/^\d{16}$/` (16 digit)
- NPWP: `/^\d{15,16}$/` atau formatted `XX.XXX.XXX.X-XXX.XXX`
- Telepon ID: `/^(\+62|62|0)8\d{8,12}$/`
- Email: RFC 5322
- Rekening BCA/Mandiri/BRI: per-bank format
- BPJS: 11-13 digit
- 20+ pattern lain

Scan sample 100-1000 row per kolom → hitung match rate. Kolom dengan >80% value match → classify sebagai PII.

## Layer 2: Context Clue (Column Name Heuristic)

Map nama kolom → kategori:
- `nik`, `nomor_ktp`, `identity_number` → NIK
- `email`, `mail`, `e_mail` → email
- `dob`, `tgl_lahir`, `birthdate` → tanggal lahir
- `fingerprint`, `biometric_*` → biometrik

Confidence scoring:
- Regex + context: 95%
- Regex only: 70%
- Context only: 50%

## Layer 3: Statistical Confidence

Threshold:
- **>95%**: auto-classify
- **80-95%**: flag for review
- **50-80%**: needs AI view atau manual
- **<50%**: skip

## Layer 4: AI View (DeepScan / Semantic)

Untuk kolom ambiguous (confidence rendah):
1. Kirim kolom + sample ke TEI embedding (bge-m3)
2. Cosine similarity vs PII category reference embeddings
3. Optional: LLM classify dengan context ("cust_ref_v2 dengan value NS123 di tabel customer_account")
4. NER (Named Entity Recognition) untuk text field non-structured — detect nama orang, alamat, nomor rekening dalam paragraph teks

## Scan Volume + Performance

- Tidak full-scan — pakai TABLESAMPLE (PostgreSQL) atau ORDER BY RAND() LIMIT (MySQL)
- 100 kolom: <30 detik
- 1000 tabel: 30 menit - 2 jam
- Non-blocking, incremental, pausable

## Accuracy Benchmark

- Obvious PII (NIK kolom nama jelas): 98%
- Ambiguous kolom legacy: 85% dengan AI View
- False positive rate: 3-5%

## DeepScan vs Standard Scan

- **Standard Scan** = Layer 1-3 only (regex + context + statistical). Fast, cheap, deterministic.
- **DeepScan** = Layer 1-4 dengan AI (NER + semantic). Lebih dalam, handle unstructured text, lebih lambat, butuh LLM.

## Standard View vs AI View

- **Standard View**: menampilkan hasil deteksi raw (kolom, kategori, confidence, status shadow data).
- **AI View**: Standard View + rekomendasi AI per kolom (enkripsi, pseudonimisasi, access control) + ringkasan global + risk mitigation plan.

## Privacy

- Sample data scan tidak disimpan
- Hanya metadata classification yang tersimpan
- Audit log untuk trace scan activity
KB,
            ],

            [
                'module_key' => 'tech_data_discovery_db_connection',
                'title' => 'Data Discovery — Database Connection Specs',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq,data_discovery',
                'keywords' => 'connect database,mysql,postgresql,mssql,oracle,mongodb,s3,minio,credential,protocol,port,firewall,whitelist,ssl,tls,readonly user,permission,grant',
                'summary' => 'Privasimu connect ke DB klien via native protocol: MySQL (3306), PostgreSQL (5432), MSSQL (1433), Oracle (1521), MongoDB (27017). Credential AES-256 encrypted. SSL/TLS + SSH tunnel support. Butuh readonly user SELECT + INFORMATION_SCHEMA. Firewall whitelist Privasimu IP (SaaS) atau localhost (on-prem).',
                'content' => <<<'KB'
# Database Connection — Technical Specs

## Supported Engines

| Engine | Version | Port | Protocol |
|---|---|---|---|
| MySQL | 5.7+/8.0+ | 3306 | Native MySQL protocol |
| MariaDB | 10.3+ | 3306 | Native MySQL protocol |
| PostgreSQL | 13+ | 5432 | libpq |
| MSSQL | 2016+ | 1433 | TDS |
| Oracle | 19c+ | 1521 | TNS |
| MongoDB | 5.0+ | 27017 | MongoDB wire |

## Cloud Storage

| Service | Protocol | Auth |
|---|---|---|
| AWS S3 | HTTPS + SigV4 | Access key + secret |
| MinIO | S3-compatible | Access key + secret |
| Google Cloud Storage | HTTPS | Service account JSON |
| Azure Blob | HTTPS | Account key + SAS |

## Required Permission (Read-Only)

### MySQL
```sql
CREATE USER 'privasimu_scanner'@'%' IDENTIFIED BY '<pwd>';
GRANT SELECT ON *.* TO 'privasimu_scanner'@'%';
GRANT SHOW VIEW ON *.* TO 'privasimu_scanner'@'%';
```

### PostgreSQL
```sql
CREATE USER privasimu_scanner WITH PASSWORD '<pwd>';
GRANT USAGE ON SCHEMA public TO privasimu_scanner;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO privasimu_scanner;
```

### MSSQL
```sql
CREATE LOGIN privasimu_scanner WITH PASSWORD = '<pwd>';
CREATE USER privasimu_scanner FOR LOGIN privasimu_scanner;
GRANT SELECT TO privasimu_scanner;
```

### Oracle
```sql
CREATE USER privasimu_scanner IDENTIFIED BY "<pwd>";
GRANT CONNECT, SELECT ANY TABLE TO privasimu_scanner;
```

## Network / Firewall

- **SaaS**: whitelist Privasimu outbound IP (`34.128.x.x`, published di docs). Atau reverse tunnel via `privasimu-agent` container (no inbound rule).
- **On-Prem**: internal VLAN, DMZ→prod firewall rule port DB.

## SSL/TLS

Wajib untuk SaaS. Opsional on-prem. Certificate:
- Verify full (strict, default)
- Verify CA (self-signed signed by internal CA)
- Disable (internal VLAN only)

Upload cert via UI `Settings → Data Discovery → Add System`.

## SSH Tunneling

Untuk DB tidak expose network:
```
Privasimu → SSH jump host → DB internal (port forward)
```

Config: SSH host + key (encrypted storage).

## Credential Storage

- **AES-256-CBC** via Laravel Crypt
- Master key di `.env` APP_KEY
- Per-tenant isolation
- Never log plaintext
- Decrypted hanya saat scan run

## Performance Impact ke Prod DB

- CPU: <5% overhead saat active scan
- I/O: 100-500 IOPS extra
- Memory: ~50 MB per connection
- Network: low (metadata + sample)

Recommendation: scan off-peak hours untuk prod DB besar.

## Troubleshooting

| Error | Cause | Fix |
|---|---|---|
| Connection refused | Firewall block | Whitelist IP |
| Auth failed | Wrong credential | Re-enter |
| SSL handshake fail | Cert issue | Upload CA bundle |
| Permission denied | Missing GRANT | Add SELECT |
| Timeout | Slow network | Increase timeout |

## Best Practice untuk Klien

1. Dedicated user `privasimu_scanner` (bukan root)
2. Read-only permission only
3. Strong password 16+ char
4. Rotate credential quarterly
5. Enable DB audit log
6. Use SSL cross-network
7. Restrict by IP
8. Test staging first
KB,
            ],

            [
                'module_key' => 'tech_ai_architecture',
                'title' => 'AI Features — Architecture + Provider Abstraction',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'ai architecture,provider,openrouter,openai,anthropic,gemini,deepseek,qwen,model,switch,streaming,tool calling,rag,credit metering,multi provider',
                'summary' => 'AI Privasimu pakai provider abstraction (OpenAI-compatible interface). Support OpenRouter, OpenAI, Anthropic, Gemini, DeepSeek, Groq, Qwen on-prem via vLLM. Single AiService handle routing + RAG + credit metering + retry + streaming + tool calling. Tenant switch provider cuma update env + config:clear.',
                'content' => <<<'KB'
# AI Features — Architecture Technical

## Provider Abstraction

`App\Services\AiService` = single entry point semua AI call. Handle:
1. Load tenant preference (provider, model, temperature)
2. Credit metering (kalau limit → throw)
3. RAG grounding injection
4. Format request per provider
5. Retry exponential backoff (1s, 2s, 4s)
6. Stream SSE atau buffer
7. Log ke `ai_credit_logs` + `audit_logs`

## Supported Providers

| Provider | Models | Deployment |
|---|---|---|
| OpenRouter | 150+ model (DeepSeek, Claude, GPT, Gemini) | Cloud aggregator |
| OpenAI Direct | GPT-4o, o1 | Cloud |
| Anthropic | Claude Opus 4, Sonnet 4, Haiku | Cloud |
| Google Gemini | Gemini 2.5 Pro, Flash | Cloud |
| Azure OpenAI | GPT-4 via Azure | Cloud/Enterprise |
| DeepSeek | V3, R1 | Cloud |
| Groq | Llama 3.3, Mixtral | Cloud high-speed |
| OpenAI-Compatible | Qwen/Llama via vLLM | **On-prem** |

## Config per Tenant

```env
AI_PROVIDER=openrouter
AI_PROVIDER_BASE_URL=https://openrouter.ai/api/v1
AI_PROVIDER_MODEL=deepseek/deepseek-chat
AI_PROVIDER_API_KEY=sk-or-xxx

# On-prem switch
AI_PROVIDER=openai-compatible
AI_PROVIDER_BASE_URL=https://10.0.0.50/v1
AI_PROVIDER_MODEL=qwen3-32b
```

Zero code change untuk switch provider.

## OpenAI-Compatible Interface

Semua provider harus implement OpenAI Chat Completion API spec. AiService translate format proprietary (Anthropic Messages API) ke OpenAI format otomatis.

## Retry & Failover

1. Primary provider
2. 3x retry exponential backoff
3. Fallback provider (tenant config optional)
4. Graceful degrade ke manual flow

## Rate Limiting

- Per-tenant: 100 req/min (configurable)
- Per-user: 20 req/min
- Global cap: runaway cost protection
- 429 dengan retry-after header

## Credit Metering

Tabel `ai_credit_logs`:
- org_id, user_id, feature, provider, model
- tokens_in, tokens_out, cost_cents
- latency_ms, request_id
- created_at

Admin dashboard: konsumsi per-feature, per-tier cap.

## Tool Calling

Function calling untuk AI Agent action. Support:
- OpenAI GPT-4/4o: native
- Anthropic Claude: native
- Gemini: native
- DeepSeek / Qwen3 / Llama 3.3: hermes parser

Approval gate: tool yang write butuh user confirm sebelum execute.

## Streaming

SSE untuk long response. Frontend render token-by-token. NGINX `proxy_buffering off`.

Streaming features:
- AI Chat
- AI Agent conversations
- AI Auto-Fill (progress feedback)

Buffered (full response):
- Contract Review (JSON parsing)
- Policy Review
- Remediation Plan

## Audit Trail

Setiap AI call tercatat:
- `ai.chat`, `ai.autofill_ropa`, `ai.tool_call`
- Actor: human atau "system"
- Input/output summary
- Tenant scope

Approval gate untuk write operation: `approval_by` user logged.
KB,
            ],

            [
                'module_key' => 'tech_ai_rag_mechanism',
                'title' => 'AI Grounding — RAG Mechanism (Anti-Hallucination)',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'rag,grounding,keyword matching,semantic,embedding,bge-m3,halu,hallucination,knowledge base,context injection,retrieval,findrelevant,anti halu',
                'summary' => 'RAG Privasimu = keyword-based retrieval (Level 2) dari knowledge_base_sections dengan feature-tag filter. Returns top-3 section, inject ke system prompt LLM. Reduce halu rate dari 8% (ungrounded) → 2% (grounded). Phase 2 roadmap: upgrade ke semantic RAG via bge-m3 embedding.',
                'content' => <<<'KB'
# RAG (Retrieval-Augmented Generation) Mechanism

## RAG Level

```
Level 0 — No grounding (pure LLM memory)
Level 1 — Static prompt (hardcoded)
Level 2 — Keyword RAG ← PRIVASIMU SAAT INI
Level 3 — Semantic RAG (embedding)
Level 4 — Hybrid (keyword + semantic + rerank)
```

## Storage

Tabel `knowledge_base_sections`:
- module_key, title, content, summary
- keywords (csv), feature_tags (csv), category
- org_id (null = system, specific = tenant-owned)

## Retrieval Method

```php
KnowledgeBaseSection::findRelevant($query, $orgId, $featureTag, $limit);
```

Scoring:
- keyword match: +3 per keyword
- title match: +5
- org_id own: +1
- feature_tag match: +2

Returns top-N (default 3).

## Injection Flow

1. User query diterima
2. `findRelevant()` return 3 KB section paling match
3. Build system prompt dengan section di-embed
4. Send ke LLM → grounded response
5. Stream ke user

## Feature-Specific Grounding

| Feature | Feature Tag |
|---|---|
| AI Chat | chat |
| AI Auto-Fill RoPA | ropa_autofill |
| AI Auto-Fill DPIA | dpia_autofill |
| AI Contract Review | contract_review |
| AI Policy Review | policy_review |
| AI Remediation Plan | remediation |
| AI Agent Tool Calling | tool_calling |
| Data Discovery AI View | pii_scan |

## Context Budget Management

`KnowledgeBaseSection::buildContext($query, $orgId, $featureTag, $mode, $limit)`:
- `summary` mode: 50-200 token per section → 3 sections = ~500 token (tight-budget feature)
- `full` mode: 500-2000 token per section → ~3-6k token (long-context)
- `adaptive` mode: prefer full, fallback summary

## Quality Measurements

Internal benchmark:
- Ungrounded: 42% accuracy UU PDP question, halu 8%
- Keyword RAG: 91% accuracy, halu 2%
- Semantic RAG (future): projected 94%

## Safety Measures

Selain RAG:
1. Tool calling JSON validation
2. Approval gate untuk write operation
3. Audit log semua AI response
4. Feedback loop (user flag wrong → improve KB)
5. Confidence meter (optional output)

## Phase 2 Upgrade Roadmap

Semantic RAG via bge-m3:
1. Migration: tambah `embedding` vector[1024] kolom
2. PostgreSQL `pgvector` atau MySQL 8.4 `VECTOR` type
3. Re-index saat edit section
4. Cosine similarity retrieval
5. Hybrid: combine keyword + semantic score

Timeline: Q3 2026.
KB,
            ],

            [
                'module_key' => 'tech_security_multi_tenancy',
                'title' => 'Security & Multi-Tenancy Implementation',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'security,keamanan,multi tenant,org_id,isolation,encryption,aes,password,bcrypt,sanctum,token,sso,saml,oidc,rbac,permission',
                'summary' => 'Multi-tenancy: org_id enforced di setiap query + middleware. PII AES-256-CBC via EncryptedString cast. Password bcrypt. Token Laravel Sanctum 60min + refresh. SSO SAML + OIDC + JIT. Permission hierarchical role + module:read/write granular + wildcard. Audit log immutable 5-year retention.',
                'content' => <<<'KB'
# Security & Multi-Tenancy Technical

## Multi-Tenancy Pattern

**Shared DB + Row-Level Isolation via `org_id`**

Enforcement:
1. Model level: `Model::where('org_id', $orgId)`
2. Service level: `TenantContextService::resolveOrgId()`
3. Controller level: `UniversalCrudController` auto-inject
4. Middleware: `TenantScopeMiddleware` reject cross-tenant

Rule: setiap query model tenant-scoped WAJIB filter org_id. CI check.

## Encryption at Rest

### DB PII (EncryptedString cast)
```php
protected $casts = [
    'email' => 'encrypted:string',
    'nik' => 'encrypted:string',
    'phone' => 'encrypted:string',
];
```
AES-256-CBC via OpenSSL. Key = `APP_KEY` env.

### Credentials (Crypt::encryptString)
API key, SMTP password, SSO secret, DB connection → encrypted sebelum DB.

### Files
- Local: permission 600
- S3: SSE-S3 atau SSE-KMS
- MinIO: SSE-C/SSE-KMS

## Password

- bcrypt cost 10
- Never plaintext stored
- Reset token expire 1 jam
- HaveIBeenPwned check (opt-in)

## Session / Token

### Sanctum
- `Bearer <token>` header
- TTL 60 menit default
- Auto-refresh
- Revoke on logout

### CSRF
- Per-session token
- Middleware validated

### Rate Limiting
- Per IP: 60/min
- Per user: 100/min
- Login: 5/min
- Password reset: 3/min

## RBAC

### Hierarchical Roles
`root` > `superadmin` > `admin` > `dpo` > `maker` > `reviewer/checker` > `auditor` > `viewer`

### Module Permission (JSON array)
```json
["ropa:read", "ropa:write", "breach:*", "*:read"]
```

Wildcard `*` supported.

### Middleware
```php
Route::post('/api/ropa')->middleware('permission:ropa,write');
```

## SSO

### SAML 2.0
- IdP: Azure AD, Okta, OneLogin, Ping, Keycloak
- Metadata: `/sso/saml/metadata`
- ACS: `/sso/saml/acs`
- Attribute mapping: email, firstname, lastname, role, groups

### OIDC/OAuth2
- Google Workspace, M365, Auth0
- Authorization Code + PKCE
- Scopes: openid email profile
- JIT provisioning

## Audit Trail (Immutable)

Tabel `audit_logs`:
- actor_type: user | ai | system | webhook
- action (ropa.create, user.login_failed, ai.tool_execute)
- resource_type + resource_id
- changes (JSON diff before/after)
- metadata (ip, user_agent, api_key)
- severity: info | warning | critical

Retention: 5-10 tahun. Tidak boleh UPDATE/DELETE by application. Enforcement via DB-level role permission.

## API Security

### Auth
- Bearer Sanctum token
- Atau Developer API key

### API Key Scope
- Permission subset
- Rate limit
- IP whitelist
- Expiration

## Certifications (Target 2026)

- ISO 27001 (Q2 2026)
- SOC 2 Type II (Q4 2026)
- ISO 27701 (Q4 2026)
- POJK compliance statement

## Data Isolation Guarantees

1. No cross-tenant leak (test coverage + audit)
2. Per-tenant backup
3. 14-hari offboarding export + hard-delete certificate
4. Incident response: 24-jam notify affected tenant
KB,
            ],

            [
                'module_key' => 'tech_api_integration',
                'title' => 'API & Integration — Developer Reference',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'api,rest,integration,webhook,developer,swagger,openapi,endpoint,sanctum,api key,siem,splunk,elastic,imap,slack,teams,telegram,whatsapp',
                'summary' => 'Privasimu REST API full CRUD + webhook. Auth Sanctum token atau Developer API key (per-scope, IP whitelist, rate limit). Endpoint per modul. Webhook push event (ropa.*, breach.*, dsr.*, ai.*). Integration: SIEM (Splunk/Elastic/QRadar), IMAP (DSR intake), Slack/Teams, Telegram, WhatsApp deep-link.',
                'content' => <<<'KB'
# API & Integration Reference

## Authentication

### Sanctum Token (User)
```
POST /api/login → {token, expires_at}
Authorization: Bearer <token>
```

### Developer API Key (Automation)
Admin create di Settings → API Keys. Format `privasimu_sk_live_xxx`.

Per-key config:
- Permission scope (subset of user permission)
- Rate limit
- IP whitelist
- Expiration

## Core Endpoints (sample)

### RoPA (Universal CRUD pattern)
```
GET    /api/m/ropa              # list
GET    /api/m/ropa/{id}         # detail
POST   /api/m/ropa              # create
PUT    /api/m/ropa/{id}         # update
DELETE /api/m/ropa/{id}         # soft-delete
POST   /api/m/ropa/{id}/restore
GET    /api/m/ropa/{id}/history
POST   /api/m/ropa/{id}/approve
GET    /api/m/ropa/{id}/export
```

Same pattern untuk dpia, gap, dsr, breach, consent, vendor, data-discovery.

### AI Features
```
POST /api/ai/chat                      # streaming
POST /api/ai/autofill/ropa
POST /api/ai/autofill/dpia
POST /api/ai/contract-review
POST /api/ai/policy-review
POST /api/ai/remediation-plan
```

### Data Discovery
```
POST /api/information-systems
POST /api/information-systems/{id}/scan
GET  /api/information-systems/{id}/schema
```

### DSR SQL Generator (Phase K)
```
POST /api/dsr/{id}/sql-pack/generate
GET  /api/dsr/{id}/sql-pack/download
POST /api/dsr/{id}/executions/{eid}/mark-executed
```

## Webhook

### Config
UI Settings → Webhooks. URL + event filter + HMAC-SHA256 secret. Retry 3x exponential backoff.

### Event Types
- `ropa.created`, `ropa.updated`, `ropa.approved`, `ropa.deleted`
- `dpia.*`
- `dsr.created`, `dsr.deadline_alert`, `dsr.completed`
- `breach.detected`, `breach.escalated`, `breach.closed`
- `vendor.risk_changed`
- `consent.withdrawn`
- `ai.tool_executed`
- `audit.suspicious`

### Payload
```json
{
  "id": "evt_xxx",
  "type": "breach.detected",
  "created_at": "2026-04-25T10:00:00Z",
  "org_id": "org_abc",
  "data": {...},
  "signature": "sha256=xxx..."
}
```

### Signature Verify
```python
expected = hmac.new(secret, payload, sha256).hexdigest()
```

## SIEM Integration

Formats:
- **Syslog RFC 5424** (UDP/TCP TLS)
- **Splunk HEC**
- **Elastic ECS** (JSON to Logstash)
- **CEF** (Common Event Format)

Events: auth, config change, sensitive action, AI execution, breach, DSR deadline.

## IMAP (DSR Intake)

Tenant inbox `dsr@company.com`. Privasimu fetch via IMAP (credential encrypted):
- Subject → request type
- Body → detail
- Attachment → evidence
- Auto-create DSR + identity verification flow

## Slack / Teams

Slack Incoming Webhook per tenant. Events:
- Breach detected → action buttons (Ack, View, Escalate)
- DSR deadline alert
- RoPA approval request

Teams: Adaptive Card equivalent.

## Telegram Bot

Built-in `@PrivasimuBot`:
- User link via `/link <token>`
- Real-time alerts
- Reply `/ack` untuk acknowledge

## WhatsApp Deep-Link

Click-to-Chat (no WhatsApp Business API needed):
- `https://wa.me/<phone>?text=ACK+BREACH+BRC-2026-042`
- Embed di email breach subject notification
- Subject klik → compose message ke DPO

## Rate Limit

- 1000 req/min per API key default
- Burst 100 req/sec sustained
- Headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After`

## Swagger / OpenAPI

- `/api/openapi.json` — spec
- `/api/docs` — interactive doc
- Postman collection downloadable
- Code examples: Python, PHP, Node.js, curl
KB,
            ],

            [
                'module_key' => 'tech_document_generation',
                'title' => 'Document Generation — PDF + DOCX Engine',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'pdf,docx,word,template,placeholder,generate,export,dompdf,phpword,watermark,logo,cover,cloneRow,cloneBlock',
                'summary' => 'Document generation: PDF via dompdf 3.x, DOCX via PhpOffice PhpWord 1.x dengan TemplateProcessor. Placeholder library 74 RoPA, 50+ DPIA, 30+ Breach. cloneRow untuk table repeater, cloneBlock untuk section. 10 preset styling + custom upload. Branding white-label per tenant.',
                'content' => <<<'KB'
# Document Generation — PDF + DOCX

## Engine

### PDF (dompdf 3.x)
- Pure PHP, no external binary
- HTML + CSS rendering (flex, custom fonts)
- Output: PDF/A compliant optional

### DOCX (PhpOffice/PhpWord 1.x)
- TemplateProcessor dengan placeholder `${field}`
- cloneRow untuk tabel repeater
- cloneBlock untuk section repeater
- Image embed, conditional section

## Placeholder Library

- **RoPA**: 74 placeholder (number, activity, DPO, legal basis, data cat, retention, security, dll)
- **DPIA**: 50+ placeholder (risk score matrix, residual, mitigation, 21 category × 4 field)
- **Breach Report**: 30+ placeholder (timeline, RACI, RCA, notification status)

## Template Types

1. **System Canonical (Nexus)** — default, full-featured
2. **10 Preset Styling** — Corporate Classic, Government Formal, Modern Minimal, Dark Professional, Draft Watermark, Rounded Soft, Compact Tight, Academic Paper, Startup Vibrant, Legal Formal
3. **Custom Upload** — tenant upload template Word (3-template limit Professional, unlimited Enterprise)

## White-Label per Tenant

Branding di `/settings/branding`:
- Primary + accent color hex
- Logo + favicon + cover bg + watermark
- Font family (Google Fonts atau upload)
- Paper size (A3-A5, Letter, Legal, Folio)
- Margin, table style
- Email sender

## Generation Flow

### PDF
Data → load template → render Blade view → HTML → dompdf → PDF stream

### DOCX
Data → load .docx template → TemplateProcessor replace:
- Scalar: `${field}` → value
- List: cloneBlock
- Table: cloneRow
→ save temp → stream

## Custom Template Upload

1. Create Word dengan placeholder `${ropa_number}`
2. Upload UI `/document-templates`
3. Validator parse + list placeholder found vs expected
4. Preview dengan sample data
5. Assign per-kind (RoPA / DPIA / Breach)
6. Active

## Digital Signature

### PDF
PKCS#7 signature support. Upload tenant cert (.p12). Verifiable Adobe Reader + country PKI.

### DOCX
MS Word digital signature metadata embed.

## Performance

- PDF 10-page RoPA: 1-2 detik
- DOCX generation: <1 detik
- Bulk export 100 RoPA ZIP: 30-60 detik (queued)

## Troubleshooting

| Issue | Fix |
|---|---|
| Placeholder tidak replaced | Check spelling (case-sensitive), verify field exist |
| PDF rendering aneh | Clear cache, check HTML valid |
| DOCX corrupt | Harus valid .docx (bukan .doc legacy), max 10 MB, no macro |
KB,
            ],

            [
                'module_key' => 'tech_performance_scale',
                'title' => 'Performance & Scale — Platform Capacity',
                'category' => 'library',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'performance,scale,scalability,concurrent,kapasitas,user,response time,latency,load,benchmark,tps,rps,throughput',
                'summary' => 'Capacity per tier: Basic 50 concurrent user, Professional 200, AI 500, Enterprise 1000+. Response time p95 <500ms UI, 2-5s AI feature. DB handle 10M+ row per tabel. Horizontal scale via K8s untuk enterprise. Typical AI Auto-Fill 8-12s p50.',
                'content' => <<<'KB'
# Performance & Scale

## Concurrent User Capacity

| Tier | Concurrent | DB Pool | App Servers |
|---|---|---|---|
| Basic | 50 | 10 | 1 |
| Professional | 200 | 30 | 2 |
| AI / AI Agent | 500 | 50 | 3 |
| Enterprise | 1000+ | 100+ | 5+ (K8s) |

## Response Time p95

### UI / REST API
- GET list: <500ms (typical 200ms)
- GET detail: <300ms (120ms)
- POST create: <500ms (250ms)
- Search/filter: <800ms (400ms)
- Complex aggregation: <2000ms (800ms)

### AI Features
- AI Chat (streaming start): <2s (800ms)
- AI Chat full short response: 3-5s (3s)
- AI Auto-Fill RoPA: <30s (12s)
- AI Auto-Fill DPIA: <60s (25s)
- AI Contract Review 10p: <90s (40s)
- AI Policy Review: <60s (30s)
- AI Remediation Plan: <120s (45s)

### Data Discovery
- Test DB connect: 1-2s
- Schema enum 100 tabel: 30-60s
- PII scan 100 kolom: 2-5 menit
- Full scan 1000 tabel: 30 menit - 2 jam

## DB Capacity Per-Table

- RoPA: 100k per tenant
- DPIA: 100k per tenant
- DSR requests: 10 juta (5-year retention)
- Audit logs: 100 juta (partitioned per bulan)
- Consent records: 100 juta (partitioned)
- Breach: 10k per tenant

Tested 10M row per tabel tanpa degradation dengan indexing.

## Scaling Strategy

### Vertical
Basic 4vCPU/16GB → Professional 8/32 → Enterprise 16/64 per server.

### Horizontal (K8s)
- HPA auto-scale 2-10 replica
- Redis cluster
- PostgreSQL primary-replica
- NGINX LB + TLS

### DB Horizontal
- Read replica (reporting, dashboard)
- Primary single writer (tidak sharding app DB by design)

## Bottleneck Mitigation

1. PHP-FPM: scale workers, Laravel Octane
2. DB connection: PgBouncer / ProxySQL
3. Redis memory: cluster mode, TTL tuning
4. File I/O: S3/MinIO offload
5. AI rate limit: multi-provider failover

## Caching

- Session: Redis TTL 60min
- Permission check: Redis TTL 5min
- Menu/nav: Redis TTL 1hour
- Dashboard aggregates: Redis TTL 5min
- Static: NGINX + CDN

## Queue

Laravel Horizon Redis-backed:
- AI execution (long-running)
- Email dispatch
- PDF generation
- DB scan
- Webhook + retry
- Audit log shipping

500+ job/sec per worker, linear scale.

## SLA

### SaaS
- Uptime 99.9% Basic, 99.95% Pro, 99.99% Enterprise
- Response time per target above
- Support response 4-24h based on tier

### On-Prem
- Hardware SLA vendor (Dell/HPE 4-hour onsite)
- Software 4-24h tier-based

## Load Test Sample (Professional)

```
Concurrent: 200, Duration 30min
Throughput: 100 req/s
p50: 120ms, p95: 450ms, p99: 1200ms
Error rate: 0.03%
DB CPU 35% avg, App CPU 55% avg
```

## Monitoring

- Platform status: `status.privasimu.com`
- Tenant dashboard: real-time health, queue, AI quota
- Grafana embed (Enterprise)
- Prometheus + New Relic APM + Sentry
KB,
            ],

            // -------------------------------------------------------------
            // Leak Detection (inside Data Discovery)
            // -------------------------------------------------------------
            [
                'module_key' => 'feature_leak_detection',
                'title' => 'Leak Detection — Verify PII Value Existence in DB',
                'category' => 'workflow',
                'feature_tags' => 'chat,sales_faq,data_discovery',
                'keywords' => 'leak detection,kebocoran,deteksi leak,verify,cari data,search value,pii lookup,confirm leak,data bocor,exact match,contains match,sample masked',
                'summary' => 'Leak Detection = fitur di Data Discovery untuk verify apakah PII spesifik (NIK, email, phone, dll) benar-benar ADA di database tenant. Flow: (1) input list kolom yang dicari, (2) platform match schema tabel, (3) pilih tabel + input value, (4) exact/contains mode, (5) output query template + found_count + sample masked + leak_confirmed boolean. Tracked di history per-system.',
                'content' => <<<'KB'
# Leak Detection — Feature Detail

## Apa Itu?
Fitur di dalam **Data Discovery** (tab "Leak Detection") untuk verify apakah PII value spesifik benar-benar tersimpan di database klien. Use case:
- **DSR Access request**: subjek minta "apakah kamu simpan NIK saya?"
- **Post-breach confirmation**: verifikasi data yang di-claim bocor beneran ada di sistem
- **Audit internal**: DPO validate kepatuhan retention (data yang harusnya sudah dihapus, masih ada?)
- **Data subject verification**: saat identity verification, cek NIK input subjek match dengan DB

## Model Data

Table `leak_detections`:
- `system_id` — Information System mana
- `org_id` — tenant
- `user_id` — siapa trigger
- `table_name` — tabel target
- `match_mode` — 'exact' atau 'contains'
- `columns` (array) — kolom yang di-check
- `query_template` (array) — SQL yang dihasilkan (untuk audit)
- `found_count` — berapa row match
- `leak_confirmed` (boolean) — true kalau found_count > 0
- `sample_masked` (array) — preview hasil (PII di-mask)

## Flow End-to-End

### Step 1: Input Columns to Match
User input list kolom yang ingin dicari, misal:
```
nik, email, no_hp
```

Optional: **table hint** (kalau tahu tabel target).

### Step 2: Match Schema
Platform call `POST /data-discovery/{id}/leak/match-schema`:
- Scan Schema Registry Information System
- Cari tabel yang punya kolom match (exact atau fuzzy)
- Return ranked list: `[{table, confidence, matching_columns, missing_columns}]`

Contoh output:
```json
[
  {"table": "customers", "confidence": 95, "matching_columns": ["nik", "email", "no_hp"], "missing_columns": []},
  {"table": "leads", "confidence": 67, "matching_columns": ["email", "phone"], "missing_columns": ["nik", "no_hp"]}
]
```

### Step 3: Pick Table
DPO pilih tabel yang paling relevan (biasanya confidence tertinggi).

### Step 4: Input Values
Per-column, input value yang mau dicari:
```
nik: 3271234567890001
email: budi@example.com
no_hp: 081234567890
```

### Step 5: Pilih Match Mode
- **Exact**: `WHERE nik = '3271234567890001'` — untuk ID exact
- **Contains**: `WHERE email LIKE '%budi@example.com%'` — untuk partial match

### Step 6: Verify Leak
Platform call `POST /data-discovery/{id}/leak/verify`:
- Generate SQL template
- Execute via connection (kalau live mode) ATAU return SQL untuk admin eksekusi (generate-only mode)
- Count rows found
- Return sample with PII masked (e.g. "3271xxxx...xxx0001")

### Step 7: Result
```json
{
  "table": "customers",
  "match_mode": "exact",
  "query_template": {"sql": "SELECT * FROM customers WHERE nik = ? AND email = ? LIMIT 10"},
  "found_count": 3,
  "leak_confirmed": true,
  "sample": [
    {"nik": "3271xxxx...0001", "email": "b***@example.com", "no_hp": "0812xxxx7890"},
    ...
  ],
  "note": "Found 3 matching records. Data confirmed to exist in database."
}
```

## History Tracking

Every leak detection run saved di `leak_detections`:
- Per-system history
- Pagination
- Can delete (soft) atau clear all
- Audit trail dengan user_id + timestamp

## Privacy & Safety

### PII Masking
Sample return **always masked**:
- NIK: show first 4 + last 4 digit only, rest `x`
- Email: first char + `***@domain`
- Phone: first 4 + last 4 digit
- Nama: first name only

Platform **tidak pernah** expose raw PII value di UI setelah initial input.

### Authorization
- User harus punya permission `data_discovery:write`
- Bulk leak detection >100 rows → audit alert ke DPO
- High-frequency queries → rate limit (protect against reconnaissance attack)

### Query Safety
- Prepared statement (parameterized) — no SQL injection
- LIMIT 10 default (tidak pull data massal)
- Read-only (SELECT only, no INSERT/UPDATE/DELETE)

## Use Case Banking

Skenario bank nasabah:
```
DSR Access request masuk:
"Subjek NIK 3271234567890001 minta tau data yang Anda simpan."

DPO flow:
1. Buka Data Discovery → pilih Information System "Core Banking"
2. Tab Leak Detection
3. Input: columns = "nik", value = "3271234567890001", exact mode
4. Match Schema → pilih table "customers"
5. Verify → found_count = 1, leak_confirmed = true
6. Check kolom lain yang ada di row itu (nama, alamat, dll)
7. Output ke DSR response template
```

## Use Case Breach Investigation

Post-incident:
```
Ada claim data leak NIK subjek X + email Y di dark web.
DPO harus konfirmasi: benar-benar data kita?

Flow:
1. Leak Detection → input NIK + email dari leak claim
2. Match schema → find tabel yang simpan
3. Verify → kalau leak_confirmed TRUE → escalate Breach Module
4. Kalau FALSE → data bukan dari sistem kita (fake claim atau third-party leak)
```

## Generate-Only Mode (Bank Ketat)

Kalau klien pakai generate-only mode (platform tidak connect ke prod DB):
- Step 1-5 sama
- Step 6: platform generate SQL template + instruksi
- Admin bank eksekusi SQL di platform mereka
- Admin upload hasil (CSV atau text) kembali
- Privasimu parse + update leak_detection record

## API Endpoints

```
POST /api/data-discovery/{id}/leak/match-schema
  body: { columns: [], table_hint: string|null }
  → { matches: [], note: string }

POST /api/data-discovery/{id}/leak/verify
  body: { table: string, values: [{column, value}], match_mode: 'exact'|'contains' }
  → LeakVerifyResult (lihat di atas)

GET  /api/data-discovery/{id}/leak/history
  → { data: LeakHistoryRow[] }

DELETE /api/data-discovery/{id}/leak/history/{history_id}
  → soft delete

DELETE /api/data-discovery/{id}/leak/history
  → clear all history
```

## Integration dengan Module Lain

- **DSR Module**: Leak Detection result bisa langsung jadi input DSR Access response
- **Breach Module**: Leak Detection confirmed → auto-create Breach incident draft
- **Posture Score**: leak events contribute ke overall risk metric

## Best Practice

1. **Jangan over-use**: bukan tool untuk browse data. Fokus untuk DSR verify, breach confirmation, retention audit.
2. **Minimize match mode contains**: lebih banyak row return → risk over-disclosure
3. **Log alasan** di notes (optional): DPO tulis kenapa run leak detection ini (audit trail)
4. **Kombinasikan dengan consent check**: kalau data found tapi consent sudah withdrawn → flag retention violation
KB,
            ],

            // -------------------------------------------------------------
            // AI Patrol Scheduler
            // -------------------------------------------------------------
            [
                'module_key' => 'feature_ai_patrol',
                'title' => 'AI Patrol — Scheduled Data Discovery Scanner',
                'category' => 'workflow',
                'feature_tags' => 'chat,sales_faq,data_discovery,pii_scan',
                'keywords' => 'ai patrol,scheduler,schedule,automatic scan,cron,periodic,otomatis,drift detection,change detection,monitoring',
                'summary' => 'AI Patrol = scheduled scanner di Data Discovery. DPO set cron schedule (daily/weekly/monthly), platform auto-scan Information System, detect drift (kolom baru, PII classification changed, tabel baru). Alert DPO kalau ada perubahan. Cocok untuk compliance "continuous monitoring" instead of ad-hoc scan.',
                'content' => <<<'KB'
# AI Patrol — Scheduled Scanner

## Apa Itu?
Fitur scheduled scan otomatis di Data Discovery. Platform run scan berkala tanpa manual trigger, detect perubahan schema + PII landscape, alert DPO.

## Use Case
- **Continuous Compliance Monitoring** — daripada scan sekali setahun, patrol daily/weekly
- **Schema Drift Detection** — dev team tambah kolom PII baru ke prod tanpa sepengetahuan DPO → detect + alert
- **New Table Detection** — database migrate tambah tabel baru yang berisi PII → auto-register ke inventory
- **PII Classification Changes** — kolom yang dulunya dianggap non-PII (mis. kolom "reference") ternyata sekarang berisi NIK (app bug?) → alert
- **Compliance Reporting** — monthly dashboard "PII landscape change" untuk report ke Direksi

## Config

Admin setup di `/data-discovery/{id}/ai-patrol`:
- **Schedule**: cron expression
  - Daily: `0 2 * * *` (2am setiap hari)
  - Weekly: `0 2 * * 1` (Senin 2am)
  - Monthly: `0 2 1 * *` (tanggal 1 2am)
- **Scope**: semua tabel atau subset
- **Notification channels**: email + Telegram + in-app
- **Alert severity threshold**: cuma critical? all change?

## What Changes Detected

### Schema Changes
- Tabel baru yang muncul
- Tabel yang di-drop
- Kolom baru yang di-add
- Kolom di-rename
- Type change (`VARCHAR(50)` → `TEXT`)
- Constraint change (NOT NULL → NULL, UNIQUE added/removed)

### PII Classification Drift
- Kolom previously classified "phone" sekarang data-nya mostly email format
- Kolom previously "reference_id" ternyata berisi NIK
- Confidence score turun signifikan (data pattern berubah)

### Volume Changes
- Jumlah row naik drastis (10x) — unusual intake
- Jumlah row turun drastis — mungkin bulk delete yang tidak tercatat

## Execution Flow

```
Cron trigger → AI Patrol Job (Laravel queue)
  ↓
1. Load Information System config
2. Run scan (Standard View + AI View opsional)
3. Compare hasil vs last scan snapshot
4. Calculate diff:
   - Added columns/tables
   - Removed
   - Changed classification
   - Volume delta
  ↓
5. Kalau ada diff significant → save to changelog
6. Kalau severity >= threshold → dispatch notification
7. Update Posture Score
```

## Notification Detail

Email/Telegram sample:
```
🔔 AI Patrol Alert — Information System "Core Banking"

Changes detected (2026-04-25 02:00 WIB):

🆕 New PII Columns:
  - customers.loyalty_card_number (classified as: card_number, confidence 92%)
  - customer_kyc.selfie_url (classified as: biometric, confidence 88%)

⚠️ Classification Changes:
  - customers.reference_v2 — previously "internal_ref", now classified as "NIK" (98% match)
    → Possible app bug menyimpan NIK ke kolom yang dimaksud internal ref?

📈 Volume Changes:
  - transactions table: +2.3M rows sejak last scan (expected growth: ~500k)

Action required:
→ Review classification changes
→ Update RoPA kalau new column PII-bearing
→ Investigate volume anomaly
```

## History / Changelog

Tab `Changelog` di Data Discovery menunjukkan timeline:
- Kapan scan run
- Apa yang berubah
- Siapa acknowledge (DPO mark reviewed)
- Link ke affected RoPA yang mungkin perlu update

## Integration

- **RoPA**: auto-suggest update RoPA kalau new PII column detected di sistem yang link RoPA
- **DPIA**: alert kalau data spesifik (biometrik, health, child) muncul baru
- **Posture Score**: drift signifikan menurunkan score
- **Audit Log**: patrol run + detection tercatat

## Performance

- Run saat off-peak (middle of night)
- Scan incremental — compare cached metadata, bukan full re-scan
- Typical duration: 5-30 menit per Information System
- Queue via Horizon — tidak block other operations

## Permissions

- **Enable/disable schedule**: `data_discovery:write`
- **View changelog**: `data_discovery:read`
- **Force manual run**: `data_discovery:write`

## Config Override

Per-schedule override:
- Skip kolom tertentu (blacklist)
- Focus subset schema saja (whitelist)
- Different alert severity threshold per environment

## Cost (AI Credits)

- Standard View only: zero AI credit (rule-based)
- AI View enabled: ~500-2000 token per column, depends on schema size
- Typical monthly cost untuk medium DB (500 kolom scan daily): ~300k token
KB,
            ],

            // -------------------------------------------------------------
            // Shadow Data + Encryption Keys + Protection
            // -------------------------------------------------------------
            [
                'module_key' => 'feature_shadow_data',
                'title' => 'Shadow Data Detection & Protection',
                'category' => 'workflow',
                'feature_tags' => 'chat,sales_faq,data_discovery,pii_scan',
                'keywords' => 'shadow data,bayangan,hidden data,undocumented,belum tercatat,ropa coverage,shadow detection,protection,encryption keys,unregistered',
                'summary' => 'Shadow Data = PII yang ada di DB tapi TIDAK tercatat di RoPA (no legal basis, no retention policy, no safeguards documented). Privasimu auto-detect saat scan: kolom PII-bearing tapi tidak ada RoPA link. Flag sebagai compliance risk. Protection tab kasih recommendation: register ke RoPA atau delete/anonymize.',
                'content' => <<<'KB'
# Shadow Data Detection

## Apa Itu?
**Shadow Data** = data pribadi yang benar-benar ada di sistem tapi **tidak tercatat / tidak terdokumentasi** di RoPA organisasi. Risk besar karena:
- Tidak ada legal basis documented
- Tidak ada retention policy → mungkin over-retention
- Tidak ada safeguards formal
- Tidak ter-scope DSR (subjek request tidak reach data ini)
- Compliance gap saat audit

## Cara Detect

Platform cross-reference:
```
Schema Registry hasil scan (actual PII in DB)
  VS
RoPA records (documented processing activity)

Delta = Shadow Data
```

Contoh:
- Data Discovery scan tabel `user_demographics` → detect kolom `salary`, `medical_conditions` classified sebagai sensitive PII
- RoPA inventory cek: tidak ada RoPA yang mention pemrosesan `user_demographics`
- **Flag: Shadow Data** — kolom sensitive ada tapi tidak ada RoPA

## Severity Classification

| Shadow Data Type | Severity |
|---|---|
| Data sensitif (biometrik/kesehatan/anak) tanpa RoPA | 🔴 Critical |
| Data umum (nama, email) tanpa RoPA | 🟠 High |
| Data sensitif dengan RoPA partial (kolom belum listed) | 🟡 Medium |
| Kolom test/staging yang tidak dihapus | 🟡 Medium |

## UI Flag

Di Data Discovery per-column:
```
✅ Documented in RoPA: RoPA-042 "Customer Registration"
⚠️ Shadow Data: No RoPA coverage
```

Shadow Data counter di dashboard (overall compliance health metric).

## Protection Tab

Recommendation engine per shadow data:
1. **Register to RoPA** — create RoPA baru atau update existing
2. **Delete** — kalau data seharusnya tidak ada (dev leak, old migration)
3. **Anonymize** — keep data tapi remove PII (untuk analytics)
4. **Encrypt** — enhanced protection selama investigasi
5. **Acknowledge as accepted risk** — temporary, dengan legal sign-off

## Encryption Keys Management

Tab `Encryption Keys` per Information System:
- Generate encryption key per-kolom PII (data-at-rest encryption tambahan)
- Key rotation schedule
- HSM integration (Enterprise tier)
- Audit log key usage

Use case:
- Kolom NIK super-sensitive → encrypted with dedicated key
- Key rotation quarterly
- Compromised key → rotate, decrypt-reencrypt batch job

## Integration

- **RoPA wizard**: "Add from Shadow Data" shortcut auto-populate dari detected
- **DPIA**: shadow data sensitive → auto-suggest DPIA
- **Remediation Plan**: shadow data included di priority action
- **Posture Score**: shadow data count negatif contribute

## Reporting

- Weekly "Shadow Data Report" email ke DPO
- Trend chart: shadow data count over time (decreasing = improving compliance)
- Export CSV untuk audit
KB,
            ],

            // -------------------------------------------------------------
            // Generic feature inventory (catch-all untuk AI awareness)
            // -------------------------------------------------------------
            [
                'module_key' => 'platform_feature_inventory',
                'title' => 'Privasimu Nexus — Complete Feature Inventory',
                'category' => 'example',
                'feature_tags' => 'chat,sales_faq',
                'keywords' => 'fitur,feature,modul,module,inventory,daftar,list,apa saja,semua,complete,lengkap,tersedia,available',
                'summary' => 'Complete list semua fitur Privasimu Nexus: RoPA, DPIA, GAP, LIA, TIA, Maturity, DSR, Consent, Cross-Border, Breach, Simulation, Vendor TPRM, Data Discovery (+ Leak Detection, AI Patrol, Shadow Data, Encryption Keys), AI Agent, Auto-Fill, Contract Review, Policy Review, Remediation Plan, Document Templates, RACI Templates, Retention Master, Knowledge Base, dan 12+ admin feature.',
                'content' => <<<'KB'
# Privasimu Nexus — Complete Feature Inventory

## AI tidak boleh bilang "feature X tidak ada" tanpa cek list ini

Kalau user tanya fitur yang tidak terlihat langsung, **cek list ini dulu**. Feature mungkin ada tapi nested di modul utama.

## 1. Assessment Engine (Compliance Evaluation)

- **RoPA** — Records of Processing Activities (wizard 7-step)
- **DPIA** — Data Protection Impact Assessment (21 kategori, matrix 5×5)
- **GAP Assessment** — 33 pertanyaan UU PDP + multi-regulation (GDPR, PDPA, ISO 27701)
- **LIA** — Legitimate Interest Assessment (3-step balancing test)
- **TIA** — Transfer Impact Assessment (cross-border scoring)
- **Maturity Assessment** — 5-level privacy maturity

## 2. Subject Rights & Consent

- **DSR** — Data Subject Rights (7 tipe: access, correction, deletion, portability, withdraw, objection, information)
- **DSR SQL Generator** — platform generate SQL pack, admin eksekusi (generate-only mode untuk bank)
- **Consent Management** — Collection Points, Consent Records, version tracking
- **Preference Center** — public URL per-subjek untuk withdraw consent
- **Consent Widget** — JS snippet drop-in + iframe embed
- **Cross-Border Transfer Registry** — SCC/BCR tracker

## 3. Incident Response

- **Breach Management** — 15 SOP containment templates, 72h countdown
- **RACI Matrix editor per-breach** — R/A/C/I per step editable
- **Containment Checklist** adaptive per SOP type
- **Multi-RoPA linkage** — 1 breach ↔ banyak RoPA
- **KOMDIGI + Subject Notification** auto-generate PDF/email
- **Breach Simulation (Drill)** — tabletop + full drill + scoring

## 4. Vendor Risk Management

- **Vendor Registry** dengan DPA/MSA/NDA attachment
- **Assessment Questionnaire** — 50+ pertanyaan
- **Risk Scoring per-vendor**
- **Periodic Reassessment Reminder**
- **AI Document Screening** — DPA parser

## 5. Data Discovery & Inventory

- **PII Scanner** — 2 mode (Live Scan + Generate-Only)
- **Standard View** — rule-based regex + context
- **AI View / DeepScan** — LLM + NER semantic classification
- **Schema Registry** — table + column metadata
- **Sharded DB Support** — 1 logical system, N physical shards
- **Leak Detection** — verify PII value existence in DB
- **AI Patrol** — scheduled auto-scan dengan drift detection
- **Shadow Data Detection** — PII without RoPA coverage
- **Encryption Keys Management** — per-column key + rotation
- **Protection Tab** — recommendation engine per kolom
- **Changelog** — schema drift history
- **AI Search** — natural language search DB

## 6. AI Features

- **AI Agent** — Conversational Compliance Assistant dengan function calling
- **AI Auto-Fill RoPA** — generate 7-section dari deskripsi
- **AI Auto-Fill DPIA** — generate 21 kategori + matrix
- **AI Contract Review** — DPA klausul analyzer
- **AI Policy Review** — Privacy Policy/SOP UU PDP checker
- **AI Remediation Plan** — dari GAP Assessment ke action plan
- **AI Document Import** — bulk parse legacy RoPA/DPIA
- **AI OCR Scanner** — KTP, form fisik, kontrak scan
- **AI Search di Data Discovery** — natural query → SQL

## 7. Documentation & Templates

- **Document Templates** — 10 preset styling + custom upload
- **Per-Kind Assignment** — template A untuk RoPA, B untuk DPIA, dll
- **Nexus Canonical DOCX** — default system template
- **PhpWord TemplateProcessor** — cloneRow + cloneBlock + placeholder
- **RACI Templates Library** — reusable preset (DPO-led, IT-led, dll)
- **Retention Policies Master Data** — reusable across RoPA
- **Knowledge Base CMS** — markdown editor untuk internal docs

## 8. Admin / Platform

- **User Management + Roles** — hierarchical + RBAC
- **Custom Tenant Roles** — define role sendiri (enterprise)
- **SSO** — SAML 2.0 + OIDC + JIT provisioning
- **Multi-Channel Notifications** — email + Telegram + WhatsApp + Slack + Teams + Push + in-app
- **Approval Workflow** — multi-level dengan timeout escalation
- **Audit Log** — immutable 5-year retention
- **Branding (White-Label)** — color, logo, domain, email sender, watermark
- **License Manager** — 5 tier (Basic → Enterprise Perpetual)
- **Menu Control** — enable/disable per tenant, user preferences
- **Custom Fields** — per-modul tanpa migrasi (JSON)
- **Cloud Storage** — S3/MinIO/GCS per-tenant (encrypted credential)
- **API Keys + Developer Hub** — self-service, Swagger, scope
- **Tenant Offboarding** — 14-day export + hard-delete certificate
- **Alert Engine** — threshold-based alerting
- **Automation Engine** — rule-based workflow
- **Voice TTS Providers** — multi-engine selector
- **AI Provider Config** — switchable OpenRouter/OpenAI/Anthropic/Gemini/DeepSeek/Qwen
- **Changelog (Platform)** — release notes per tenant dashboard

## 9. Enterprise Specific

- **Holding Dashboard** — cross-tenant aggregated (parent-child structure)
- **Document Import Bulk** — legacy RoPA/DPIA migration
- **Enterprise Roadmap** — priority feature request backlog
- **Dedicated TAM** — technical account manager
- **Premium SLA** — 4-hour response mission-critical

## 10. Developer / Integration

- **REST API Full CRUD** — all module
- **Webhook** — event push dengan HMAC signature
- **SIEM Integration** — Splunk/Elastic/QRadar/Azure Sentinel
- **IMAP Intake** — DSR via email
- **Slack / Teams Bot** — alert + approval
- **Telegram Bot** — real-time notification
- **WhatsApp Deep-Link** — no Business API needed
- **Swagger/OpenAPI 3.0** spec

## 11. Security & Compliance

- **AES-256-CBC Encryption at Rest** (EncryptedString cast)
- **SSL/TLS in Transit**
- **bcrypt Password Hashing**
- **Sanctum Token Auth** (60min + refresh)
- **Rate Limiting** (per IP, per user, per endpoint)
- **CSRF Protection**
- **Org_id Multi-Tenancy Isolation** (enforced)
- **Certificate Target**: ISO 27001 (Q2 2026), SOC 2 Type II (Q4 2026), ISO 27701 (Q4 2026)

## 12. Business Intelligence

- **Posture Score** — unified compliance dashboard
- **Trend Charts** — historical tracking
- **Multi-Regulation Support** — UU PDP + GDPR + PDPA + ISO 27701
- **Benchmark Industri** — compare dengan sektor
- **Export Reports** — PDF/DOCX/CSV

## Aturan Penting untuk AI Agent

1. **Jangan bilang "fitur X tidak ada"** — cek list di atas dulu
2. **Kalau user pakai sinonim** (misal "scan PII" = "Data Discovery Scanner"), match ke fitur yang sesuai
3. **Kalau tidak yakin fitur ada/tidak**, **jawab "Saya cek dulu di sistem"** → call tool list_information_systems atau similar
4. **Jangan halu**: jangan invent fitur yang tidak ada di list ini
5. **Defer ke docs atau sales** kalau benar-benar tidak tahu
KB,
            ],
        ];
    }
}
