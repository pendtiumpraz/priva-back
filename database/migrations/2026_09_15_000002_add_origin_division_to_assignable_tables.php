<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Divisi asal — divisi pembuat record, TERKUNCI di `assign_group`.
 *
 * Saat orang dari divisi tertentu membuat RoPA/DPIA/pihak ketiga, divisinya
 * otomatis masuk ke `assign_group` dan TIDAK BISA dilepas. Divisi lain boleh
 * ditambahkan, atau penugasannya diperluas ke "(All Group)"; yang tidak boleh
 * adalah menghilangkan divisi asalnya.
 *
 * Kenapa kolom tersendiri dan bukan diturunkan dari `created_by`:
 *
 * Kalau "divisi pembuat" dibaca dari `$user->department` saat itu juga,
 * memindahkan seseorang ke divisi lain akan diam-diam MEMINDAHKAN KUNCI pada
 * semua record lamanya — RoPA yang dikunci ke HR tiba-tiba terkunci ke
 * Keuangan, tanpa satu pun jejak. Kuncinya adalah fakta tentang record, bukan
 * tentang orangnya hari ini, jadi ia disimpan di record.
 *
 * Pembuatnya sendiri tidak kehilangan apa pun saat pindah divisi: klausa
 * `created_by` di AssignmentScope tetap memberinya akses.
 *
 * NULL berarti tidak ada yang terkunci, dan itu keadaan yang sah untuk:
 *   - seluruh record yang sudah ada sebelum migrasi ini (TIDAK di-backfill —
 *     mengisinya dari `created_by` akan tiba-tiba menyembunyikan data yang
 *     selama ini terlihat semua orang);
 *   - record yang dibuat DPO/admin tenant/superadmin, yang memang bekerja
 *     lintas divisi dan sering membuatkan untuk divisi lain;
 *   - record yang dibuat orang tanpa divisi.
 *
 * Nilainya adalah NAMA divisi (`departments.name`), bukan id — supaya sebanding
 * langsung dengan isi `assign_group`, yang juga menyimpan nama. Lihat
 * AssignmentScope::DELIM untuk bentuk multi-divisinya.
 */
return new class extends Migration
{
    /** Tabel yang punya kolom penugasan sendiri — hanya ketiga ini di seluruh skema. */
    private const TABEL = ['ropas', 'dpias', 'vendors'];

    public function up(): void
    {
        foreach (self::TABEL as $tabel) {
            if (! Schema::hasTable($tabel) || Schema::hasColumn($tabel, 'origin_division')) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $t) {
                $t->string('origin_division')->nullable()->after('assign_group');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABEL as $tabel) {
            if (! Schema::hasTable($tabel) || ! Schema::hasColumn($tabel, 'origin_division')) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $t) {
                $t->dropColumn('origin_division');
            });
        }
    }
};
