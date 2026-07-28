<?php

namespace Database\Seeders;

use App\Models\Dpia;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant "Privasimu Nexus" — Privasimu memakai Privasimu.
 *
 * Mengisi organisasi, RoPA, DPIA, dan item Rencana Penanganan Risiko yang
 * menggambarkan pemrosesan data pribadi NYATA oleh platform ini sendiri.
 * Isinya diturunkan dari apa yang benar-benar dilakukan kode di repo ini —
 * bukan contoh karangan:
 *
 *   - users/personal_access_tokens        -> pendaftaran & autentikasi
 *   - AiDocumentAnalyzer + AiService      -> dokumen tenant dikirim ke LLM
 *   - DatabaseScanner / CloudStorageScanner -> memindai sistem klien
 *   - AsesmenPublikController             -> data PIC pihak ketiga + IP pengirim
 *   - DsrPublicController                 -> identitas pemohon hak subjek data
 *   - ConsentCollection + widget sematan  -> persetujuan milik klien
 *   - AuditLog                            -> jejak aktivitas + alamat IP
 *   - Lms (Mux)                           -> DPO Academy
 *   - Vercel Blob / Neon / Mux            -> transfer ke luar negeri
 *
 * Sifat: IDEMPOTEN. Dikunci pada registration_number dan slug organisasi,
 * jadi aman dijalankan berulang — tidak menggandakan baris.
 *
 * CATATAN RTP: Rencana Penanganan Risiko bukan tabel tersendiri. Ia adalah
 * pandangan agregat atas `dpias.mitigation_tracking`
 * (RiskTreatmentPlanController), sehingga item RTP di sini ditanam di dalam
 * DPIA induknya.
 *
 * Jalankan:
 *   php artisan db:seed --class=Database\\Seeders\\NexusTenantSeeder
 *
 * Kata sandi admin diambil dari env NEXUS_TENANT_ADMIN_PASSWORD. Bila tidak
 * diisi, seeder membuat kata sandi acak dan MENCETAKNYA sekali di konsol —
 * salin lalu ganti setelah masuk pertama kali.
 */
class NexusTenantSeeder extends Seeder
{
    private Organization $org;

    private User $admin;

    /** @var array<string, string> registration_number => uuid */
    private array $ropaIds = [];

    public function run(): void
    {
        $this->org = $this->seedOrganization();
        $this->admin = $this->seedAdmin();

        $this->seedRopa();
        $this->seedDpia();

        $this->report();
    }

    // ------------------------------------------------------------------
    // Organisasi & pengguna
    // ------------------------------------------------------------------

    private function seedOrganization(): Organization
    {
        $org = Organization::withoutGlobalScopes()->where('slug', 'privasimu-nexus')->first();

        $attributes = [
            'name' => 'Privasimu Nexus',
            'slug' => 'privasimu-nexus',
            'industry' => 'Teknologi Informasi — Perangkat Lunak Kepatuhan (SaaS)',
            'website' => 'https://privasimu.com',
            'email' => 'dpo@privasimu.com',
            'business_model' => 'B2B SaaS',
            'company_size' => '11-50',
            'data_subjects_type' => 'Karyawan klien (pengguna aplikasi), subjek data milik klien (diproses sebagai prosesor), pelamar kerja, prospek pemasaran',
            'core_systems' => 'Aplikasi Nexus (Laravel + Next.js), PostgreSQL (Neon), Vercel Blob, penyedia LLM, Mux, surel transaksional',
            'has_dpo' => true,
            'onboarding_completed' => true,
        ];

        if ($org) {
            $org->fill($attributes)->save();

            return $org;
        }

        return Organization::create(array_merge(['id' => (string) Str::uuid()], $attributes));
    }

    private function seedAdmin(): User
    {
        $email = env('NEXUS_TENANT_ADMIN_EMAIL', 'dpo@privasimu.com');
        $existing = User::withoutGlobalScopes()->where('email', $email)->first();

        if ($existing) {
            $this->command?->warn("Pengguna {$email} sudah ada — kata sandi TIDAK diubah.");

            return $existing;
        }

        $plain = env('NEXUS_TENANT_ADMIN_PASSWORD') ?: Str::password(20);

        $user = User::create([
            'id' => (string) Str::uuid(),
            'org_id' => $this->org->id,
            'name' => 'DPO Privasimu Nexus',
            'email' => $email,
            'password' => Hash::make($plain),
            'role' => 'admin',
            'locale' => 'id',
        ]);

        if (! env('NEXUS_TENANT_ADMIN_PASSWORD')) {
            $this->command?->newLine();
            $this->command?->warn('=================================================');
            $this->command?->warn(" Kata sandi awal untuk {$email}:");
            $this->command?->warn("   {$plain}");
            $this->command?->warn(' Dicetak SEKALI. Salin, lalu ganti setelah masuk.');
            $this->command?->warn('=================================================');
            $this->command?->newLine();
        }

        return $user;
    }

    // ------------------------------------------------------------------
    // RoPA
    // ------------------------------------------------------------------

