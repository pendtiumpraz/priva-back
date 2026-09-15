<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keterlihatan Transfer Lintas Negara — DITURUNKAN dari induknya.
 *
 * `cross_border_transfers` tidak punya kolom penugasan sendiri (`assign_group`
 * / `assignees`) dan bahkan tidak punya `created_by`. Divisinya diambil dari
 * baris yang ditautkannya:
 *
 *   vendor_id        → vendors.assign_group       (pihak ketiga penerimanya)
 *   linked_ropa_id   → ropas.assign_group         (RoPA utamanya)
 *   linked_ropa_ids  → ropas.assign_group         (RoPA tambahan, larik JSON)
 *
 * Aturannya SALAH SATU, bukan semua: transfer terlihat kalau paling tidak satu
 * induknya terlihat. "Semua" akan membuat transfer yang menaut RoPA HR sekaligus
 * pihak ketiga Keuangan justru tidak terlihat oleh kedua divisi itu.
 *
 * `linked_ropa_ids` ikut diperiksa dan itu bukan kelengkapan yang berlebihan:
 * CrossBorderController menyinkronkan `linked_ropa_id` ke ELEMEN PERTAMA larik
 * itu saja, jadi tanpa klausanya divisi yang tercantum di urutan kedua dan
 * seterusnya tidak akan pernah bisa melihat transfer yang ditugaskan kepadanya.
 *
 * Transfer yang tidak punya induk sama sekali — atau induknya sudah hilang
 * permanen — tidak punya divisi, jadi terlihat oleh semua orang di tenant itu,
 * persis seperti `assign_group` NULL di tabel mana pun. Lihat IndukTerlihat
 * untuk alasan hapus-lunak diperlakukan berbeda dari hapus permanen.
 *
 * Batas tenant (`org_id`) tetap wajib dipasang pemanggil — kelas ini hanya
 * MENAMBAH klausa dan tidak pernah melonggarkan apa pun.
 */
final class CrossBorderScope
{
    /** Nama tabel, dipakai untuk korelasi subquery — query luar tidak boleh ber-alias. */
    private const TABEL = 'cross_border_transfers';

    /**
     * Tempelkan klausa keterlihatan ke builder apa pun atas
     * `cross_border_transfers`.
     *
     * MENGUBAH query di tempat dan tidak mengembalikan apa pun — alasan yang
     * sama dengan AssignmentScope::terapkan.
     *
     * @param  User|null  $user
     */
    public static function terapkan(Builder $query, $user, string $orgId): void
    {
        if (! $user || AssignmentScope::melihatSeluruhTenant($user)) {
            return;
        }

        $query->where(function ($w) use ($user, $orgId) {
            // (a) Pihak ketiga penerimanya terlihat.
            $w->whereExists(fn ($q) => self::indukPihakKetiga($q, $user, $orgId));

            // (b) Salah satu RoPA yang ditautkannya terlihat.
            $w->orWhereExists(fn ($q) => self::indukRopa($q, $user, $orgId));

            // (c) Tidak ada induk yang bisa menentukan divisinya. Ditanyakan
            //     dengan subquery yang SAMA tapi tanpa batas divisi ($user
            //     null), sehingga "induknya tidak terlihat" tidak pernah
            //     tertukar dengan "induknya tidak ada".
            $w->orWhere(function ($yatim) use ($orgId) {
                $yatim->whereNotExists(fn ($q) => self::indukPihakKetiga($q, null, $orgId))
                    ->whereNotExists(fn ($q) => self::indukRopa($q, null, $orgId));
            });
        });
    }

    /** Pihak ketiga penerima transfer ini. */
    private static function indukPihakKetiga(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::pihakKetiga($q, $user, $orgId);
        $q->whereColumn('vendors.id', self::TABEL.'.vendor_id');
    }

    /** RoPA yang ditautkan transfer ini — kolom tunggal ATAU larik JSON. */
    private static function indukRopa(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1));
        IndukTerlihat::ropa($q, $user, $orgId);
        $q->where(function ($tautan) {
            $tautan->whereColumn('ropas.id', self::TABEL.'.linked_ropa_id')
                ->orWhereRaw(IndukTerlihat::memuatId(self::TABEL.'.linked_ropa_ids', 'ropas.id'));
        });
    }
}
