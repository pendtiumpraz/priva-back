<?php

namespace App\Support;

use App\Models\User;

/**
 * Penugasan divisi otomatis saat membuat, dan kuncinya saat mengubah.
 *
 * Aturannya:
 *
 *   1. Orang dari divisi D membuat record → `assign_group` otomatis memuat D.
 *   2. Divisi lain boleh DITAMBAHKAN, atau penugasannya diperluas ke
 *      "(All Group)".
 *   3. D TIDAK BISA DILEPAS. Itu bagian yang harus ditegakkan di server —
 *      menyembunyikan tombolnya di UI bukan penegakan, cuma penyamaran.
 *   4. Tidak berlaku bagi yang memang bekerja lintas divisi (DPO, admin
 *      tenant, superadmin) — mereka sering membuatkan untuk divisi lain, dan
 *      mengunci mereka ke divisinya sendiri justru menghalangi pekerjaannya.
 *
 * KENAPA ini penting: sebelum ini `assign_group` hanya diisi dari payload, dan
 * NULL berarti "(All Group)" di AssignmentScope. Artinya staf HR yang membuat
 * RoPA menghasilkan record yang terbaca SELURUH tenant — kebalikan dari yang
 * dimaksud penyaringan divisi. Seluruh saringan yang ada praktis diam untuk
 * record baru sampai ada yang menugaskannya manual.
 *
 * Divisi yang dikunci disimpan di kolom `origin_division`, bukan diturunkan
 * dari `created_by` — alasannya ada di migrasi
 * 2026_09_15_000002_add_origin_division_to_assignable_tables.
 *
 * Hanya `ropas`, `dpias`, dan `vendors` yang punya `assign_group`; enam modul
 * turunan (TIA, LIA, insiden, transfer lintas negara, telaah kontrak, sistem
 * informasi) MEWARISI divisinya lewat TurunanScope, jadi tidak ada yang perlu
 * dikunci di sana.
 */
final class PenugasanDivisi
{
    /**
     * Divisi yang harus dikunci untuk record yang dibuat orang ini.
     *
     * null = tidak ada yang dikunci, dan itu keadaan yang sah: pembuatnya
     * bekerja lintas divisi, atau memang tidak punya divisi.
     *
     * @param  User|null  $pembuat
     */
    public static function divisiPembuat($pembuat): ?string
    {
        if (! $pembuat || AssignmentScope::melihatSeluruhTenant($pembuat)) {
            return null;
        }

        $divisi = trim((string) (optional($pembuat->department)->name ?? ''));

        return $divisi === '' ? null : $divisi;
    }

    /**
     * Lengkapi payload pembuatan: set `origin_division` dan pastikan
     * `assign_group` memuatnya.
     *
     * Payload yang sudah menyebut divisi lain tidak dibuang — divisi asalnya
     * DITAMBAHKAN ke daftar itu, karena aturan (2) memang membolehkan banyak
     * divisi sekaligus.
     *
     * @param  array<string, mixed>  $data
     * @param  User|null  $pembuat
     * @return array<string, mixed>
     */
    public static function saatBuat(array $data, $pembuat): array
    {
        $divisi = self::divisiPembuat($pembuat);
        if ($divisi === null) {
            return $data;
        }

        $data['origin_division'] = $divisi;

        $diminta = trim((string) ($data['assign_group'] ?? ''));

        // INTI FITURNYA. Kalau tidak ada penugasan yang diminta, record baru
        // jatuh ke divisi pembuatnya — BUKAN ke NULL, yang di AssignmentScope
        // berarti "(All Group)" dan membuatnya terbaca seluruh tenant.
        //
        // Perhatikan ini sengaja TIDAK lewat gabung(): bagi gabung(), daftar
        // kosong sudah memuat divisi mana pun, sehingga NULL akan dibiarkan
        // NULL dan seluruh fitur ini diam-diam tidak terjadi.
        if ($diminta === '') {
            $data['assign_group'] = $divisi;

            return $data;
        }

        // Sudah menyebut divisi lain, atau sengaja diperluas ke "(All Group)" —
        // dihormati, asal divisi asalnya ikut termuat.
        $data['assign_group'] = self::gabung($diminta, $divisi);

        return $data;
    }

    /**
     * Jaga payload perubahan: `assign_group` tidak boleh kehilangan divisi asal,
     * dan `origin_division` tidak boleh disetel ulang lewat payload.
     *
     * Dipanggil dengan divisi asal yang TERSIMPAN di record, bukan divisi orang
     * yang sedang mengubah — kuncinya milik record.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function saatUbah(array $data, ?string $divisiAsal): array
    {
        // Kolomnya tidak pernah boleh datang dari luar. Tanpa ini, satu baris
        // di payload cukup untuk melepas kuncinya sendiri.
        unset($data['origin_division']);

        if ($divisiAsal === null || $divisiAsal === '') {
            return $data;
        }

        // Hanya ikut campur kalau penugasannya memang sedang disentuh.
        if (! array_key_exists('assign_group', $data)) {
            return $data;
        }

        $data['assign_group'] = self::gabung($data['assign_group'], $divisiAsal);

        return $data;
    }

    /**
     * Apakah daftar divisi ini memuat $divisi?
     *
     * NULL dan "(All Group)" dihitung MEMUAT: keduanya berarti berlaku untuk
     * semua divisi, jadi divisi asalnya sudah termasuk di dalamnya.
     */
    public static function memuat(?string $daftar, string $divisi): bool
    {
        $daftar = trim((string) ($daftar ?? ''));

        if ($daftar === '' || $daftar === AssignmentScope::SEMUA) {
            return true;
        }

        return in_array($divisi, self::pecah($daftar), true);
    }

    /**
     * Gabungkan $divisi ke dalam daftar, tanpa menduplikasi dan tanpa mengubah
     * urutan yang sudah ada.
     *
     * Daftar kosong dan "(All Group)" berarti sudah memuat divisi mana pun, dan
     * memaksa divisi asal masuk ke sana justru MENYEMPITKAN penugasan yang
     * sengaja dibuat terbuka. Itulah sebabnya saatBuat() tidak boleh memakai
     * metode ini untuk kasus kosongnya.
     *
     * Daftar kosong dinormalkan menjadi NULL, dan itu BUKAN kerapian belaka:
     * AssignmentScope memperlakukan NULL dan string kosong sangat berbeda.
     * Klausanya berbunyi `whereNull('assign_group') OR assign_group = '(All
     * Group)'` — string kosong tidak cocok dengan keduanya, dan tidak cocok
     * pula dengan klausa divisi mana pun. Menyimpan '' karena itu membuat
     * record LENYAP dari semua orang kecuali pembuatnya, tanpa satu pun galat.
     */
    public static function gabung(?string $daftar, string $divisi): ?string
    {
        if (self::memuat($daftar, $divisi)) {
            return trim((string) ($daftar ?? '')) === '' ? null : $daftar;
        }

        $bagian = self::pecah((string) $daftar);
        $bagian[] = $divisi;

        return implode(AssignmentScope::DELIM, $bagian);
    }

    /**
     * Pecah `assign_group` menjadi daftar nama divisi.
     *
     * @return list<string>
     */
    public static function pecah(string $daftar): array
    {
        $bagian = array_map('trim', explode(AssignmentScope::DELIM, $daftar));

        return array_values(array_filter($bagian, fn ($b) => $b !== ''));
    }
}
