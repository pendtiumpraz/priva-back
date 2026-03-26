<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\KnowledgeBaseSection;

class KnowledgeBaseSectionsSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            [
                'module_key' => 'general',
                'title' => 'Informasi Umum PRIVASIMU',
                'sort_order' => 0,
                'keywords' => 'privasimu,platform,apa itu,tentang,fitur,harga,paket,license,kontak,bantuan,help,menu',
                'content' => <<<'KB'
# PRIVASIMU — Platform Kepatuhan UU PDP

## Tentang PRIVASIMU
PRIVASIMU adalah platform SaaS untuk membantu organisasi mematuhi UU Pelindungan Data Pribadi (UU No. 27 Tahun 2022 / UU PDP). Platform ini multi-tenant — setiap organisasi memiliki data terpisah dan aman.

## Modul yang Tersedia
1. **Dashboard** — Ringkasan kepatuhan real-time
2. **Gap Assessment** — Analisis kesenjangan UU PDP
3. **ROPA** — Record of Processing Activities
4. **DPIA** — Data Protection Impact Assessment
5. **Data Breach Management** — Penanganan insiden kebocoran data
6. **DSR** — Data Subject Request (Hak Subjek Data)
7. **Consent Management** — Manajemen persetujuan
8. **Fire Drill / Simulasi** — Latihan respons insiden
9. **Data Discovery** — Pemetaan data
10. **Dokumentasi** — Panduan & SOP

## Paket License
| Paket | Fitur |
|-------|-------|
| **Basic** | Semua modul compliance tanpa AI |
| **Pro (AI)** | Semua modul + AI Assistant + AI Risk Scoring + Live Drill |
| **Enterprise (AI Agent)** | Semua fitur Pro + AI Agent otomatis |

## Kontak & Dukungan
- **PT Sainskerta Solusi Nusantara**
- Kontak: 081319504441 (Galih)
- Email: hello@sainskerta.net
KB
            ],
            [
                'module_key' => 'dashboard',
                'title' => 'Dashboard',
                'sort_order' => 1,
                'keywords' => 'dashboard,beranda,statistik,kpi,chart,grafik,skor,score,ringkasan,overview',
                'content' => <<<'KB'
# Dashboard

## Fungsi
Dashboard menampilkan ringkasan kepatuhan organisasi secara real-time dalam satu halaman.

## Komponen Dashboard
1. **KPI Cards** — Total ROPA, DPIA, Breach Incidents, Gap Score
2. **Compliance Score** — Persentase kepatuhan berdasarkan Gap Assessment
3. **Grafik Tren** — Tren kepatuhan dari waktu ke waktu
4. **Distribusi Risiko** — Pie chart: Low, Medium, High risk items
5. **Timeline Aktivitas** — Log aktivitas terbaru tim
6. **Breach Countdown** — Insiden breach aktif dengan countdown 72 jam
7. **Quick Actions** — Shortcut ke modul yang sering digunakan

## Cara Membaca Dashboard
- **Skor Hijau (≥80%)**: Kepatuhan baik
- **Skor Kuning (50-79%)**: Perlu perbaikan
- **Skor Merah (<50%)**: Sangat perlu ditangani segera

## Siapa yang Bisa Akses
Semua role bisa melihat Dashboard (SuperAdmin, Admin, DPO, Maker, Viewer).
KB
            ],
            [
                'module_key' => 'gap_assessment',
                'title' => 'Gap Assessment',
                'sort_order' => 2,
                'keywords' => 'gap,assessment,analisis,kesenjangan,kepatuhan,compliance,pertanyaan,domain,skor,nilai',
                'content' => <<<'KB'
# Gap Assessment (Analisis Kesenjangan UU PDP)

## Fungsi
Mengukur tingkat kepatuhan organisasi terhadap UU PDP melalui kuesioner terstruktur.

## Struktur
- **62 pertanyaan** berdasarkan UU PDP
- **7 domain** kepatuhan:
  1. Kebijakan & Tata Kelola
  2. Pemrosesan Data
  3. DPIA (Penilaian Dampak)
  4. Hak Subjek Data
  5. Penanganan Breach
  6. Transfer Data Lintas Batas
  7. Organisasi & SDM

## Cara Mengisi
1. Klik **"Gap Assessment"** di sidebar
2. Pilih **"Mulai Assessment Baru"** atau lanjutkan yang ada
3. Jawab setiap pertanyaan dengan pilihan: Belum, Sebagian, Sudah
4. Setelah selesai, klik **"Submit"**
5. Lihat hasil: skor per domain + rekomendasi

## Scoring
- **Awal (0-25%)** — Baru mulai, banyak yang harus dikerjakan
- **Berkembang (26-50%)** — Sudah ada upaya tapi belum konsisten
- **Terkelola (51-75%)** — Sebagian besar sudah comply
- **Optimized (76-100%)** — Kepatuhan sangat baik

## Tips
- Libatkan DPO dan IT Security dalam pengisian
- Lakukan assessment secara berkala (minimal setiap 6 bulan)
- Gunakan rekomendasi untuk membuat action plan
KB
            ],
            [
                'module_key' => 'ropa',
                'title' => 'ROPA (Record of Processing Activities)',
                'sort_order' => 3,
                'keywords' => 'ropa,record,processing,activities,pemrosesan,wizard,langkah,risk,risiko,data kategori,legal basis,retensi',
                'content' => <<<'KB'
# ROPA (Record of Processing Activities)

## Fungsi
Mencatat seluruh aktivitas pemrosesan data pribadi di organisasi, sesuai kewajiban UU PDP Pasal 31.

## Wizard 6 Langkah
1. **Identifikasi** — Nama aktivitas, divisi, penanggung jawab
2. **Tujuan Pemrosesan** — Alasan pemrosesan + dasar hukum (legal basis)
3. **Kategori Data** — Jenis data yang diproses (umum/sensitif)
4. **Keamanan** — Langkah-langkah perlindungan data
5. **Review** — Ringkasan sebelum submit
6. **Submit** — Simpan dan generate kode ROPA

## Kategori Legal Basis (Dasar Hukum)
- Persetujuan (Consent)
- Kontrak
- Kewajiban Hukum
- Kepentingan Vital
- Tugas Publik
- Kepentingan Sah (Legitimate Interest)

## Risk Level Otomatis
- Data **sensitif** (kesehatan, biometrik, genetik, anak, ras, agama) → **HIGH risk** → otomatis generate DPIA
- Data **umum** → LOW/MEDIUM risk berdasarkan volume dan tujuan

## Tips
- Setiap departemen/divisi harus memiliki minimal 1 ROPA
- Update ROPA setiap ada perubahan proses bisnis
- ROPA dengan risk HIGH wajib ditindaklanjuti dengan DPIA
KB
            ],
            [
                'module_key' => 'dpia',
                'title' => 'DPIA (Data Protection Impact Assessment)',
                'sort_order' => 4,
                'keywords' => 'dpia,impact,assessment,penilaian,dampak,risiko,mitigasi,likelihood,skor risiko,high risk',
                'content' => <<<'KB'
# DPIA (Data Protection Impact Assessment)

## Fungsi
Menilai dampak pemrosesan data pribadi berisiko tinggi terhadap hak dan kebebasan subjek data.

## Kapan DPIA Wajib?
- Pemrosesan data **sensitif** (kesehatan, biometrik, dll)
- **Profiling** atau pengambilan keputusan otomatis
- **Pemantauan sistematis** skala besar
- ROPA dengan risk level **HIGH**

## Wizard 4 Langkah
1. **Identifikasi** — Deskripsi pemrosesan, tujuan, scope
2. **Analisis Risiko** — Likelihood × Impact = Risk Score
3. **Mitigasi** — Langkah-langkah mengurangi risiko
4. **Review** — Kesimpulan dan rekomendasi

## Scoring
- **Likelihood** (1-5): Kemungkinan risiko terjadi
- **Impact** (1-5): Dampak jika risiko terjadi
- **Risk Score** = Likelihood × Impact
  - 1-8: LOW (hijau)
  - 9-15: MEDIUM (kuning)
  - 16-25: HIGH (merah)

## Hasil DPIA
- Ringkasan skor per kategori risiko
- Rekomendasi mitigasi spesifik
- Status: Draft → In Review → Approved → Needs Action

## Tips
- DPIA harus dilakukan SEBELUM pemrosesan dimulai
- DPO wajib terlibat dalam review DPIA
- Simpan bukti DPIA untuk audit
KB
            ],
            [
                'module_key' => 'breach',
                'title' => 'Data Breach Management',
                'sort_order' => 5,
                'keywords' => 'breach,kebocoran,insiden,72 jam,komdigi,notifikasi,containment,assessment,fase,terdeteksi,ditutup',
                'content' => <<<'KB'
# Data Breach Management

## Fungsi
Menangani insiden kebocoran data pribadi sesuai kewajiban UU PDP Pasal 46.

## 5 Fase Penanganan Breach
### Fase 1: Terdeteksi
- Insiden baru masuk ke sistem
- Input: deskripsi insiden, sumber, severity awal
- **Countdown 72 jam dimulai**

### Fase 2: Assessment
- Investigasi mendalam
- Identifikasi: data apa yang terdampak, berapa subjek, akar penyebab
- Klasifikasi severity: Low / Medium / High / Critical
- Checklist assessment harus diselesaikan sebelum lanjut

### Fase 3: Containment
- Tindakan menghentikan kebocoran
- 10 item checklist containment:
  1. Isolasi sistem terdampak
  2. Reset kredensial
  3. Nonaktifkan akun terkompromi
  4. Blokir IP penyerang
  5. Patch vulnerability
  6. Backup data yang tersisa
  7. Aktifkan monitoring tambahan
  8. Koordinasi dengan IT Security
  9. Dokumentasi tindakan
  10. Verifikasi containment berhasil

### Fase 4: Notifikasi
- **Wajib dalam 3×24 jam** (72 jam) ke KOMDIGI
- Notifikasi ke subjek data yang terdampak
- Template notifikasi tersedia otomatis
- Isi: kronologi, data terdampak, tindakan yang diambil, kontak DPO

### Fase 5: Ditutup
- Insiden selesai ditangani
- Lessons learned documented
- Update prosedur untuk mencegah berulang

## RACI Matrix
| Peran | Tanggung Jawab |
|-------|----------------|
| DPO | Responsible — koordinator utama |
| IT Security | Containment teknis |
| Legal | Aspek hukum & notifikasi |
| Manajemen | Approval & eskalasi |
| PR/Comms | Komunikasi ke publik |

## Tips
- SELALU catat timeline dengan akurat
- Jangan hapus bukti (digital forensics)
- Komunikasi internal harus terdokumentasi
KB
            ],
            [
                'module_key' => 'dsr',
                'title' => 'DSR (Data Subject Request)',
                'sort_order' => 6,
                'keywords' => 'dsr,hak,subjek,data,request,akses,koreksi,hapus,delete,portabilitas,tarik consent,deadline',
                'content' => <<<'KB'
# DSR (Data Subject Request)

## Fungsi
Mengelola permintaan hak subjek data sesuai UU PDP Pasal 6-13.

## Jenis Hak Subjek Data
1. **Hak Akses** — Subjek berhak tahu data apa yang diproses
2. **Hak Koreksi** — Memperbaiki data yang tidak akurat
3. **Hak Hapus** — Meminta penghapusan data (right to erasure)
4. **Hak Portabilitas** — Mendapatkan salinan data dalam format terstruktur
5. **Tarik Consent** — Membatalkan persetujuan pemrosesan

## Cara Menggunakan
1. Klik **"DSR Request"** di sidebar
2. Klik **"Tambah Request"**
3. Isi: nama pemohon, jenis hak, data yang diminta, bukti identitas
4. Sistem otomatis set deadline (default: 3 hari kerja)
5. Proses request → ubah status ke "In Progress"
6. Selesaikan → upload bukti tindakan → tandai "Completed"

## Deadline
- UU PDP: response dalam **3 hari kerja** (dapat diperpanjang dengan alasan)
- Sistem akan memberikan notifikasi H-1 deadline
- Request yang lewat deadline akan ditandai merah

## Tips
- Verifikasi identitas pemohon sebelum memproses
- Dokumentasikan alasan jika menolak request
- Simpan log sebagai bukti kepatuhan
KB
            ],
            [
                'module_key' => 'consent',
                'title' => 'Consent Management',
                'sort_order' => 7,
                'keywords' => 'consent,persetujuan,izin,collection point,audit trail,tarik,revoke,cookie',
                'content' => <<<'KB'
# Consent Management

## Fungsi
Mengelola persetujuan pengumpulan dan pemrosesan data pribadi sesuai UU PDP.

## Fitur Utama
1. **Collection Points** — Titik-titik pengumpulan consent (form, website, app)
2. **Consent Records** — Rekam jejak setiap consent yang diberikan
3. **Audit Trail** — Log lengkap: kapan, siapa, consent apa, channel apa
4. **Revoke Management** — Proses pencabutan consent

## Cara Menggunakan
1. Buka **"Consent Mgmt"** di sidebar
2. **Tambah Collection Point**: nama, deskripsi, tujuan, jenis data
3. Catat setiap consent yang masuk
4. Monitor status: Active, Expired, Revoked
5. Proses permintaan revoke (tarik consent)

## Persyaratan Consent Valid (UU PDP)
- Diberikan secara **bebas** (tidak dipaksa)
- **Spesifik** untuk tujuan tertentu
- **Informatif** — subjek tahu data apa dan untuk apa
- **Tegas** — pernyataan eksplisit (bukan default centang)

## Tips
- Setiap tujuan pemrosesan harus punya consent terpisah
- Consent bisa dicabut kapan saja
- Simpan bukti consent untuk audit
KB
            ],
            [
                'module_key' => 'simulation',
                'title' => 'Fire Drill / Simulasi',
                'sort_order' => 8,
                'keywords' => 'simulasi,fire drill,latihan,quiz,tabletop,sop,live drill,ransomware,skenario,drill',
                'content' => <<<'KB'
# Fire Drill / Simulasi

## Fungsi
Melatih kesiapan tim dalam menghadapi insiden keamanan data melalui berbagai mode simulasi.

## 4 Mode Simulasi
### 1. Quiz
- Pertanyaan tentang prosedur dan UU PDP
- Multiple choice, auto-scoring
- Cocok untuk pelatihan rutin

### 2. Tabletop Exercise
- Diskusi skenario insiden secara verbal
- Moderator membacakan skenario, tim membahas respons
- Tidak perlu sistem teknis

### 3. SOP Walkthrough
- Jalan-jalan melalui Standard Operating Procedure
- Step-by-step verifikasi apakah SOP sudah dipahami
- Checklist per langkah

### 4. Live Visual Drill
- **Simulasi real-time** dengan efek visual
- Screen shake, flashing, alert suara
- Skenario: Ransomware Attack, Data Exfiltration
- Timer real-time, decision points
- Penilaian performa tim: A-F grade

## Cara Menjalankan
1. Buka **"Fire Drill"** di sidebar
2. Pilih mode simulasi
3. Pilih skenario (atau buat custom)
4. Set peserta dan peran
5. Mulai simulasi
6. Review hasil dan skor

## Tips
- Lakukan simulasi minimal 2x setahun  
- Variasikan skenario agar tim tidak terbiasa
- Dokumentasikan hasil untuk perbaikan
KB
            ],
            [
                'module_key' => 'data_discovery',
                'title' => 'Data Discovery & Mapping',
                'sort_order' => 9,
                'keywords' => 'data,discovery,mapping,pemetaan,alur,sumber,tujuan,kategori,inventaris',
                'content' => <<<'KB'
# Data Discovery & Mapping

## Fungsi
Memetakan seluruh alur data pribadi dalam organisasi — dari mana data masuk, kemana mengalir, dan dimana disimpan.

## Fitur
1. **Data Inventory** — Daftar semua jenis data yang diproses
2. **Flow Mapping** — Visualisasi alur data antar sistem
3. **Source & Destination** — Identifikasi sumber dan tujuan data
4. **Category Tagging** — Label data: umum, sensitif, anak, dll

## Cara Menggunakan
1. Buka **"Data Discovery"** di sidebar
2. Tambah entry: sistem, database, atau aplikasi
3. Map data: data apa masuk → proses apa → keluar kemana
4. Tag kategori data
5. Review dan update berkala

## Manfaat
- Memudahkan pembuatan ROPA
- Identifikasi data sensitif yang mungkin terlewat
- Basis untuk DPIA
- Membantu response saat breach (tahu data apa yang terdampak)
KB
            ],
            [
                'module_key' => 'users',
                'title' => 'User Management & Role',
                'sort_order' => 10,
                'keywords' => 'user,management,role,akses,permission,superadmin,admin,dpo,maker,viewer,tambah user,hapus user',
                'content' => <<<'KB'
# User Management & Role

## Role yang Tersedia
| Role | Akses |
|------|-------|
| **SuperAdmin** | Akses penuh ke semua tenant, manajemen user global, konfigurasi platform, license, AI settings |
| **Admin** | Manajemen tenant sendiri, user di organisasinya, input license |
| **DPO** | Data Protection Officer — akses semua modul compliance |
| **Maker** | Input dan edit data di modul compliance |
| **Viewer** | Hanya bisa melihat data (read-only) |

## Cara Menambah User
1. Buka **"User Management"** di sidebar (hanya Admin/SuperAdmin)
2. Klik **"Tambah User"**
3. Isi: nama, email, role, divisi
4. User akan menerima email undangan
5. User login dan mulai menggunakan platform

## Cara Mengubah Role
1. Di User Management, klik user yang ingin diubah
2. Pilih role baru
3. Simpan — akses berubah langsung

## Tips
- Minimal 1 DPO per organisasi
- Berikan role sesuai kebutuhan (principle of least privilege)
- Review role secara berkala
KB
            ],
            [
                'module_key' => 'uu_pdp',
                'title' => 'UU Pelindungan Data Pribadi',
                'sort_order' => 11,
                'keywords' => 'uu pdp,undang-undang,hukum,pasal,pelindungan,data pribadi,sensitif,komdigi,sanksi,denda',
                'content' => <<<'KB'
# UU Pelindungan Data Pribadi (UU No. 27 Tahun 2022)

## Tentang
UU PDP adalah undang-undang yang mengatur pelindungan data pribadi di Indonesia, berlaku sejak 2022.

## Pasal-Pasal Penting
- **Pasal 4**: Jenis data pribadi (umum & spesifik/sensitif)
- **Pasal 6-13**: Hak-hak subjek data
- **Pasal 16-19**: Kewajiban pengendali data
- **Pasal 20**: Record of Processing Activities (ROPA) wajib
- **Pasal 34**: DPIA untuk pemrosesan berisiko tinggi
- **Pasal 46**: Notifikasi breach dalam 3×24 jam
- **Pasal 57**: Lembaga pengawas (KOMDIGI)

## Data Sensitif (Pasal 4 ayat 2)
- Kesehatan
- Biometrik
- Genetik
- Data anak
- Catatan keuangan
- Ras dan etnis
- Agama/kepercayaan
- Orientasi seksual
- Pandangan politik
- Catatan kejahatan

## Sanksi
- Sanksi administratif: teguran, denda, penghentian pemrosesan
- Pidana: penjara maksimal 5 tahun / denda miliar
- Perdata: ganti rugi kepada subjek data

## Tips
- UU PDP berlaku untuk SEMUA organisasi yang memproses data pribadi penduduk Indonesia
- Tidak ada pengecualian berdasarkan ukuran organisasi
- Compliance harus proaktif, bukan reaktif
KB
            ],
        ];

        foreach ($sections as $section) {
            KnowledgeBaseSection::updateOrCreate(
                ['module_key' => $section['module_key']],
                $section
            );
        }

        $this->command->info('✅ ' . count($sections) . ' knowledge base sections seeded.');
    }
}
