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
        if (! $user || AssignmentScope::melihatSeluruhTenant($user)) {
            return;
        }

        $query->where(function ($w) use ($user, $orgId) {
            // (a) Pihak ketiga di ujung rantainya terlihat oleh user ini.
            //
            // Dua subquery bersarang, bukan join: klausa AssignmentScope
            // menulis nama kolom tanpa prefiks tabel, jadi ia hanya boleh
            // menempel pada query yang tabelnya cuma `vendors`.
            $w->whereIn('source_document_id', function ($kontrak) use ($user, $orgId) {
                $kontrak->select('id')
                    ->from('vendor_contracts')
                    ->where('org_id', $orgId)
                    ->whereIn('vendor_id', function ($pihak) use ($user, $orgId) {
                        $pihak->select('id')
                            ->from('vendors')
                            ->where('org_id', $orgId);

                        // Pihak ketiga tidak punya kolom `created_by` —
                        // argumen yang sama dengan Vendor::scopeVisibleTo.
                        AssignmentScope::terapkan($pihak, $user, pakaiCreatedBy: false);
                    });
            });

            // (b) Tidak ada kontrak pihak ketiga di baliknya — telaah ini
            //     tidak punya divisi. Lihat docblock kelas untuk ketiga
            //     bentuknya (unggahan langsung, Document Maker, rantai putus).
            $w->orWhereNotExists(function ($kontrak) use ($orgId) {
                $kontrak->select(DB::raw(1))
                    ->from('vendor_contracts')
                    ->where('org_id', $orgId)
                    ->whereColumn('vendor_contracts.id', 'contract_reviews.source_document_id');
            });
        });
    }
}