    /**
     * Aktivitas pemrosesan Nexus sendiri.
     *
     * Empat di antaranya bertingkat risiko tinggi dan karena itu punya DPIA
     * di bawah: analisis dokumen dengan AI, pemindaian sistem klien,
     * verifikasi identitas pemohon DSR, dan replikasi cadangan lintas negara.
     */
    private function seedRopa(): void
    {
        foreach ($this->ropaDefinitions() as $i => $def) {
            $number = sprintf('ROPA-NEXUS-%03d', $i + 1);

            $ropa = Ropa::withoutGlobalScopes()->updateOrCreate(
                ['registration_number' => $number],
                array_merge([
                    'org_id' => $this->org->id,
                    'created_by' => $this->admin->id,
                    'status' => 'approved',
                    'progress' => 100.0,
                    'approved_by' => $this->admin->id,
                    'approved_at' => now()->subDays(30 - $i),
                ], $def)
            );

            $this->ropaIds[$number] = $ropa->id;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function ropaDefinitions(): array
    {
        return [
            [
                'processing_activity' => 'Pendaftaran dan autentikasi pengguna tenant',
                'division' => 'Engineering',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Platform',
                'risk_level' => 'medium',
                'description' => 'Pembuatan akun pengguna aplikasi Nexus oleh klien, autentikasi berbasis token, autentikasi dua faktor, dan pengelolaan sesi aktif.',
                'purpose' => 'Memberikan akses terkendali ke aplikasi sesuai perjanjian berlangganan, serta memastikan hanya orang berwenang yang dapat melihat data kepatuhan klien.',
                'legal_basis' => 'Pelaksanaan perjanjian (Pasal 20 ayat (2) huruf b UU PDP)',
                'legal_basis_detail' => 'Akun dibuat sebagai bagian dari pelaksanaan perjanjian berlangganan dengan badan usaha klien.',
                'data_categories' => ['Nama lengkap', 'Alamat surel kantor', 'Nomor telepon', 'Kata sandi (hash bcrypt)', 'Rahasia 2FA', 'Preferensi bahasa', 'Alamat IP sesi'],
                'data_subjects' => ['Karyawan klien yang menjadi pengguna aplikasi'],
                'recipients' => ['Tim Engineering Privasimu (akses terbatas)', 'Penyedia basis data terkelola'],
                'retention_period' => 'Selama perjanjian berlangganan aktif + 12 bulan',
                'security_measures' => 'Kata sandi di-hash bcrypt, 2FA wajib untuk peran istimewa, token Sanctum dengan rotasi geser, kebijakan kata sandi, pembatasan percobaan masuk, dan pemangkasan token kedaluwarsa terjadwal.',
            ],
            [
                'processing_activity' => 'Pengelolaan langganan, lisensi, dan penagihan',
                'division' => 'Finance',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Business Operations',
                'risk_level' => 'medium',
                'description' => 'Pencatatan lisensi, masa berlaku, kuota kredit AI, dan penerbitan tagihan kepada badan usaha klien.',
                'purpose' => 'Menjalankan hak dan kewajiban komersial dalam perjanjian berlangganan.',
                'legal_basis' => 'Pelaksanaan perjanjian dan kewajiban hukum perpajakan',
                'legal_basis_detail' => 'Dokumen tagihan wajib disimpan sesuai ketentuan perpajakan.',
                'data_categories' => ['Nama penanggung jawab', 'Surel dan telepon kontak', 'Jabatan', 'Riwayat transaksi'],
                'data_subjects' => ['Penanggung jawab komersial pada klien'],
                'recipients' => ['Tim Keuangan Privasimu', 'Konsultan pajak (bila diminta)'],
                'retention_period' => '10 tahun sejak transaksi (mengikuti ketentuan perpajakan)',
                'security_measures' => 'Akses berbasis peran, jejak audit atas perubahan lisensi, dan pemisahan data komersial dari data operasional tenant.',
            ],
            [
                'processing_activity' => 'Analisis dokumen kepatuhan menggunakan kecerdasan buatan',
                'division' => 'Engineering',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'AI Platform',
                'risk_level' => 'high',
                'description' => 'Dokumen bukti kepatuhan yang diunggah klien (kebijakan, SOP, kontrak, laporan audit) diekstraksi teksnya lalu dikirim ke penyedia model bahasa untuk dianalisis terhadap pertanyaan asesmen.',
                'purpose' => 'Membantu klien menilai kesesuaian bukti terhadap indikator kepatuhan secara otomatis.',
                'legal_basis' => 'Pelaksanaan perjanjian — Privasimu bertindak sebagai Prosesor Data Pribadi',
                'legal_basis_detail' => 'Pemrosesan dilakukan atas instruksi klien sebagai Pengendali. Kewajiban prosesor mengikuti Pasal 51 UU PDP.',
                'data_categories' => ['Isi dokumen klien yang dapat memuat nama, jabatan, dan alamat surel', 'Potensi data pribadi spesifik bila klien mengunggah dokumen yang memuatnya'],
                'data_subjects' => ['Karyawan dan mitra klien yang namanya tercantum dalam dokumen'],
                'recipients' => ['Penyedia model bahasa pihak ketiga (lintas negara)'],
                'retention_period' => 'Hasil analisis disimpan selama perjanjian aktif; teks yang dikirim ke penyedia tidak disimpan Privasimu di luar tembolok hasil',
                'security_measures' => 'Pembersih isi (AiContentSanitizer), penjaga masukan dan keluaran terhadap penyuntikan perintah, tembolok hasil berkunci org_id, pembatasan laju per pengguna, dan penolakan berkas gambar agar tidak menghabiskan kredit.',
            ],
            [
                'processing_activity' => 'Pemindaian penemuan data pada sistem klien',
                'division' => 'Engineering',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Data Discovery',
                'risk_level' => 'high',
                'description' => 'Aplikasi terhubung ke basis data dan penyimpanan objek milik klien untuk memetakan tabel dan kolom, lalu mengklasifikasikan kolom yang memuat data pribadi.',
                'purpose' => 'Menghasilkan peta data pribadi klien sebagai dasar penyusunan RoPA dan penilaian risiko.',
                'legal_basis' => 'Pelaksanaan perjanjian — Privasimu bertindak sebagai Prosesor Data Pribadi',
                'legal_basis_detail' => 'Kredensial koneksi diberikan klien secara sadar melalui menu Data Discovery.',
                'data_categories' => ['Kredensial koneksi basis data klien', 'Metadata skema (nama tabel dan kolom)', 'Cuplikan nilai kolom untuk klasifikasi'],
                'data_subjects' => ['Subjek data milik klien yang datanya tersimpan di sistem yang dipindai'],
                'recipients' => ['Tidak ada pihak ketiga — pemindaian berjalan di infrastruktur Privasimu'],
                'retention_period' => 'Metadata skema selama perjanjian aktif; cuplikan nilai disamarkan dan tidak disimpan utuh',
                'security_measures' => 'Kredensial dienkripsi saat disimpan, penyamaran nilai pada hasil pemindaian, pembatasan pengungkapan lewat endpoint reveal yang tercatat audit, dan pemisahan koneksi per tenant.',
            ],
            [
                'processing_activity' => 'Penangkapan persetujuan melalui widget sematan',
                'division' => 'Engineering',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Consent Platform',
                'risk_level' => 'medium',
                'description' => 'Widget persetujuan yang dipasang klien di situsnya mengirimkan catatan persetujuan subjek data ke Nexus melalui endpoint publik.',
                'purpose' => 'Menyimpan bukti persetujuan yang dapat ditunjukkan klien bila dipersoalkan.',
                'legal_basis' => 'Pelaksanaan perjanjian — Privasimu bertindak sebagai Prosesor Data Pribadi',
                'legal_basis_detail' => 'Dasar pemrosesan terhadap subjek data dipegang klien sebagai Pengendali.',
                'data_categories' => ['Pengenal subjek data (surel atau telepon)', 'Butir persetujuan yang dipilih', 'Versi teks persetujuan', 'Waktu dan kanal', 'Alamat IP pengirim'],
                'data_subjects' => ['Pengunjung dan nasabah klien'],
                'recipients' => ['Sistem hubungan pelanggan klien bila integrasi diaktifkan'],
                'retention_period' => 'Mengikuti kebijakan retensi klien; bawaan selama perjanjian aktif',
                'security_measures' => 'Kunci API per klien, pembatasan asal penyematan lewat frame-ancestors, pembatasan laju endpoint publik, dan tanda tangan webhook.',
            ],
            [
                'processing_activity' => 'Portal publik permintaan subjek data dan verifikasi identitas pemohon',
                'division' => 'Operations',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'DSR',
                'risk_level' => 'high',
                'description' => 'Pemohon hak subjek data mengirimkan permintaan melalui portal publik klien, disertai data untuk membuktikan bahwa ia benar orang yang dimaksud.',
                'purpose' => 'Memastikan permintaan hak subjek data dilayani kepada orang yang benar dan tidak disalahgunakan pihak lain.',
                'legal_basis' => 'Pelaksanaan perjanjian — Prosesor; kewajiban hukum klien memenuhi hak subjek data',
                'legal_basis_detail' => 'Verifikasi identitas diperlukan agar pemenuhan hak tidak justru membocorkan data kepada penipu.',
                'data_categories' => ['Nama lengkap', 'Alamat surel', 'Nomor telepon', 'Dokumen atau nomor identitas untuk verifikasi', 'Alamat IP dan agen peramban'],
                'data_subjects' => ['Subjek data yang mengajukan permintaan kepada klien'],
                'recipients' => ['Tim klien yang menangani permintaan'],
                'retention_period' => 'Bukti verifikasi dimusnahkan setelah permintaan selesai; catatan permintaan disimpan sebagai bukti kepatuhan',
                'security_measures' => 'Tautan bertoken dengan masa berlaku, pembatasan laju terpisah dari API umum, pencatatan alamat IP pengirim, dan sertifikat penyelesaian yang dapat diverifikasi.',
            ],
            [
                'processing_activity' => 'Asesmen kepatuhan pihak ketiga melalui tautan publik',
                'division' => 'Operations',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'TPRM',
                'risk_level' => 'medium',
                'description' => 'Pihak ketiga milik klien mengisi kuesioner kepatuhan dan mengunggah bukti melalui tautan bertoken tanpa perlu membuat akun.',
                'purpose' => 'Mengumpulkan bukti kepatuhan pihak ketiga untuk penilaian risiko oleh klien.',
                'legal_basis' => 'Pelaksanaan perjanjian — Prosesor; kepentingan sah klien menilai risiko mitranya',
                'data_categories' => ['Nama dan jabatan penanggung jawab pihak ketiga', 'Alamat surel', 'Isi jawaban dan dokumen bukti', 'Alamat IP dan agen peramban pengirim'],
                'data_subjects' => ['Penanggung jawab pada perusahaan pihak ketiga milik klien'],
                'recipients' => ['Tim pengadaan dan kepatuhan klien'],
                'retention_period' => 'Selama hubungan kerja sama klien dengan pihak ketiga berlangsung',
                'security_measures' => 'Token sekali pakai dengan tenggat, pembatasan laju 30 permintaan per menit, konteks tenant ditetapkan dari token, dan pencatatan alamat IP pengirim pada audit.',
            ],
            [
                'processing_activity' => 'Jejak audit dan pencatatan keamanan',
                'division' => 'Engineering',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Security',
                'risk_level' => 'medium',
                'description' => 'Setiap perubahan data dan tindakan penting dicatat beserta pelaku, waktu, alamat IP, dan nilai sebelum/sesudah.',
                'purpose' => 'Memenuhi kewajiban akuntabilitas, mendukung investigasi insiden, dan menjadi bukti kepatuhan klien.',
                'legal_basis' => 'Kewajiban hukum dan kepentingan sah menjaga keamanan sistem',
                'data_categories' => ['Pengenal pengguna', 'Alamat IP', 'Agen peramban', 'Tindakan dan nilai perubahan'],
                'data_subjects' => ['Pengguna aplikasi', 'Pengirim melalui tautan publik'],
                'recipients' => ['Tim Keamanan Privasimu', 'Klien untuk jejak miliknya sendiri'],
                'retention_period' => 'Mengikuti setelan security.audit_log_retention_days; bawaan disimpan tanpa batas sampai diatur',
                'security_measures' => 'Rantai hash tahan-ubah yang diverifikasi harian, pemangkasan terjadwal, dan pembatasan akses baca ke jejak lintas tenant.',
            ],
            [
                'processing_activity' => 'Penyelenggaraan DPO Academy (pembelajaran daring)',
                'division' => 'Product',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'LMS',
                'risk_level' => 'low',
                'description' => 'Pencatatan pendaftaran peserta, kemajuan menonton materi video, hasil kuis, dan penerbitan sertifikat.',
                'purpose' => 'Menyelenggarakan pelatihan kesadaran pelindungan data bagi pengguna klien.',
                'legal_basis' => 'Pelaksanaan perjanjian',
                'data_categories' => ['Nama peserta', 'Surel', 'Kemajuan belajar', 'Nilai kuis', 'Nomor sertifikat'],
                'data_subjects' => ['Karyawan klien yang menjadi peserta'],
                'recipients' => ['Penyedia layanan video (lintas negara)'],
                'retention_period' => 'Selama perjanjian aktif; sertifikat disimpan sebagai bukti pelatihan',
                'security_measures' => 'Token pemutaran video bertanda tangan dengan masa berlaku pendek, gerbang hak akses LMS per tenant, dan pemisahan konten per organisasi.',
            ],
            [
                'processing_activity' => 'Notifikasi dan komunikasi transaksional',
                'division' => 'Engineering',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Platform',
                'risk_level' => 'low',
                'description' => 'Pengiriman pemberitahuan tenggat, ringkasan berkala, peringatan keamanan, dan pemberitahuan insiden melalui surel dan kanal pesan.',
                'purpose' => 'Memberi tahu pengguna atas kejadian yang menuntut tindakan dalam batas waktu tertentu.',
                'legal_basis' => 'Pelaksanaan perjanjian',
                'data_categories' => ['Nama', 'Alamat surel', 'Pengenal kanal pesan', 'Preferensi notifikasi'],
                'data_subjects' => ['Pengguna aplikasi'],
                'recipients' => ['Penyedia surel transaksional', 'Kanal pesan yang dikonfigurasi klien'],
                'retention_period' => 'Log pengiriman 12 bulan',
                'security_measures' => 'Preferensi notifikasi per pengguna, validasi URL keluar untuk mencegah pengiriman ke tujuan internal, dan tanda tangan webhook.',
            ],
            [
                'processing_activity' => 'Dukungan pelanggan dan permintaan fitur',
                'division' => 'Product',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Customer Success',
                'risk_level' => 'low',
                'description' => 'Penerimaan pertanyaan, keluhan, dan usulan fitur dari pengguna klien maupun pengunjung publik.',
                'purpose' => 'Menyelesaikan kendala pengguna dan menyusun prioritas pengembangan produk.',
                'legal_basis' => 'Pelaksanaan perjanjian dan kepentingan sah pengembangan produk',
                'data_categories' => ['Nama', 'Surel', 'Isi pesan yang dapat memuat data lain yang disebut pengirim'],
                'data_subjects' => ['Pengguna aplikasi', 'Pengunjung publik yang mengirim usulan'],
                'recipients' => ['Tim Produk dan Dukungan Privasimu'],
                'retention_period' => '24 bulan sejak tiket ditutup',
                'security_measures' => 'Pembatasan laju pada endpoint publik, verifikasi captcha, dan pembatasan akses baca ke tim terkait.',
            ],
            [
                'processing_activity' => 'Pengelolaan prospek pemasaran dari situs web',
                'division' => 'Marketing',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Growth',
                'risk_level' => 'low',
                'description' => 'Pengumpulan data calon klien melalui formulir kontak dan permintaan demonstrasi di situs pemasaran.',
                'purpose' => 'Menindaklanjuti ketertarikan calon klien terhadap produk.',
                'legal_basis' => 'Persetujuan (Pasal 20 ayat (2) huruf a UU PDP)',
                'legal_basis_detail' => 'Persetujuan diberikan saat mengirim formulir, dengan butir pemasaran terpisah dari butir permintaan demonstrasi.',
                'data_categories' => ['Nama', 'Surel kantor', 'Nomor telepon', 'Nama perusahaan', 'Jabatan'],
                'data_subjects' => ['Calon klien dan pengunjung situs'],
                'recipients' => ['Tim Penjualan Privasimu'],
                'retention_period' => '24 bulan sejak kontak terakhir, atau segera setelah persetujuan ditarik',
                'security_measures' => 'Persetujuan tercatat beserta versi teksnya, kanal penarikan persetujuan tersedia, dan akses terbatas pada tim penjualan.',
            ],
            [
                'processing_activity' => 'Pencadangan dan replikasi data untuk pemulihan bencana',
                'division' => 'Engineering',
                'entity' => 'Privasimu Nexus',
                'work_unit' => 'Infrastructure',
                'risk_level' => 'high',
                'description' => 'Salinan basis data seluruh tenant dan berkas unggahan direplikasi ke penyimpanan terkelola untuk pemulihan bencana.',
                'purpose' => 'Menjamin ketersediaan dan pemulihan layanan bila terjadi kegagalan infrastruktur.',
                'legal_basis' => 'Pelaksanaan perjanjian dan kewajiban menjaga keamanan data',
                'legal_basis_detail' => 'Ketersediaan merupakan bagian dari kewajiban pelindungan data pada Pasal 35 dan 36 UU PDP.',
                'data_categories' => ['Seluruh kategori data yang diproses aplikasi, termasuk data pribadi milik klien'],
                'data_subjects' => ['Seluruh subjek data pada seluruh tenant'],
                'recipients' => ['Penyedia basis data terkelola dan penyimpanan objek (lintas negara)'],
                'retention_period' => 'Cadangan bergilir 30 hari',
                'security_measures' => 'Enkripsi saat disimpan dan saat dikirim, pemisahan basis data untuk tenant terisolasi, dan pembatasan akses pemulihan pada peran root.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // DPIA + item Rencana Penanganan Risiko
    // ------------------------------------------------------------------

    private function seedDpia(): void
    {
        foreach ($this->dpiaDefinitions() as $i => $def) {
            $number = sprintf('DPIA-NEXUS-%03d', $i + 1);

            Dpia::withoutGlobalScopes()->updateOrCreate(
                ['registration_number' => $number],
                [
                    'org_id' => $this->org->id,
                    'ropa_id' => $this->ropaIds[$def['ropa_number']] ?? null,
                    'risk_level' => 'high',
                    'status' => 'approved',
                    'progress' => 100.0,
                    'created_by' => $this->admin->id,
                    'approver_id' => $this->admin->id,
                    'approved_at' => now()->subDays(20 - $i),
                    'description' => $def['description'],
                    'risk_assessment' => [
                        'likelihood' => $def['likelihood'],
                        'impact' => $def['impact'],
                        'risks' => $def['risks'],
                    ],
                    'mitigation_measures' => array_column($def['treatments'], 'action'),
                    'mitigation_tracking' => $this->buildTreatments($def['treatments']),
                    'wizard_data' => [
                        'informasi_dpia' => [
                            'deskripsi_pemrosesan' => $def['description'],
                            'pic_name' => 'Kepala Teknologi Privasimu',
                            'dpo_name' => 'DPO Privasimu Nexus',
                        ],
                        'koneksi_ropa' => [
                            'connected_ropas' => array_values(array_filter([$this->ropaIds[$def['ropa_number']] ?? null])),
                        ],
                        'potensi_risiko' => $this->potensiRisiko($def['risiko_overrides'] ?? []),
                    ],
                ]
            );
        }
    }

    /**
     * Lengkapi item penanganan dengan bidang yang diharapkan DpiaRtpController
     * supaya langsung tampil benar di layar Rencana Penanganan Risiko.
     *
     * @param  array<int, array<string, mixed>>  $treatments
     * @return array<int, array<string, mixed>>
     */
    private function buildTreatments(array $treatments): array
    {
        $now = now()->toIso8601String();

        return array_map(function (array $t) use ($now) {
            $status = $t['status'] ?? 'planned';
            $done = in_array($status, ['implemented', 'verified'], true);

            return array_merge([
                'id' => (string) Str::uuid(),
                'category' => null,
                'rationale' => null,
                'owner_user_id' => $this->admin->id,
                'residual_likelihood' => null,
                'residual_impact' => null,
                'evidence_files' => [],
                'notes' => '',
                'started_at' => $status === 'planned' ? null : $now,
                'completed_at' => $done ? $now : null,
                'verified_at' => $status === 'verified' ? $now : null,
                'verified_by' => $status === 'verified' ? $this->admin->id : null,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $this->admin->id,
            ], $t);
        }, $treatments);
    }

    /**
     * Isi 21 kategori penilaian risiko. Yang tidak disebut di $overrides
     * diisi status 'sudah' — kategori yang memang sudah tertangani kontrol
     * bawaan platform.
     *
     * @param  array<string, array{status: string, description: string}>  $overrides
     * @return array<string, array{status: string, description: string}>
     */
    private function potensiRisiko(array $overrides): array
    {
        $out = [];
        foreach (Dpia::RISK_CATEGORIES as $category) {
            $out[$category] = $overrides[$category] ?? [
                'status' => 'sudah',
                'description' => 'Tertangani oleh kontrol bawaan platform dan ditinjau pada asesmen berkala.',
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dpiaDefinitions(): array
    {
        return [
            [
                'ropa_number' => 'ROPA-NEXUS-003',
                'description' => 'Penilaian dampak atas pengiriman isi dokumen kepatuhan milik klien ke penyedia model bahasa pihak ketiga di luar wilayah Indonesia untuk keperluan analisis otomatis.',
                'likelihood' => 3,
                'impact' => 5,
                'risks' => [
                    'Dokumen yang diunggah klien dapat memuat data pribadi spesifik tanpa disadari pengunggahnya.',
                    'Penyedia model bahasa memproses data di luar wilayah Indonesia.',
                    'Sebagian penyedia melatih model dari data yang dikirim melalui API.',
                    'Penyuntikan perintah melalui isi dokumen dapat mengubah perilaku model.',
                ],
                'risiko_overrides' => [
                    'Transfer Luar Negeri' => ['status' => 'sebagian', 'description' => 'Pemrosesan berlangsung di penyedia luar negeri. Dasar transfer bersandar pada perjanjian dan pemilihan penyedia berkomitmen tanpa pelatihan; pengaman teknis berupa opsi pemrosesan on-premise masih dalam pengembangan.'],
                    'Pihak Ketiga' => ['status' => 'sebagian', 'description' => 'Register kepatuhan penyedia tersedia beserta status DPA, zero-data-retention, dan yurisdiksi. Penyedia bertanda not_recommended kini ditolak sistem saat hendak dipilih.'],
                    'Data Minimization' => ['status' => 'sebagian', 'description' => 'Hanya teks yang relevan dengan pertanyaan asesmen yang dikirim, namun ekstraksi masih mengambil seluruh isi dokumen sebelum dipangkas.'],
                    'Anonimisasi' => ['status' => 'belum', 'description' => 'Belum ada penyamaran otomatis atas nama dan pengenal langsung sebelum teks dikirim ke penyedia.'],
                ],
                'treatments' => [
                    [
                        'risk_event' => 'Data pribadi spesifik dalam dokumen klien terkirim ke penyedia luar negeri',
                        'treatment_type' => 'reduce',
                        'action' => 'Terapkan penyamaran otomatis atas pengenal langsung (nama, NIK, surel, telepon) pada teks sebelum dikirim ke penyedia model bahasa.',
                        'priority' => 'critical',
                        'status' => 'in_progress',
                        'due_date' => now()->addDays(45)->toDateString(),
                        'inherent_likelihood' => 3,
                        'inherent_impact' => 5,
                    ],
                    [
                        'risk_event' => 'Penyedia model bahasa melatih model dari data yang dikirim',
                        'treatment_type' => 'avoid',
                        'action' => 'Blokir pemilihan penyedia dengan penanda no_training=false atau pdp_risk=not_recommended, dengan penembusan khusus root yang tercatat di audit log.',
                        'priority' => 'critical',
                        'status' => 'verified',
                        'due_date' => now()->subDays(5)->toDateString(),
                        'inherent_likelihood' => 4,
                        'inherent_impact' => 5,
                    ],
                    [
                        'risk_event' => 'Penyuntikan perintah melalui isi dokumen yang diunggah',
                        'treatment_type' => 'reduce',
                        'action' => 'Pertahankan lapis penjaga masukan dan keluaran, serta pembersih isi dokumen tidak tepercaya sebelum masuk ke prompt.',
                        'priority' => 'high',
                        'status' => 'implemented',
                        'due_date' => now()->subDays(20)->toDateString(),
                        'inherent_likelihood' => 3,
                        'inherent_impact' => 4,
                    ],
                    [
                        'risk_event' => 'Ketergantungan pada penyedia luar negeri untuk klien tersektor diatur',
                        'treatment_type' => 'reduce',
                        'action' => 'Sediakan mode pemrosesan on-premise penuh (LLM dan embedding lokal) sebagai pilihan bagi klien yang datanya tidak boleh keluar infrastruktur.',
                        'priority' => 'high',
                        'status' => 'in_progress',
                        'due_date' => now()->addDays(90)->toDateString(),
                        'inherent_likelihood' => 3,
                        'inherent_impact' => 4,
                    ],
                ],
            ],
            [
                'ropa_number' => 'ROPA-NEXUS-004',
                'description' => 'Penilaian dampak atas akses aplikasi ke basis data produksi milik klien untuk memetakan dan mengklasifikasikan kolom yang memuat data pribadi.',
                'likelihood' => 2,
                'impact' => 5,
                'risks' => [
                    'Aplikasi menyimpan kredensial koneksi ke basis data produksi klien.',
                    'Pemindaian membaca cuplikan nilai kolom yang berisi data pribadi nyata.',
                    'Kesalahan pembatasan dapat membuat hasil pemindaian satu klien terlihat klien lain.',
                    'Beban pemindaian dapat mengganggu ketersediaan sistem klien.',
                ],
                'risiko_overrides' => [
                    'Enkripsi (Structured)' => ['status' => 'sudah', 'description' => 'Kredensial koneksi dienkripsi saat disimpan dan hanya dibuka saat eksekusi pemindaian.'],
                    'Pemantauan Akses' => ['status' => 'sebagian', 'description' => 'Pengungkapan nilai tersamar sudah tercatat di audit, namun pemantauan volume pembacaan belum diberi ambang peringatan.'],
                    'Data Minimization' => ['status' => 'sebagian', 'description' => 'Cuplikan nilai dibatasi jumlah barisnya, tetapi kolom yang dicuplik belum dibatasi hanya pada yang benar-benar perlu diklasifikasi.'],
                ],
                'treatments' => [
                    [
                        'risk_event' => 'Kredensial basis data produksi klien tersimpan di sistem Privasimu',
                        'treatment_type' => 'reduce',
                        'action' => 'Wajibkan pemakaian akun basis data khusus baca-saja berhak minimum untuk setiap koneksi pemindaian, dan tolak koneksi dengan hak tulis.',
                        'priority' => 'critical',
                        'status' => 'planned',
                        'due_date' => now()->addDays(60)->toDateString(),
                        'inherent_likelihood' => 2,
                        'inherent_impact' => 5,
                    ],
                    [
                        'risk_event' => 'Hasil pemindaian satu klien terlihat oleh klien lain',
                        'treatment_type' => 'reduce',
                        'action' => 'Pertahankan tiga lapis isolasi (RLS basis data, filter org_id eksplisit, lingkup global model) dan uji regresi isolasi pada setiap rilis.',
                        'priority' => 'critical',
                        'status' => 'verified',
                        'due_date' => now()->subDays(15)->toDateString(),
                        'inherent_likelihood' => 2,
                        'inherent_impact' => 5,
                    ],
                    [
                        'risk_event' => 'Cuplikan nilai kolom memuat data pribadi nyata',
                        'treatment_type' => 'reduce',
                        'action' => 'Samarkan nilai pada hasil pemindaian secara bawaan dan catat setiap pengungkapan nilai asli beserta pelakunya.',
                        'priority' => 'high',
                        'status' => 'implemented',
                        'due_date' => now()->subDays(30)->toDateString(),
                        'inherent_likelihood' => 3,
                        'inherent_impact' => 4,
                    ],
                ],
            ],
            [
                'ropa_number' => 'ROPA-NEXUS-006',
                'description' => 'Penilaian dampak atas pengumpulan data identitas pemohon pada portal publik permintaan subjek data, termasuk dokumen identitas yang diunggah untuk verifikasi.',
                'likelihood' => 3,
                'impact' => 4,
                'risks' => [
                    'Dokumen identitas yang diunggah pemohon memuat data pribadi spesifik.',
                    'Endpoint publik tanpa autentikasi rentan penyalahgunaan dan pengumpulan data massal.',
                    'Verifikasi yang lemah dapat menyerahkan data kepada pihak yang menyamar sebagai subjek data.',
                    'Bukti verifikasi tersimpan lebih lama dari keperluannya.',
                ],
                'risiko_overrides' => [
                    'Pemusnahan Data' => ['status' => 'belum', 'description' => 'Pemusnahan otomatis atas dokumen verifikasi setelah permintaan selesai belum berjalan; masih dilakukan manual.'],
                    'Verifikasi Data' => ['status' => 'sebagian', 'description' => 'Verifikasi berlapis tersedia, namun tingkat keketatannya belum dapat diatur per klien sesuai profil risikonya.'],
                    'Retensi' => ['status' => 'sebagian', 'description' => 'Catatan permintaan disimpan sebagai bukti kepatuhan, tetapi masa simpan lampiran verifikasi belum ditetapkan terpisah.'],
                ],
                'treatments' => [
                    [
                        'risk_event' => 'Dokumen identitas pemohon tersimpan melampaui keperluan verifikasi',
                        'treatment_type' => 'reduce',
                        'action' => 'Jadwalkan pemusnahan otomatis lampiran verifikasi 30 hari setelah permintaan berstatus selesai, dengan pencatatan pemusnahan di audit.',
                        'priority' => 'high',
                        'status' => 'planned',
                        'due_date' => now()->addDays(30)->toDateString(),
                        'inherent_likelihood' => 4,
                        'inherent_impact' => 4,
                    ],
                    [
                        'risk_event' => 'Penyalahgunaan endpoint publik untuk pengumpulan data',
                        'treatment_type' => 'reduce',
                        'action' => 'Pertahankan pembatasan laju terpisah per token dan alamat IP pada seluruh endpoint publik, terpisah dari kuota API terautentikasi.',
                        'priority' => 'high',
                        'status' => 'verified',
                        'due_date' => now()->subDays(25)->toDateString(),
                        'inherent_likelihood' => 3,
                        'inherent_impact' => 3,
                    ],
                    [
                        'risk_event' => 'Data diserahkan kepada pihak yang menyamar sebagai subjek data',
                        'treatment_type' => 'reduce',
                        'action' => 'Sediakan tingkat keketatan verifikasi yang dapat diatur klien, dan wajibkan verifikasi berlapis untuk permintaan penghapusan.',
                        'priority' => 'medium',
                        'status' => 'in_progress',
                        'due_date' => now()->addDays(75)->toDateString(),
                        'inherent_likelihood' => 2,
                        'inherent_impact' => 5,
                    ],
                ],
            ],
            [
                'ropa_number' => 'ROPA-NEXUS-013',
                'description' => 'Penilaian dampak atas replikasi salinan basis data seluruh tenant ke penyedia terkelola yang memproses data di luar wilayah Indonesia.',
                'likelihood' => 2,
                'impact' => 5,
                'risks' => [
                    'Salinan lengkap data seluruh tenant berada pada penyedia di luar yurisdiksi Indonesia.',
                    'Klien sektor jasa keuangan tunduk pada ketentuan residensi data yang lebih ketat.',
                    'Pemulihan cadangan dapat menghidupkan kembali data yang telah dimusnahkan atas permintaan subjek data.',
                ],
                'risiko_overrides' => [
                    'Transfer Luar Negeri' => ['status' => 'sebagian', 'description' => 'Replikasi berlangsung ke wilayah di luar Indonesia. Klien tersektor diatur ditawari basis data terdedikasi di wilayah Indonesia melalui skema tenancy berjenjang.'],
                    'Back-up dan Restore' => ['status' => 'sudah', 'description' => 'Cadangan bergilir 30 hari dengan uji pemulihan berkala.'],
                    'Keamanan Back-up' => ['status' => 'sudah', 'description' => 'Enkripsi saat disimpan dan saat dikirim, akses pemulihan terbatas pada peran root.'],
                    'Pemusnahan Data' => ['status' => 'sebagian', 'description' => 'Pemusnahan pada basis data utama berjalan, namun penghapusan pada salinan cadangan menunggu habisnya siklus 30 hari.'],
                ],
                'treatments' => [
                    [
                        'risk_event' => 'Data klien tersektor diatur berada di luar wilayah Indonesia',
                        'treatment_type' => 'reduce',
                        'action' => 'Sediakan basis data terdedikasi di wilayah Indonesia bagi klien yang menuntut residensi data, melalui skema tenancy berjenjang yang sudah tersedia.',
                        'priority' => 'critical',
                        'status' => 'in_progress',
                        'due_date' => now()->addDays(120)->toDateString(),
                        'inherent_likelihood' => 3,
                        'inherent_impact' => 5,
                    ],
                    [
                        'risk_event' => 'Pemulihan cadangan menghidupkan kembali data yang telah dimusnahkan',
                        'treatment_type' => 'reduce',
                        'action' => 'Susun prosedur pemulihan yang menjalankan ulang daftar permintaan penghapusan setelah restorasi, dan catat pelaksanaannya sebagai bukti.',
                        'priority' => 'high',
                        'status' => 'planned',
                        'due_date' => now()->addDays(60)->toDateString(),
                        'inherent_likelihood' => 2,
                        'inherent_impact' => 4,
                    ],
                    [
                        'risk_event' => 'Salinan cadangan tidak terenkripsi pada penyimpanan objek',
                        'treatment_type' => 'reduce',
                        'action' => 'Pastikan enkripsi saat disimpan aktif pada seluruh wadah penyimpanan objek dan verifikasi setelan tersebut setiap kuartal.',
                        'priority' => 'medium',
                        'status' => 'verified',
                        'due_date' => now()->subDays(40)->toDateString(),
                        'inherent_likelihood' => 2,
                        'inherent_impact' => 5,
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------

    private function report(): void
    {
        $ropa = Ropa::withoutGlobalScopes()->where('org_id', $this->org->id)->count();
        $dpia = Dpia::withoutGlobalScopes()->where('org_id', $this->org->id)->count();

        $rtp = Dpia::withoutGlobalScopes()
            ->where('org_id', $this->org->id)
            ->get()
            ->sum(fn ($d) => count($d->mitigation_tracking ?? []));

        $this->command?->info("Tenant  : {$this->org->name} ({$this->org->id})");
        $this->command?->info("Admin   : {$this->admin->email}");
        $this->command?->info("RoPA    : {$ropa}");
        $this->command?->info("DPIA    : {$dpia}");
        $this->command?->info("Item RTP: {$rtp} (tertanam di mitigation_tracking tiap DPIA)");
    }
}
