<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyambungkan mesin aturan ke jalur keluar.
 *
 * Sampai sekarang mesinnya bisa memutuskan tapi tidak ada yang bertanya
 * kepadanya. Dua hal yang ditambahkan:
 *
 * 1. `consent_collection_points.consent_rule_set_id` — titik pengumpulan mana
 *    yang dijaga oleh set aturan mana. NULL berarti tidak dijaga, dan itu
 *    HARUS berperilaku persis seperti sebelum migrasi ini ada: tenant yang
 *    belum menyusun aturan apa pun tidak boleh tiba-tiba berhenti menerima
 *    webhook.
 *
 * 2. Dua kolom konteks pada jejak keputusan. Tanpa keduanya, jejak hanya
 *    menjawab "apa keputusannya"; auditor menanyakan "keputusan atas
 *    penangkapan yang mana, lewat pintu apa".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consent_collection_points', 'consent_rule_set_id')) {
            Schema::table('consent_collection_points', function (Blueprint $t) {
                $t->uuid('consent_rule_set_id')->nullable()->index();
            });
        }

        Schema::table('consent_rule_decisions', function (Blueprint $t) {
            if (! Schema::hasColumn('consent_rule_decisions', 'collection_point_id')) {
                $t->uuid('collection_point_id')->nullable()->index();
            }
            if (! Schema::hasColumn('consent_rule_decisions', 'context')) {
                // capture | partner_api | preview_manual
                $t->string('context', 32)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('consent_collection_points', 'consent_rule_set_id')) {
            Schema::table('consent_collection_points', function (Blueprint $t) {
                $t->dropColumn('consent_rule_set_id');
            });
        }

        Schema::table('consent_rule_decisions', function (Blueprint $t) {
            foreach (['collection_point_id', 'context'] as $col) {
                if (Schema::hasColumn('consent_rule_decisions', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
