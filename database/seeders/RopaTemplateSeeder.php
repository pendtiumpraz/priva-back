<?php

namespace Database\Seeders;

use App\Models\RopaTemplate;
use Illuminate\Database\Seeder;

/**
 * Seed RoPA templates covering common processing activities per industry.
 * DPO/PIC can pick a template in the New RoPA wizard to skip the blank state.
 *
 * Idempotent — keyed on (industry, activity_code).
 */
class RopaTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $tpl) {
            RopaTemplate::updateOrCreate(
                ['industry' => $tpl['industry'], 'activity_code' => $tpl['activity_code'], 'is_system' => true, 'org_id' => null],
                [
                    'name' => $tpl['name'],
                    'description' => $tpl['description'],
                    'wizard_data' => $tpl['wizard_data'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function templates(): array
    {
        return [
            // ===== BANKING =====
            [
                'industry' => 'banking', 'activity_code' => 'kyc_onboarding',
                'name' => 'KYC Onboarding Nasabah',
                'description' => 'Proses verifikasi identitas nasabah baru sesuai POJK 11 dan UU PDP — pengumpulan NIK, foto KTP, selfie, dan data biometrik.',
                'wizard_data' => [
                    'detail_pemrosesan' => [
                        'nama_pemrosesan' => 'KYC Onboarding Nasabah',
                        'sistem_terkait' => 'Core Banking + e-KYC Platform',
                        'risk_level' => 'high',
                    ],
                    'informasi_pemrosesan' => [
                        'tujuan' => "1. Verifikasi identitas calon nasabah (due diligence)\n2. Mencegah pencucian uang (AML)\n3. Memenuhi kewajiban POJK 12/POJK.03/2018",
                        'legal_basis' => 'kewajiban_hukum',
                        'jenis_pemrosesan' => 'Pengumpulan, Analisis, Penyimpanan',
                    ],
                    'pengumpulan_data' => [
                        'sumber_data' => 'Calon Nasabah',
                        'kategori_subjek' => 'Nasabah / Prospective Customer',
                        'jenis_data_umum' => ['Nama lengkap', 'Alamat', 'Nomor telepon', 'Email'],
                        'jenis_data_pii' => ['NIK', 'Foto KTP', 'Selfie', 'Data biometrik (wajah)'],
                    ],
                    'penggunaan_penyimpanan' => ['lokasi_penyimpanan' => 'Core Banking on-premise + Document Vault'],
                    'retensi_keamanan' => ['masa_retensi' => '10 tahun setelah penutupan rekening (POJK)', 'prosedur_pemusnahan' => 'Hapus/anonimisasi di DB + shredding arsip fisik'],
                ],
            ],
            [
                'industry' => 'banking', 'activity_code' => 'credit_scoring',
                'name' => 'Credit Scoring & Risk Assessment',
                'description' => 'Penilaian kelayakan kredit nasabah — BI Checking, histori transaksi, scoring model.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Credit Scoring Nasabah', 'sistem_terkait' => 'Loan Origination System + SLIK OJK', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Menilai kelayakan kredit calon debitur\n2. Perhitungan limit pinjaman\n3. Kepatuhan POJK manajemen risiko kredit", 'legal_basis' => 'kepentingan_sah'],
                    'pengumpulan_data' => ['sumber_data' => 'Nasabah + SLIK OJK + Biro Kredit', 'kategori_subjek' => 'Calon Debitur', 'jenis_data_umum' => ['Pendapatan', 'Pekerjaan'], 'jenis_data_pii' => ['NIK', 'Histori kredit', 'Informasi keuangan']],
                    'retensi_keamanan' => ['masa_retensi' => '10 tahun (kewajiban POJK)'],
                ],
            ],
            [
                'industry' => 'banking', 'activity_code' => 'transaction_monitoring',
                'name' => 'Transaction Monitoring (Anti Fraud / AML)',
                'description' => 'Monitoring transaksi untuk deteksi pola mencurigakan (pencucian uang, pendanaan terorisme).',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Transaction Monitoring AML', 'sistem_terkait' => 'AML Monitoring Platform', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Deteksi pola transaksi mencurigakan\n2. Pelaporan ke PPATK sesuai UU TPPU\n3. Audit trail transaksi nasabah", 'legal_basis' => 'kewajiban_hukum'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['Data transaksi nasabah', 'Profil risiko', 'Geolokasi transaksi']],
                ],
            ],

            // ===== HEALTHCARE =====
            [
                'industry' => 'healthcare', 'activity_code' => 'patient_records',
                'name' => 'Rekam Medis Pasien',
                'description' => 'Pencatatan rekam medis elektronik (RME) sesuai PMK 269 dan UU PDP Pasal 4 (data kesehatan = data sensitif).',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Electronic Medical Records', 'sistem_terkait' => 'HIS / EMR System', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Pencatatan riwayat medis pasien\n2. Koordinasi perawatan antar dokter/tenaga medis\n3. Klaim BPJS/asuransi\n4. Audit mutu pelayanan", 'legal_basis' => 'vital_interest'],
                    'pengumpulan_data' => ['sumber_data' => 'Pasien + Tenaga Medis', 'kategori_subjek' => 'Pasien', 'jenis_data_pii' => ['Data kesehatan (DIAGNOSIS, MEDIKASI)', 'NIK', 'Nomor BPJS', 'Hasil lab', 'Resep']],
                    'retensi_keamanan' => ['masa_retensi' => '10 tahun setelah kunjungan terakhir (PMK 269)', 'prosedur_pemusnahan' => 'Anonimisasi untuk riset, hapus permanen setelah 10 tahun'],
                ],
            ],
            [
                'industry' => 'healthcare', 'activity_code' => 'appointment_booking',
                'name' => 'Booking & Scheduling Pasien',
                'description' => 'Sistem pendaftaran janji temu pasien online/offline.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Patient Appointment Booking', 'sistem_terkait' => 'Booking Portal + SMS Gateway', 'risk_level' => 'medium'],
                    'informasi_pemrosesan' => ['tujuan' => 'Manajemen jadwal dokter & pasien, reminder kunjungan', 'legal_basis' => 'kontrak'],
                    'pengumpulan_data' => ['jenis_data_umum' => ['Nama', 'HP', 'Email'], 'jenis_data_pii' => ['Nomor BPJS', 'Keluhan (ringkas)']],
                ],
            ],

