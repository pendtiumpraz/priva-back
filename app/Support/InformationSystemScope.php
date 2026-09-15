<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keterlihatan Sistem Informasi (Data Discovery) — DITURUNKAN dari induknya.
 *
 * `information_systems` tidak punya kolom penugasan sendiri, dan tautannya pun
 * tidak berupa kolom FK melainkan DUA TABEL PIVOT:
 *
 *   information_system_ropa    → ropas.assign_group
 *   information_system_vendor  → vendors.assign_group
 *
 * Satu sistem bisa memasok banyak kegiatan pemrosesan sekaligus — itu memang
 * sifatnya — jadi aturannya SALAH SATU: divisi mana pun yang punya RoPA atau
 * pihak ketiga di atas sistem itu berhak melihatnya.
 *
 * Sistem yang belum ditautkan ke apa pun tidak punya divisi dan terlihat oleh
 * semua orang. Itu justru yang diinginkan: katalog sistem yang baru dipindai
 * belum tahu miliknya siapa, dan menyembunyikannya akan membuat tak seorang pun
 * bisa menautkannya ke RoPA — keadaan yang mengunci dirinya sendiri.
 *
 * Amplopnya milik TurunanScope; di sini hanya definisi induknya.
 */
final class InformationSystemScope
{
    /** Nama tabel, dipakai untuk korelasi subquery — query luar tidak boleh ber-alias. */
    private const TABEL = 'information_systems';

    /**
     * Tempelkan klausa keterlihatan ke builder apa pun atas
     * `information_systems`.
     *
     * @param  User|null  $user
     */
    public static function terapkan(Builder $query, $user, string $orgId): void
    {
        TurunanScope::rakit($query, $user, $orgId, [
            self::indukRopa(...),
            self::indukPihakKetiga(...),
        ], self::TABEL.'.created_by');
    }

    private static function indukRopa(Builder $q, $user, string $orgId): void
    {
        self::lewatPivot($q, $user, $orgId, 'information_system_ropa', 'ropa_id', IndukTerlihat::ropa(...));
    }

    private static function indukPihakKetiga(Builder $q, $user, string $orgId): void
    {
        self::lewatPivot($q, $user, $orgId, 'information_system_vendor', 'vendor_id', IndukTerlihat::pihakKetiga(...));
    }

    /**
     * "Ada baris pivot untuk sistem ini yang menunjuk induk terlihat."
     *
     * Dua subquery bersarang, bukan join: klausa AssignmentScope menulis nama
     * kolom tanpa prefiks tabel, jadi subquery terdalamnya harus menunjuk satu
     * tabel induk sendirian.
     *
     * Pivotnya ikut disaring `org_id` sendiri — keduanya memang menyimpan kolom
     * itu justru untuk ini, dan query builder mentah tidak pernah kena global
     * scope `org`.
     *
     * @param  callable(Builder, mixed, string): void  $induk
     */
    private static function lewatPivot(Builder $q, $user, string $orgId, string $pivot, string $kolomInduk, callable $induk): void
    {
        $q->select(DB::raw(1))
            ->from($pivot)
            ->where('org_id', $orgId)
            ->whereColumn($pivot.'.information_system_id', self::TABEL.'.id')
            ->whereIn($kolomInduk, function ($baris) use ($user, $orgId, $induk) {
                $baris->select('id');
                $induk($baris, $user, $orgId);
            });
    }
}
