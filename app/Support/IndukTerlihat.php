<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Subquery "induk yang terlihat".
 *
 * Beberapa modul tidak punya kolom penugasan sendiri dan divisinya DITURUNKAN
 * dari baris lain: telaah kontrak dari pihak ketiganya, transfer lintas negara
 * dari pihak ketiga/RoPA-nya, TIA dari ketiga-tiganya. Yang mereka butuhkan
 * sama — "baris induk mana yang boleh dilihat orang ini" — dan itu tinggal di
 * sini supaya ketiganya memakai satu bentuk, bukan tiga salinan yang pasti
 * menyimpang cepat atau lambat.
 *
 * Aturan divisinya sendiri tetap milik AssignmentScope. Tiap subquery menunjuk
 * SATU tabel tanpa join, supaya nama kolom `assign_group`/`assignees` tidak
 * pernah ambigu.
 *
 * Memanggil dengan `$user = null` sengaja menghasilkan subquery TANPA batas
 * divisi — itulah yang dipakai untuk menjawab pertanyaan berbeda: "apakah
 * induknya ADA sama sekali", yang menentukan sebuah baris yatim (tanpa induk
 * atau induknya sudah hilang permanen) diperlakukan sebagai tanpa divisi.
 *
 * Baris induk yang dihapus LUNAK sengaja tidak disaring: barisnya masih ada
 * beserta `assign_group`-nya, jadi divisinya masih bisa dibaca dan tetap
 * berlaku. Yang hilang permanen barulah membuat divisinya tak tertentukan.
 */
final class IndukTerlihat
{
    /**
     * Arahkan subquery ke `ropas` dan batasi ke yang terlihat oleh $user.
     *
     * Pemanggil menentukan sendiri `select()`-nya (`'id'` untuk whereIn,
     * `DB::raw(1)` untuk whereExists).
     *
     * @param  User|null  $user  null = tanpa batas divisi
     */
    public static function ropa(Builder $q, $user, string $orgId): void
    {
        $q->from('ropas')->where('org_id', $orgId);

        // RoPA menyimpan divisi terlibat juga di `wizard_data` — argumen yang
        // sama dengan Ropa::scopeVisibleTo lewat trait AssignmentVisibility.
        AssignmentScope::terapkan($q, $user, pakaiCreatedBy: true, pakaiWizardRopa: true);
    }

    /**
     * Arahkan subquery ke `dpias` dan batasi ke yang terlihat oleh $user.
     *
     * DPIA punya kolom penugasannya sendiri, tapi TIDAK menyimpan divisi di
     * wizard seperti RoPA — argumen yang sama dengan Dpia lewat trait
     * AssignmentVisibility (lihat RelationCatalog::visibilityByType).
     *
     * @param  User|null  $user  null = tanpa batas divisi
     */
    public static function dpia(Builder $q, $user, string $orgId): void
    {
        $q->from('dpias')->where('org_id', $orgId);

        AssignmentScope::terapkan($q, $user, pakaiCreatedBy: true);
    }

    /**
     * Arahkan subquery ke `vendors` dan batasi ke yang terlihat oleh $user.
     *
     * @param  User|null  $user  null = tanpa batas divisi
     */
    public static function pihakKetiga(Builder $q, $user, string $orgId): void
    {
        $q->from('vendors')->where('org_id', $orgId);

        // Pihak ketiga tidak punya kolom `created_by` — argumen yang sama
        // dengan Vendor::scopeVisibleTo.
        AssignmentScope::terapkan($q, $user, pakaiCreatedBy: false);
    }

    /**
     * Ekspresi SQL "teks JSON di $kolomJson memuat id dari $kolomId".
     *
     * `cross_border_transfers.linked_ropa_ids` adalah larik JSON, dan tidak ada
     * cara PORTABEL menanyakan "ada anggota larik yang cocok dengan hasil
     * subquery" lewat query builder: `whereJsonContains()` hanya menerima
     * NILAI, bukan kolom. Karena itu pencocokannya dilakukan pada teks JSON-nya.
     *
     * Itu aman justru karena isinya UUID: semua id panjangnya sama persis (36
     * karakter) sehingga satu UUID tidak mungkin menjadi substring UUID lain,
     * dan tiap elemen divalidasi `uuid` (CrossBorderController::validatePayload)
     * sehingga tidak ada bentuk lain yang bisa masuk ke kolom itu.
     *
     * Pencocokan teks tidak memakai indeks — tapi JSON_CONTAINS pun tidak, jadi
     * tidak ada yang dikorbankan di sini.
     */
    public static function memuatId(string $kolomJson, string $kolomId): string
    {
        return match (DB::connection()->getDriverName()) {
            // Postgres tegas soal tipe: kolom `json` dan kolom `uuid` dua-duanya
            // harus di-cast ke text dulu sebelum LIKE/penyambungan.
            'pgsql' => "{$kolomJson}::text LIKE '%' || {$kolomId}::text || '%'",
            'mysql', 'mariadb' => "{$kolomJson} LIKE CONCAT('%', {$kolomId}, '%')",
            // sqlite — `||` adalah penyambung standarnya.
            default => "{$kolomJson} LIKE '%' || {$kolomId} || '%'",
        };
    }
}
