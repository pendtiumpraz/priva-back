<?php

namespace App\Services;

use App\Models\PiiPatternRule;

/**
 * Katalog pola pengenal data pribadi bawaan, disemai per tenant.
 *
 * Kenapa ini perlu ada sama sekali: platform punya DUA lapisan deteksi yang
 * bekerja dengan cara berbeda.
 *
 *   PiiDetector       -> mencocokkan NAMA KOLOM ('nik', 'no_ktp', 'npwp', ...)
 *   ContentPiiScanner -> mencocokkan ISI DATA dengan regex dari tabel ini
 *
 * Lapisan pertama sudah tertanam di kode dan selalu aktif. Lapisan kedua
 * membaca tabel ini — dan tabelnya mulai KOSONG. Akibatnya kolom bernama
 * `field_123` atau `keterangan` yang isinya NIK tidak pernah terdeteksi:
 * namanya tidak cocok, dan tidak ada satu pun regex yang memeriksa isinya.
 *
 * Jadi penyemaian di sini bukan sekadar kenyamanan — ia menghidupkan lapisan
 * deteksi yang selama ini mati.
 *
 * Mengikuti pola ControlLibraryService: baris dimiliki tenant, disemai saat
 * pertama kali dibuka, dan `reset` mengembalikannya. Tidak ada baris sistem
 * bersama, sehingga penyuntingan satu tenant tidak menyentuh tenant lain.
 */
class PiiPatternRuleService
{
    /**
     * Semai bila tenant belum pernah punya pola sama sekali.
     *
     * Pemeriksaan memakai withTrashed: tenant yang sengaja menghapus semua
     * pola bawaan tidak akan melihatnya tumbuh lagi setiap halaman dibuka.
     * Tanpa itu, penghapusan tidak pernah benar-benar bertahan.
     */
    public static function ensureSeeded(string $orgId): int
    {
        $exists = PiiPatternRule::withoutGlobalScope('org')
            ->withTrashed()
            ->where('org_id', $orgId)
            ->exists();

        if ($exists) {
            return 0;
        }

        return self::seed($orgId);
    }

    /** Tulis ulang katalog bawaan untuk satu tenant. */
    public static function seed(string $orgId): int
    {
        $count = 0;

        foreach (self::defaults() as $i => $item) {
            PiiPatternRule::create(array_merge([
                // Aktif kecuali polanya sendiri menyatakan sebaliknya. Beberapa
                // pola disemai NONAKTIF karena terlalu sering cocok pada kolom
                // teknis — lihat catatan masing-masing di defaults().
                'is_active' => true,
            ], $item, [
                'org_id' => $orgId,
                'sequence' => ($i + 1) * 10,
            ]));
            $count++;
        }

        return $count;
    }

