<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Keterlihatan baris berdasarkan penugasan divisi.
 *
 * SATU-SATUNYA tempat aturan "siapa boleh melihat baris apa" ditulis. Sebelum
 * ini aturannya hidup di dua tempat sekaligus — trait AssignmentVisibility untuk
 * RoPA/DPIA dan salinan tangan di Vendor::scopeVisibleTo — persis bentuk
 * duplikasi yang dulu melahirkan F-03.
 *
 * Klausanya ditulis terhadap KONTRAK builder, bukan terhadap model. Itu
 * disengaja: peta koneksi mengambil barisnya lewat `DB::table()` mentah, yang
 * tidak pernah kena scope Eloquent, sehingga peta memperlihatkan RoPA, DPIA, dan
 * pihak ketiga milik divisi lain walau tabelnya sendiri sudah menyembunyikannya.
 * Dengan bentuk ini builder Eloquent dan query builder mentah memakai aturan yang
 * sama, kata demi kata.
 *
 * Aturannya — user non-admin melihat baris yang:
 *   (a) `assign_group` kosong atau berisi sentinel "(All Group)" — milik semua;
 *   (b) memuat dirinya di `assignees`;
 *   (c) dibuatnya sendiri (`created_by`, pada tabel yang punya kolom itu);
 *   (d) `assign_group`-nya memuat nama divisinya.
 *
 * Batas tenant (`org_id`) BUKAN urusan kelas ini — ia hanya MENAMBAH klausa dan
 * tidak pernah melonggarkan apa pun. Pemanggil tetap wajib memasang org_id.
 */
final class AssignmentScope
{
    /**
     * Delimiter multi-divisi pada `assign_group` — HARUS identik dengan
     * konstanta FE `ASSIGN_DIV_DELIM` di AssignScopeModal.tsx.
     */
    public const DELIM = ' | ';

    /** Sentinel "berlaku untuk semua divisi". */
    public const SEMUA = '(All Group)';

    /**
     * Apakah orang ini memegang peran DPO?
     *
     * DUA cara menandainya, dan keduanya dipakai di lapangan: kolom `role`
     * global bernilai `dpo`, ATAU nama tenant role-nya `dpo`. Tenant yang
     * memakai role kustom bernama "DPO" harus tetap terbaca sebagai DPO —
     * kalau tidak, pemilih Pejabat PDP di wizard RoPA jadi kosong dan cakupan
     * DPO-nya salah dihitung.
     *
     * @param  User|null  $user
     */
    public static function berperanDpo($user): bool
    {
        if (! $user) {
            return false;
        }

        return ($user->role ?? '') === 'dpo'
            || strtolower((string) optional($user->tenantRole)->name) === 'dpo';
    }

    /**
     * Divisi yang dipakai menyaring baris untuk orang ini.
     *
     * Biasanya divisinya sendiri. Bedanya hanya untuk DPO se-perusahaan yang
     * sedang MEMPERSEMPIT pandangannya ke satu divisi — ia lalu melihat divisi
     * pilihannya, bukan divisi tempat akunnya terdaftar. Lihat CakupanDpo.
     *
     * @param  User|null  $user
     */
    public static function divisiEfektif($user): ?string
    {
        return CakupanDpo::pandanganDipersempit($user) ?? optional(optional($user)->department)->name;
    }

    /**
     * Apakah user ini menembus penyaringan divisi?
     *
     * @param  User|null  $user
     */
    public static function melihatSeluruhTenant($user): bool
    {
        if (! $user) {
            return false;
        }

        // Pemilik dan staf platform selalu tembus — mereka di atas tenant.
        if (in_array($user->role ?? '', ['root', 'superadmin'], true)) {
            return true;
        }

        // DPO diputuskan tersendiri, dan HARUS didahulukan: cakupannya diatur
        // admin tenant (se-perusahaan / per-divisi), dan ia sendiri boleh
        // mempersempit pandangannya. Kalau pemeriksaan izin '*' di bawah
        // dibiarkan lebih dulu, DPO per-divisi yang — seperti lazimnya —
        // berizin penuh atas semua modul akan tetap menembus batas divisi,
        // dan settingnya tidak pernah berarti apa-apa. '*' berarti "semua
        // MODUL", bukan "semua divisi".
        if (self::berperanDpo($user)) {
            return CakupanDpo::melihatSeluruhPerusahaan($user);
        }

        $tenantRole = $user->tenantRole;
        $izin = $tenantRole?->permissions;

        return ($user->role ?? '') === 'admin'
            || strtolower((string) optional($tenantRole)->name) === 'admin'
            // Admin tenant sering memakai NAMA role kustom tetapi berizin '*'
            // (akses penuh) — itulah cara kanonik aplikasi menandai "boleh lihat
            // semua" (lihat CheckPermission). Tanpa cek ini admin tenant ikut
            // tersaring dan halamannya kosong.
            || (is_array($izin) && in_array('*', $izin, true));
    }

    /**
     * Tempelkan klausa keterlihatan ke builder apa pun.
     *
     * MENGUBAH query di tempat dan tidak mengembalikan apa pun. Itu disengaja:
     * builder Eloquent dan query builder mentah punya tipe kembalian berbeda,
     * dan mengembalikan tipe supernya akan memaksa setiap pemanggil melebarkan
     * tipenya sendiri. Keduanya sama-sama mutable, jadi menempel di tempat tetap
     * benar untuk keduanya.
     *
     * Tidak melakukan apa-apa bila usernya tidak ada (konteks artisan, queue,
     * pemindai posture) atau memang menembus penyaringan.
     *
     * @param  User|null  $user
     * @param  bool  $pakaiCreatedBy  tabelnya punya `created_by` dan pembuat selalu boleh melihat
     * @param  bool  $pakaiWizardRopa  RoPA menyimpan divisi terlibat di wizard_data (multi-divisi + warisan)
     */
    public static function terapkan(Builder $query, $user, bool $pakaiCreatedBy = true, bool $pakaiWizardRopa = false): void
    {
        if (! $user || self::melihatSeluruhTenant($user)) {
            return;
        }

        $userId = $user->id;
        $divisi = self::divisiEfektif($user);

        $query->where(function ($w) use ($userId, $divisi, $pakaiCreatedBy, $pakaiWizardRopa) {
            // (a) Milik semua divisi.
            $w->where(function ($a) {
                $a->whereNull('assign_group')
                    ->orWhere('assign_group', self::SEMUA);
            });
            // (b) Ditugaskan langsung ke orangnya.
            $w->orWhereJsonContains('assignees', $userId);
            // (c) Pembuat record.
            if ($pakaiCreatedBy) {
                $w->orWhere('created_by', $userId);
            }
            // (d) Divisi. `assign_group` bisa berisi SATU nama (warisan) atau
            // BANYAK nama yang disambung ' | '. Pencocokannya di-anchor pada
            // delimiter supaya 'HR' tidak ikut match 'HRD'.
            if ($divisi) {
                $d = self::DELIM;
                $esc = addcslashes($divisi, '%_\\');
                $w->orWhere('assign_group', $divisi)
                    ->orWhere('assign_group', 'like', $esc.$d.'%')
                    ->orWhere('assign_group', 'like', '%'.$d.$esc)
                    ->orWhere('assign_group', 'like', '%'.$d.$esc.$d.'%');
                if ($pakaiWizardRopa) {
                    $w->orWhereJsonContains('wizard_data->detail_pemrosesan->divisi_list', $divisi)
                        ->orWhere('wizard_data->detail_pemrosesan->divisi', $divisi)
                        ->orWhere('division', $divisi);
                }
            }
        });
    }
}
