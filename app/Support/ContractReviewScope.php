<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keterlihatan Contract Review — DITURUNKAN dari pihak ketiganya.
 *
 * `contract_reviews` tidak punya kolom penugasan sendiri (`assign_group` /
 * `assignees`), dan memang tidak perlu punya: telaah kontrak selalu "kontrak
 * milik siapa". Divisinya karena itu diambil dari ujung rantai
 *
 *   contract_reviews.source_document_id
 *     → vendor_contracts.id
 *     → vendor_contracts.vendor_id
 *       → vendors.assign_group / vendors.assignees
 *
 * Aturan divisinya sendiri TIDAK ditulis ulang di sini. Klausanya tetap milik
 * AssignmentScope dan dipasang pada subquery `vendors` — satu tabel sendirian,
 * tanpa join — supaya nama kolom `assign_group`/`assignees` tidak pernah ambigu
 * dan tetap satu kata demi kata dengan yang dipakai registri pihak ketiga.
 *
 * Bentuk ini menyusul apa yang SUDAH berlaku di daftar kontrak TPRM
 * (VendorContractController::index, `whereHas('vendor', …->visibleTo($user))`).
 * Modul Contract Review tidak pernah ikut, sehingga telaah atas kontrak divisi
 * lain tetap terbuka lewat /contract-review walau kontraknya sendiri tidak
 * kelihatan di TPRM — dua halaman, dua jawaban, untuk berkas yang sama.
 *
 * Dua klausa, dan itu memang seluruh aturannya:
 *
 *   (a) pihak ketiga di baliknya terlihat oleh user ini; ATAU
 *   (b) tidak ada kontrak pihak ketiga di baliknya sama sekali.
 *
 * (b) menampung tiga hal sekaligus, dan ketiganya berarti sama: telaah ini
 * tidak punya divisi. Yaitu unggahan langsung lewat /contract-review, kiriman
 * Document Maker (`source_module = 'document_maker'`, id-nya milik
 * `generated_documents`), dan kontrak yang sudah HILANG PERMANEN.
 *
 * Yang terakhir bukan kemungkinan teoretis: `DELETE /vendors/{id}/force`
 * menghapus keras, `vendor_contracts.vendor_id` ber-cascadeOnDelete, sedangkan
 * baris telaahnya sengaja dibuat tanpa foreign key supaya hasil telaah tidak
 * ikut jatuh (lihat migrasi 2026_07_20_000001). Jadi rantainya benar-benar bisa
 * putus. Kalau putus, divisinya tidak bisa ditentukan lagi — dan telaah tanpa
 * divisi diperlakukan persis seperti `assign_group` NULL di tabel mana pun:
 * terlihat oleh semua orang di tenant itu. Menyembunyikannya justru membuat
 * baris yang sah lenyap selamanya dari semua orang kecuali DPO/admin.
 *
 * Yang dihapus LUNAK tidak termasuk: barisnya masih ada beserta
 * `assign_group`-nya, jadi divisinya masih bisa dibaca dan tetap berlaku.
 *
 * Batas tenant (`org_id`) tetap wajib dipasang pemanggil pada query luarnya —
 * kelas ini hanya MENAMBAH klausa dan tidak pernah melonggarkan apa pun.
 */
final class ContractReviewScope
{
    /** Nama tabel, dipakai untuk korelasi subquery — query luar tidak boleh ber-alias. */
    private const TABEL = 'contract_reviews';

    /**
     * Tempelkan klausa keterlihatan ke builder apa pun atas `contract_reviews`.
     *
     * MENGUBAH query di tempat dan tidak mengembalikan apa pun — alasan yang
     * sama dengan AssignmentScope::terapkan.
     *
     * Query luarnya HARUS `DB::table('contract_reviews')` tanpa alias: klausa
     * (b) menunjuk `contract_reviews.source_document_id` dengan nama tabelnya.
     *
     * @param  User|null  $user
     * @param  string  $orgId  batas tenant untuk subquery-nya sendiri
     */
    public static function terapkan(Builder $query, $user, string $orgId): void
    {
        // Amplopnya — termasuk klausa yatim, yang di sini menampung ketiga
        // bentuk "tanpa pihak ketiga" sekaligus — milik TurunanScope.
        //
        // `created_by` sengaja TIDAK dipakai walau kolomnya ada: telaah dari
        // TPRM hanya bisa dibuat oleh orang yang saat itu melihat kontraknya,
        // jadi klausa pembuat tidak akan pernah memperluas apa pun. Yang
        // diubahnya justru hal yang salah — kalau pihak ketiganya kemudian
        // dipindah ke divisi lain, pembuat lama tetap ikut. Perpindahan divisi
        // harus memindahkan akses.
        TurunanScope::rakit($query, $user, $orgId, [
            self::indukPihakKetiga(...),
        ]);
    }

    /**
     * Pihak ketiga di ujung rantai kontrak telaah ini.
     *
     * Subquery bersarang, bukan join: klausa AssignmentScope menulis nama kolom
     * tanpa prefiks tabel, jadi ia hanya boleh menempel pada query yang
     * tabelnya cuma `vendors`.
     */
    private static function indukPihakKetiga(Builder $q, $user, string $orgId): void
    {
        $q->select(DB::raw(1))
            ->from('vendor_contracts')
            ->where('org_id', $orgId)
            ->whereColumn('vendor_contracts.id', self::TABEL.'.source_document_id')
            ->whereIn('vendor_id', function ($pihak) use ($user, $orgId) {
                $pihak->select('id');
                IndukTerlihat::pihakKetiga($pihak, $user, $orgId);
            });
    }
}