            // ===== INSURANCE =====
            [
                'industry' => 'insurance', 'activity_code' => 'claim_processing',
                'name' => 'Proses Klaim Asuransi',
                'description' => 'Pemrosesan klaim asuransi jiwa/kesehatan — verifikasi dokumen, investigasi, pembayaran.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Insurance Claim Processing', 'sistem_terkait' => 'Claim Management System', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Verifikasi klaim tertanggung\n2. Investigasi fraud\n3. Pembayaran manfaat", 'legal_basis' => 'kontrak'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['Data kesehatan', 'Dokumen kematian', 'Histori medis', 'Polis nomor', 'Rekening bank']],
                    'retensi_keamanan' => ['masa_retensi' => '10 tahun setelah klaim selesai (POJK asuransi)'],
                ],
            ],
            [
                'industry' => 'insurance', 'activity_code' => 'policy_underwriting',
                'name' => 'Underwriting Polis Baru',
                'description' => 'Penilaian risiko calon tertanggung sebelum penerbitan polis.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Policy Underwriting', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => 'Penilaian risiko untuk menentukan premi & coverage'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['Medical check-up results', 'Histori penyakit keluarga', 'Pekerjaan berisiko', 'NIK']],
                ],
            ],

            // ===== FINTECH =====
            [
                'industry' => 'fintech', 'activity_code' => 'p2p_lending',
                'name' => 'P2P Lending — Analisis Peminjam',
                'description' => 'Analisis calon peminjam platform P2P lending (POJK 77).',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'P2P Lending Borrower Analysis', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Credit scoring peminjam\n2. Matching dengan pemberi pinjaman\n3. Kepatuhan POJK 77", 'legal_basis' => 'kontrak'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['NIK', 'Akses SMS/kontak', 'Data e-commerce', 'Selfie', 'Histori pinjaman']],
                ],
            ],

            // ===== RETAIL / E-COMMERCE =====
            [
                'industry' => 'retail', 'activity_code' => 'loyalty_program',
                'name' => 'Program Loyalty Pelanggan',
                'description' => 'Pengelolaan kartu member/poin loyalty dengan profil belanja.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Customer Loyalty Program', 'risk_level' => 'medium'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Akumulasi & redemption poin\n2. Personalisasi promo\n3. Analisis perilaku belanja", 'legal_basis' => 'persetujuan'],
                    'pengumpulan_data' => ['jenis_data_umum' => ['Nama', 'Email', 'HP', 'Ulang tahun'], 'jenis_data_pii' => ['Histori transaksi', 'Preferensi produk']],
                ],
            ],
            [
                'industry' => 'retail', 'activity_code' => 'order_fulfillment',
                'name' => 'Order Fulfillment & Shipping',
                'description' => 'Pemrosesan pesanan dari checkout sampai pengiriman.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Order Fulfillment', 'risk_level' => 'medium'],
                    'informasi_pemrosesan' => ['tujuan' => 'Fulfillment pesanan e-commerce — pembayaran, pengiriman, notifikasi', 'legal_basis' => 'kontrak'],
                    'pengumpulan_data' => ['jenis_data_umum' => ['Nama', 'Alamat lengkap', 'HP', 'Email'], 'jenis_data_pii' => ['Data kartu kredit (tokenized)', 'Lokasi GPS delivery']],
                ],
            ],

            // ===== HR / EMPLOYEE =====
            [
                'industry' => 'general', 'activity_code' => 'hr_payroll',
                'name' => 'HR — Payroll Karyawan',
                'description' => 'Pengelolaan penggajian, benefit, dan administrasi karyawan.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Payroll Processing', 'sistem_terkait' => 'HRIS', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Perhitungan & pembayaran gaji\n2. Pemotongan pajak/BPJS\n3. Benefit administration", 'legal_basis' => 'kontrak'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['NIK', 'NPWP', 'Nomor BPJS', 'Rekening bank', 'Gaji']],
                    'retensi_keamanan' => ['masa_retensi' => '30 tahun setelah masa kerja berakhir (UU Ketenagakerjaan)'],
                ],
            ],
            [
                'industry' => 'general', 'activity_code' => 'recruitment',
                'name' => 'Rekrutmen Kandidat Karyawan',
                'description' => 'Pengumpulan CV, wawancara, background check calon karyawan.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Recruitment & Hiring', 'risk_level' => 'medium'],
                    'informasi_pemrosesan' => ['tujuan' => "1. Seleksi kandidat\n2. Background check (pidana, referensi)\n3. Offering", 'legal_basis' => 'langkah_pra_kontrak'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['Nama', 'CV', 'Ijazah', 'SKCK', 'NIK', 'Hasil psikotes']],
                    'retensi_keamanan' => ['masa_retensi' => '1 tahun untuk kandidat yang tidak diterima'],
                ],
            ],

            // ===== GOVERNMENT / PUBLIC =====
            [
                'industry' => 'government', 'activity_code' => 'citizen_services',
                'name' => 'Pelayanan Publik / Izin Online',
                'description' => 'Sistem pelayanan perizinan/administrasi untuk warga.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Citizen Services Portal', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => 'Penerbitan perizinan / layanan administratif untuk warga', 'legal_basis' => 'kepentingan_umum'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['NIK', 'KK', 'Akta kelahiran', 'Dokumen pendukung layanan']],
                ],
            ],

            // ===== TELCO =====
            [
                'industry' => 'telco', 'activity_code' => 'prepaid_registration',
                'name' => 'Registrasi Kartu Prabayar',
                'description' => 'Registrasi kartu SIM prabayar dengan NIK+KK sesuai Permenkominfo.',
                'wizard_data' => [
                    'detail_pemrosesan' => ['nama_pemrosesan' => 'Prepaid SIM Registration', 'risk_level' => 'high'],
                    'informasi_pemrosesan' => ['tujuan' => 'Registrasi kartu SIM sesuai Permenkominfo 14/2017', 'legal_basis' => 'kewajiban_hukum'],
                    'pengumpulan_data' => ['jenis_data_pii' => ['NIK', 'KK', 'Nomor HP']],
                ],
            ],
        ];
    }
}