    /**
     * Pola bawaan.
     *
     * Semuanya berformat pasti — panjang tetap atau berpola tegas — supaya
     * kecocokannya bermakna. Pola yang terlalu longgar lebih berbahaya
     * daripada tidak ada: ia menandai ribuan kolom yang bukan data pribadi,
     * dan orang berhenti mempercayai hasil pemindaian.
     *
     * `sample_value` diuji terhadap `pattern` saat penyimpanan, jadi setiap
     * baris di sini terverifikasi mencocokkan contohnya sendiri.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            // ---------- Identitas inti ----------
            [
                'key' => 'nik',
                'label' => 'NIK (Nomor Induk Kependudukan)',
                'pattern' => '/\b\d{16}\b/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.95,
                'reason' => 'NIK 16 digit — data pribadi spesifik, Pasal 4 UU PDP.',
                'sample_value' => '3174012509900001',
            ],
            [
                'key' => 'nkk',
                'label' => 'Nomor Kartu Keluarga',
                'pattern' => '/\b\d{16}\b/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                // Sengaja lebih rendah dari NIK: formatnya identik 16 digit,
                // jadi kalau keduanya cocok, NIK yang menang.
                'weight' => 0.60,
                'reason' => 'Nomor KK berformat sama dengan NIK — perlu dipastikan dari konteks kolomnya.',
                'sample_value' => '3174012509900002',
            ],
            [
                'key' => 'npwp',
                'label' => 'NPWP',
                'pattern' => '/\b\d{2}\.\d{3}\.\d{3}\.\d-\d{3}\.\d{3}\b|\b\d{15,16}\b/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.90,
                'reason' => 'NPWP — pengenal wajib pajak, data pribadi spesifik.',
                'sample_value' => '09.254.294.3-407.000',
            ],
            [
                'key' => 'paspor_ri',
                'label' => 'Nomor Paspor RI',
                'pattern' => '/\b[A-Z]\d{7}\b/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.80,
                'reason' => 'Paspor RI — satu huruf diikuti tujuh digit.',
                'sample_value' => 'A1234567',
            ],
            [
                'key' => 'email',
                'label' => 'Alamat Surel',
                'pattern' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.95,
                'reason' => 'Alamat surel — data pribadi umum.',
                'sample_value' => 'nasabah@contoh.co.id',
            ],
            [
                'key' => 'telepon_id',
                'label' => 'Nomor Telepon Indonesia',
                'pattern' => '/\b(?:\+62|62|0)8[1-9]\d{6,10}\b/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.90,
                'reason' => 'Nomor seluler Indonesia.',
                'sample_value' => '081234567890',
            ],
            [
                'key' => 'plat_nomor',
                'label' => 'Plat Nomor Kendaraan',
                'pattern' => '/\b[A-Z]{1,2}\s?\d{1,4}\s?[A-Z]{1,3}\b/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.55,
                'reason' => 'Plat nomor kendaraan — dapat mengarah ke pemiliknya.',
                'sample_value' => 'B 1234 ABC',
            ],

            // ---------- Keuangan ----------
            [
                'key' => 'kartu_kredit',
                'label' => 'Nomor Kartu Kredit/Debit',
                'pattern' => '/\b(?:\d[ -]?){13,19}\b/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.85,
                'reason' => 'Nomor kartu pembayaran — wajib dienkripsi. Perlu diverifikasi Luhn sebelum disimpulkan.',
                'sample_value' => '4111 1111 1111 1111',
            ],
            [
                'key' => 'rekening_bank',
                'label' => 'Nomor Rekening Bank',
                'pattern' => '/\b\d{10,16}\b/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.55,
                'reason' => 'Nomor rekening — panjangnya berbeda antar bank, jadi kecocokan perlu dikuatkan konteks kolom.',
                'sample_value' => '1234567890',
            ],
            [
                'key' => 'cif',
                'label' => 'Nomor CIF Nasabah',
                'pattern' => '/\bCIF[-\s]?\d{6,12}\b/i',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.85,
                'reason' => 'Customer Information File — pengenal nasabah. Format berbeda antar bank, sesuaikan bila perlu.',
                'sample_value' => 'CIF-00123456',
            ],
            [
                'key' => 'nomor_polis',
                'label' => 'Nomor Polis Asuransi',
                'pattern' => '/\b(?:POL|PLS)[-\s]?\d{6,12}\b/i',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.80,
                'reason' => 'Nomor polis — pengenal tertanggung. Format berbeda antar penerbit, sesuaikan bila perlu.',
                'sample_value' => 'POL-00123456',
            ],

            // ---------- Kesehatan & kepegawaian ----------
            [
                'key' => 'bpjs',
                'label' => 'Nomor BPJS',
                'pattern' => '/\b\d{13}\b/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.75,
                'reason' => 'Nomor peserta BPJS 13 digit — menyiratkan data kesehatan, Pasal 4 UU PDP.',
                'sample_value' => '0001234567890',
            ],
            [
                'key' => 'rekam_medis',
                'label' => 'Nomor Rekam Medis',
                'pattern' => '/\b(?:RM|MR)[-\s]?\d{6,10}\b/i',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.85,
                'reason' => 'Nomor rekam medis — data kesehatan, kategori paling sensitif. Format berbeda antar fasilitas kesehatan.',
                'sample_value' => 'RM-0012345',
            ],
            [
                'key' => 'nip_nrp',
                'label' => 'NIP / NRP Pegawai',
                'pattern' => '/\b\d{18}\b/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.70,
                'reason' => 'NIP pegawai negeri 18 digit — pengenal kepegawaian.',
                'sample_value' => '198501012010011001',
            ],

            // ---------- Lokasi & perangkat ----------
            //
            // UU PDP menyebut data lokasi secara eksplisit, dan pengenal
            // perangkat dapat mengikat perilaku ke satu orang meski namanya
            // tidak pernah muncul. Tapi pola di kelompok ini paling sering
            // salah tandai: kolom teknis penuh angka desimal dan alamat mesin.
            // Karena itu bobotnya lebih rendah, dan yang paling berisik
            // disemai NONAKTIF supaya tenant menyalakannya secara sadar.
            [
                'key' => 'koordinat_gps',
                'label' => 'Koordinat GPS (pasangan lintang, bujur)',
                // Sengaja menuntut PASANGAN lintang-bujur, bukan satu angka
                // desimal. Satu angka desimal cocok dengan hampir semua kolom
                // harga, bobot, dan persentase — tidak berguna sebagai sinyal.
                'pattern' => '/-?\d{1,2}\.\d{4,}\s*,\s*-?\d{1,3}\.\d{4,}/',
                'pdp_category' => 'spesifik',
                'classification' => 'sensitive',
                'encryption_required' => true,
                'weight' => 0.85,
                'reason' => 'Titik koordinat — data lokasi disebut eksplisit dalam UU PDP.',
                'sample_value' => '-6.200000, 106.816666',
            ],
            [
                'key' => 'mac_address',
                'label' => 'MAC Address',
                'pattern' => '/\b(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.90,
                'reason' => 'Alamat perangkat jaringan — pengenal perangkat yang bertahan lama.',
                'sample_value' => '00:1A:2B:3C:4D:5E',
            ],
            [
                'key' => 'imei',
                'label' => 'IMEI Perangkat',
                'pattern' => '/\b\d{15}\b/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                // Lebih rendah dari MAC: 15 digit polos juga muncul di nomor
                // transaksi dan nomor referensi.
                'weight' => 0.60,
                'reason' => 'IMEI 15 digit — pengenal ponsel. Perlu dikuatkan konteks kolomnya.',
                'sample_value' => '356938035643809',
            ],
            [
                'key' => 'alamat_ip',
                'label' => 'Alamat IP (IPv4)',
                'pattern' => '/\b(?:(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)\b/',
                'pdp_category' => 'umum',
                'classification' => 'pii',
                'encryption_required' => false,
                'weight' => 0.50,
                // Disemai NONAKTIF. Alamat IP memang data pribadi ketika
                // menunjuk pengguna, tetapi kolom log, konfigurasi, dan
                // inventaris server penuh dengan IP yang tidak menunjuk siapa
                // pun. Dinyalakan tanpa dipikir, ia menenggelamkan temuan yang
                // benar-benar penting.
                'is_active' => false,
                'reason' => 'Alamat IP pengguna termasuk data pribadi. Dinonaktifkan secara bawaan karena kolom log dan inventaris server juga cocok — nyalakan setelah memastikan kolom sasarannya.',
                'sample_value' => '192.168.1.100',
            ],
        ];
    }
}
