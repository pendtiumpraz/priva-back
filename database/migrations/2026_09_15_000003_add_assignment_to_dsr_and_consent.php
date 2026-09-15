<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penugasan divisi untuk DSR dan Consent.
 *
 * Keduanya sebelumnya tidak punya konsep divisi sama sekali — satu-satunya
 * penanda kepemilikan adalah `created_by`, dan `dsr_requests.assigned_to` yang
 * berisi SATU user dan dipakai untuk rute notifikasi, bukan keterlihatan.
 * Akibatnya seluruh permohonan subjek data dan seluruh titik pengumpulan
 * consent terbaca oleh semua orang di tenant.
 *
 * Kolomnya sengaja dibuat SAMA PERSIS dengan `ropas`/`dpias`/`vendors` supaya
 * trait AssignmentVisibility dan AssignmentScope berlaku apa adanya, tanpa
 * ragam klausa baru yang harus dipelihara terpisah.
 *
 * TIDAK ada backfill. Seluruh baris lama tetap `assign_group` NULL, yang di
 * AssignmentScope berarti "(All Group)" — tetap terlihat semua orang, persis
 * seperti sebelum migrasi ini. Mengisinya dari `created_by` akan tiba-tiba
 * menyembunyikan data yang selama ini terbuka.
 *
 * `dsr_apps.default_division` menjawab bagian "ada bidang-bidangnya": permohonan
 * yang masuk lewat portal HR otomatis jatuh ke HR. Kosong berarti permohonan
 * masuk TANPA divisi — dan itu memang yang diinginkan untuk aplikasi yang belum
 * dipetakan: permohonan tetap terlihat semua orang sampai ada yang men-triase.
 * DSR punya tenggat 3x24 jam; permohonan yang tersembunyi karena salah
 * konfigurasi adalah kegagalan yang jauh lebih mahal daripada permohonan yang
 * terlihat terlalu banyak orang.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['dsr_requests', 'consent_collection_points'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $t) use ($tabel) {
                if (! Schema::hasColumn($tabel, 'assign_group')) {
                    $t->string('assign_group')->nullable();
                }
                if (! Schema::hasColumn($tabel, 'assignees')) {
                    $t->json('assignees')->nullable();
                }
                if (! Schema::hasColumn($tabel, 'origin_division')) {
                    $t->string('origin_division')->nullable();
                }
            });
        }

        if (Schema::hasTable('dsr_apps') && ! Schema::hasColumn('dsr_apps', 'default_division')) {
            Schema::table('dsr_apps', function (Blueprint $t) {
                $t->string('default_division')->nullable()->after('default_assignee_user_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['dsr_requests', 'consent_collection_points'] as $tabel) {
            if (! Schema::hasTable($tabel)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $t) use ($tabel) {
                foreach (['assign_group', 'assignees', 'origin_division'] as $kolom) {
                    if (Schema::hasColumn($tabel, $kolom)) {
                        $t->dropColumn($kolom);
                    }
                }
            });
        }

        if (Schema::hasTable('dsr_apps') && Schema::hasColumn('dsr_apps', 'default_division')) {
            Schema::table('dsr_apps', function (Blueprint $t) {
                $t->dropColumn('default_division');
            });
        }
    }
};
