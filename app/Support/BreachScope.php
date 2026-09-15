<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keterlihatan Insiden (Breach) — DITURUNKAN dari induknya.
 *
 * `breach_incidents` tidak punya kolom penugasan sendiri. Divisinya diambil dari
 * baris yang ditautkannya:
 *
 *   linked_ropa_id     → ropas.assign_group    (RoPA utama)
 *   linked_ropa_ids    → ropas.assign_group    (RoPA terdampak, larik JSON)
 *   linked_vendor_ids  → vendors.assign_group  (pihak ketiga terlibat, larik JSON)
 *
 * Aturannya SALAH SATU. Satu insiden kebocoran hampir selalu menyentuh lebih
 * dari satu divisi — itu memang sifat insiden — dan tiap divisi yang datanya
 * terdampak berhak melihatnya. Menuntut semua induk terlihat justru akan
 * menyembunyikan insiden terbesar dari semua orang kecuali DPO.
 *
 * Kedua larik JSON diperiksa dengan pencocokan teks; alasannya, batasnya, dan
 * mengapa itu aman untuk UUID ada di IndukTerlihat::memuatId.
 *
 * CATATAN PENTING soal batas waktu: penyaringan ini TIDAK berlaku bagi DPO
 * maupun admin tenant (lihat AssignmentScope::melihatSeluruhTenant), dan
 * merekalah yang memikul kewajiban notifikasi 3x24 jam pada UU PDP Pasal 46.
 * Jadi tidak ada insiden yang bisa lolos dari pandangan pihak yang wajib
 * melaporkannya — penyaringan ini hanya merapikan pandangan staf divisi.
 */
final class BreachScope
{
    /** Nama tabel, dipakai untuk korelasi subquery — query luar tidak boleh ber-alias. */
    private const TABEL = 'breach_incidents';

    /**
     * Tempelkan klausa keterlihatan ke builder apa pun atas `breach_incidents`.
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

    /** RoPA terdampak — kolom tunggal ATAU larik JSON. */
    private static function indukRopa(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::ropa($q, $user, $orgId);
        $q->where(function ($tautan) {
            $tautan->whereColumn('ropas.id', self::TABEL.'.linked_ropa_id')
                ->orWhereRaw(IndukTerlihat::memuatId(self::TABEL.'.linked_ropa_ids', 'ropas.id'));
        });
    }

    /** Pihak ketiga yang terlibat — larik JSON saja, tidak ada kolom tunggalnya. */
    private static function indukPihakKetiga(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::pihakKetiga($q, $user, $orgId);
        $q->whereRaw(IndukTerlihat::memuatId(self::TABEL.'.linked_vendor_ids', 'vendors.id'));
    }
}
