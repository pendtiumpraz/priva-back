<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pencabutan kewenangan wali dari dashboard — siapa dan mengapa.
 *
 * `revoke_reason` tetap kosakata sistem (peralihan_dewasa | manual) supaya
 * antrean peralihan dan laporan bisa bercabang tanpa mengurai kalimat.
 * Alasan manusianya — "wali meninggal", "hak asuh berpindah ke ayah",
 * "salah memasukkan email" — masuk `revoke_note`, dan pelakunya `revoked_by`.
 * Tanpa keduanya, pencabutan hanya terbaca "manual" saat diaudit, dan
 * pertanyaan pertama auditor ("siapa yang mencabut, atas dasar apa?") tidak
 * terjawab dari data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardian_consents', function (Blueprint $t) {
            if (! Schema::hasColumn('guardian_consents', 'revoke_note')) {
                $t->string('revoke_note', 500)->nullable()->after('revoke_reason');
            }
            if (! Schema::hasColumn('guardian_consents', 'revoked_by')) {
                $t->uuid('revoked_by')->nullable()->after('revoke_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('guardian_consents', function (Blueprint $t) {
            foreach (['revoked_by', 'revoke_note'] as $kolom) {
                if (Schema::hasColumn('guardian_consents', $kolom)) {
                    $t->dropColumn($kolom);
                }
            }
        });
    }
};
