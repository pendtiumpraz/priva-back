<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keterlihatan LIA — DITURUNKAN dari induknya.
 *
 * `lia_assessments` tidak punya kolom penugasan sendiri. Divisinya diambil dari
 * baris yang ditautkannya:
 *
 *   linked_ropa_id → ropas.assign_group
 *   linked_dpia_id → dpias.assign_group
 *
 * Aturannya SALAH SATU, dan di sini itu penting: LIA menilai keseimbangan
 * kepentingan sah, sehingga satu LIA sering merujuk RoPA milik divisi operasional
 * sekaligus DPIA yang dikerjakan divisi lain. Menuntut keduanya terlihat akan
 * menyembunyikan LIA itu dari kedua-duanya.
 *
 * Amplopnya — "salah satu terlihat", klausa pembuat, dan klausa yatim — milik
 * TurunanScope; di sini hanya definisi induknya.
 */
final class LiaScope
{
    /** Nama tabel, dipakai untuk korelasi subquery — query luar tidak boleh ber-alias. */
    private const TABEL = 'lia_assessments';

    /**
     * Tempelkan klausa keterlihatan ke builder apa pun atas `lia_assessments`.
     *
     * @param  User|null  $user
     */
    public static function terapkan(Builder $query, $user, string $orgId): void
    {
        TurunanScope::rakit($query, $user, $orgId, [
            self::indukRopa(...),
            self::indukDpia(...),
        ], self::TABEL.'.created_by');
    }

    private static function indukRopa(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::ropa($q, $user, $orgId);
        $q->whereColumn('ropas.id', self::TABEL.'.linked_ropa_id');
    }

    private static function indukDpia(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::dpia($q, $user, $orgId);
        $q->whereColumn('dpias.id', self::TABEL.'.linked_dpia_id');
    }
}
