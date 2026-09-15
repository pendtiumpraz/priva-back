<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keterlihatan TIA — DITURUNKAN dari induknya.
 *
 * `tia_assessments` tidak punya kolom penugasan sendiri (`assign_group` /
 * `assignees`). Divisinya diambil dari baris yang ditautkannya:
 *
 *   linked_ropa_id         → ropas.assign_group
 *   linked_vendor_id       → vendors.assign_group
 *   linked_cross_border_id → transfer lintas negara — yang keterlihatannya
 *                            SENDIRI juga turunan, jadi klausanya bersarang
 *                            lewat CrossBorderScope, bukan disalin ulang.
 *
 * Aturannya SALAH SATU: cukup satu induk terlihat. "Semua" akan membuat TIA
 * yang menaut RoPA HR sekaligus pihak ketiga Keuangan justru tidak terlihat
 * oleh kedua divisi itu.
 *
 * Berbeda dari transfer lintas negara, tabel ini PUNYA `created_by` — dan
 * pembuatnya selalu boleh melihat hasil kerjanya sendiri, sama seperti aturan
 * (c) di AssignmentScope. Itu bukan celah: seseorang hanya bisa membuat TIA
 * dari sumber yang memang terlihat olehnya, karena ketiga endpoint
 * `/tia/from-*` membaca induknya lewat saringan yang sama.
 *
 * TIA tanpa induk sama sekali — atau induknya sudah hilang permanen — tidak
 * punya divisi, jadi terlihat oleh semua orang di tenant itu, persis seperti
 * `assign_group` NULL di tabel mana pun.
 *
 * Batas tenant (`org_id`) tetap wajib dipasang pemanggil.
 */
final class TiaScope
{
    /** Nama tabel, dipakai untuk korelasi subquery — query luar tidak boleh ber-alias. */
    private const TABEL = 'tia_assessments';

    /**
     * Tempelkan klausa keterlihatan ke builder apa pun atas `tia_assessments`.
     *
     * MENGUBAH query di tempat dan tidak mengembalikan apa pun.
     *
     * @param  User|null  $user
     */
    public static function terapkan(Builder $query, $user, string $orgId): void
    {
        if (! $user || AssignmentScope::melihatSeluruhTenant($user)) {
            return;
        }

        $query->where(function ($w) use ($user, $orgId) {
            // (a) RoPA yang ditautkannya terlihat.
            $w->whereExists(fn ($q) => self::indukRopa($q, $user, $orgId));

            // (b) Pihak ketiga yang ditautkannya terlihat.
            $w->orWhereExists(fn ($q) => self::indukPihakKetiga($q, $user, $orgId));

            // (c) Transfer lintas negara yang ditautkannya terlihat.
            $w->orWhereExists(fn ($q) => self::indukTransfer($q, $user, $orgId));

            // (d) Dibuatnya sendiri.
            $w->orWhere(self::TABEL.'.created_by', $user->id);

            // (e) Tidak ada induk yang bisa menentukan divisinya. Ditanyakan
            //     dengan subquery yang SAMA tapi tanpa batas divisi ($user
            //     null), sehingga "induknya tidak terlihat" tidak pernah
            //     tertukar dengan "induknya tidak ada".
            $w->orWhere(function ($yatim) use ($orgId) {
                $yatim->whereNotExists(fn ($q) => self::indukRopa($q, null, $orgId))
                    ->whereNotExists(fn ($q) => self::indukPihakKetiga($q, null, $orgId))
                    ->whereNotExists(fn ($q) => self::indukTransfer($q, null, $orgId));
            });
        });
    }

    private static function indukRopa(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::ropa($q, $user, $orgId);
        $q->whereColumn('ropas.id', self::TABEL.'.linked_ropa_id');
    }

    private static function indukPihakKetiga(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::pihakKetiga($q, $user, $orgId);
        $q->whereColumn('vendors.id', self::TABEL.'.linked_vendor_id');
    }

    /**
     * Transfer lintas negara yang ditautkan TIA ini.
     *
     * Keterlihatan transfer itu sendiri juga turunan, jadi aturannya dipinjam
     * utuh dari CrossBorderScope — bukan ditulis ulang. Subquery-nya ber-FROM
     * `cross_border_transfers`, yaitu tabel yang memang dirujuk klausa-klausa
     * di sana, sehingga korelasinya tetap benar meski bersarang dua lapis.
     */
    private static function indukTransfer(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1))
            ->from('cross_border_transfers')
            ->where('org_id', $orgId)
            ->whereColumn('cross_border_transfers.id', self::TABEL.'.linked_cross_border_id');

        CrossBorderScope::terapkan($q, $user, $orgId);
    }
}
