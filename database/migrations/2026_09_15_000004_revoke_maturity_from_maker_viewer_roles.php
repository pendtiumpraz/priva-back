<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cabut izin `maturity` dari role Maker & Viewer pada tenant yang sudah berjalan.
 *
 * Penilaian Tingkat Kematangan mengukur program privasi organisasi secara
 * menyeluruh — bawaannya milik DPO dan admin tenant. Sebelum ini rute
 * `/api/maturity/*` bahkan TIDAK punya gerbang izin sama pun, sehingga setiap
 * pengguna yang login bisa membuka dan mengisinya. Gerbangnya kini terpasang
 * (routes/api.php), dan migrasi ini menyesuaikan role yang sudah terlanjur
 * memegang izinnya.
 *
 * INI MENGUBAH AKSES TENANT YANG SEDANG BERJALAN — disengaja, dan itulah
 * inti perubahannya. Yang TIDAK disentuh:
 *
 *   - role Admin (berizin '*'), DPO, dan role kustom apa pun. Hanya DUA nama
 *     role bawaan yang disasar, dan hanya kalau `is_system` benar — role
 *     bernama sama yang dibuat sendiri oleh tenant tidak ikut diubah;
 *   - izin modul lain pada role yang sama;
 *   - tenant yang memang sengaja memberi maturity ke role kustom.
 *
 * Admin tenant tetap dapat memberikan izin ini kembali ke siapa pun lewat
 * Pengaturan → Role Management. Bukti maturity lazimnya diunggah tim
 * IT/keamanan di bawah supervisi DPO, jadi jalan itu memang perlu tetap ada —
 * yang berubah hanyalah: sekarang harus diberikan secara sadar, bukan melekat
 * begitu saja.
 *
 * down() sengaja TIDAK mengembalikan izinnya. Mengembalikan akses secara
 * otomatis pada rollback berarti memperluas akses tanpa ada yang memutuskan —
 * arah yang tidak boleh terjadi diam-diam. Pemulihannya lewat UI Role
 * Management, oleh orang yang memang berwenang.
 */
return new class extends Migration
{
    private const ROLE_DISASAR = ['Maker', 'Viewer'];

    public function up(): void
    {
        DB::table('tenant_roles')
            ->whereIn('name', self::ROLE_DISASAR)
            ->where('is_system', true)
            ->orderBy('id')
            ->chunkById(200, function ($peran) {
                foreach ($peran as $p) {
                    $izin = json_decode((string) $p->permissions, true);
                    if (! is_array($izin)) {
                        continue;
                    }

                    // Wildcard '*' berarti akses penuh yang memang disengaja —
                    // jangan diutak-atik lewat migrasi.
                    if (in_array('*', $izin, true)) {
                        continue;
                    }

                    $bersih = array_values(array_filter(
                        $izin,
                        fn ($v) => ! is_string($v) || ! str_starts_with($v, 'maturity'),
                    ));

                    if (count($bersih) === count($izin)) {
                        continue;
                    }

                    DB::table('tenant_roles')
                        ->where('id', $p->id)
                        ->update(['permissions' => json_encode($bersih)]);
                }
            });
    }

    public function down(): void
    {
        // Sengaja kosong — lihat catatan di atas.
    }
};
