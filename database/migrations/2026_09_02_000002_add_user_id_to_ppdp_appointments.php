<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautkan penunjukan PPDP ke user platform yang sudah ada.
 *
 * Sebelumnya form PPDP menangkap ulang nama/email/telepon secara manual —
 * duplikasi dengan role `dpo` di User Management dan field `dpo_list` di RoPA.
 * Untuk PPDP internal, cukup pilih user (dropdown /dpo-users); nama/email/
 * telepon/jabatan disalin otomatis dari user tsb oleh controller.
 * PPDP pihak ketiga (is_internal=false) tetap boleh isi manual (user_id null).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ppdp_appointments') || Schema::hasColumn('ppdp_appointments', 'user_id')) {
            return;
        }

        Schema::table('ppdp_appointments', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('org_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ppdp_appointments') || ! Schema::hasColumn('ppdp_appointments', 'user_id')) {
            return;
        }

        Schema::table('ppdp_appointments', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
