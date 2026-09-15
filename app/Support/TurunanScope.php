<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Rangka "keterlihatan yang diturunkan dari induk".
 *
 * Enam modul di skema ini tidak punya kolom penugasan sendiri dan divisinya
 * diambil dari baris yang ditautkannya: telaah kontrak, transfer lintas negara,
 * TIA, LIA, insiden, dan sistem informasi. BENTUK induknya berbeda-beda —
 * kolom FK, larik JSON, tabel pivot, bahkan induk yang keterlihatannya sendiri
 * turunan — dan itu memang harus ditulis apa adanya di masing-masing modul.
 *
 * Yang SAMA di keenamnya adalah amplopnya, dan justru amplop itu bagian yang
 * paling mudah salah tanpa ketahuan:
 *
 *   - "salah satu induk terlihat" harus OR, bukan AND. Sekali tertukar, baris
 *     yang menaut RoPA HR sekaligus pihak ketiga Keuangan justru hilang dari
 *     KEDUA divisi itu — dan tidak ada galat apa pun yang muncul;
 *   - klausa yatim harus memakai subquery yang SAMA tapi tanpa batas divisi,
 *     supaya "induknya tidak terlihat" tidak pernah tertukar dengan "induknya
 *     tidak ada". Kalau tertukar, seluruh saringan gagal TERBUKA;
 *   - di dalam klausa yatim, syaratnya AND: baris baru disebut yatim kalau
 *     TIDAK SATU PUN induknya ada.
 *
 * Karena itu amplopnya ditulis sekali di sini dan diuji lewat modul-modulnya,
 * sementara definisi induknya tetap eksplisit dan terbaca di tiap kelas scope.
 *
 * Batas tenant (`org_id`) tetap wajib dipasang pemanggil pada query luarnya —
 * kelas ini hanya MENAMBAH klausa dan tidak pernah melonggarkan apa pun.
 *
 * Baris induk yang dihapus LUNAK sengaja tidak disaring di mana pun: barisnya
 * masih ada beserta `assign_group`-nya, jadi divisinya masih bisa dibaca dan
 * tetap berlaku. Yang hilang PERMANEN barulah membuat divisinya tak
 * tertentukan — dan baris itu lalu diperlakukan sebagai tanpa divisi, karena
 * menyembunyikannya akan membuat data yang sah lenyap selamanya dari semua
 * orang kecuali DPO/admin.
 */
final class TurunanScope
{
    /**
     * Rakit klausa keterlihatan dari daftar induk.
     *
     * Tiap $induk adalah callable(Builder $subquery, $user, string $orgId): void
     * yang MENGISI subquery untuk dipakai di EXISTS. Ia dipanggil dua kali
     * dengan arti berbeda:
     *
     *   $user terisi → "induknya ada DAN terlihat oleh orang ini"
     *   $user null   → "induknya ada" (tanpa batas divisi sama sekali)
     *
     * Pasangan itulah yang membedakan yatim dari tersaring, jadi tiap klausa
     * induk WAJIB meneruskan $user apa adanya ke AssignmentScope — jangan
     * pernah menggantinya dengan pengguna lain atau memaksanya non-null.
     *
     * MENGUBAH query di tempat dan tidak mengembalikan apa pun — alasan yang
     * sama dengan AssignmentScope::terapkan.
     *
     * @param  User|null  $user
     * @param  list<callable(Builder, mixed, string): void>  $induk
     * @param  string|null  $kolomPembuat  kolom pembuat berkualifikasi tabel, bila tabelnya punya
     */
    public static function rakit(Builder $query, $user, string $orgId, array $induk, ?string $kolomPembuat = null): void
    {
        if (! $user || AssignmentScope::melihatSeluruhTenant($user)) {
            return;
        }

        $query->where(function ($w) use ($user, $orgId, $induk, $kolomPembuat) {
            // Salah satu induk terlihat — cukup satu.
            foreach ($induk as $klausa) {
                $w->orWhereExists(fn ($q) => $klausa($q, $user, $orgId));
            }

            // Pembuatnya sendiri, pada tabel yang punya kolomnya. Aturan yang
            // sama dengan klausa (c) di AssignmentScope.
            if ($kolomPembuat !== null) {
                $w->orWhere($kolomPembuat, $user->id);
            }

            // Yatim: tidak ada SATU PUN induk yang bisa menentukan divisinya.
            $w->orWhere(function ($yatim) use ($orgId, $induk) {
                foreach ($induk as $klausa) {
                    $yatim->whereNotExists(fn ($q) => $klausa($q, null, $orgId));
                }
            });
        });
    }
}
