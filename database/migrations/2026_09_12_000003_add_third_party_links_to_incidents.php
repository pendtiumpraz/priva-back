<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Insiden ↔ pihak ketiga, dua arah.
 *
 * Sebelum ini insiden hanya bisa ditautkan ke RoPA, sehingga pertanyaan
 * "pihak ketiga mana yang mungkin terlibat pada kebocoran ini" tidak pernah
 * terjawab di dalam sistem — padahal datanya ada, hanya tidak pernah ditelusuri.
 *
 *   - `breach_incidents.linked_vendor_ids` — pihak ketiga yang DIPASTIKAN
 *     terlibat, dipilih penanggung jawab insiden. Bentuknya JSON, meniru
 *     `linked_ropa_ids` supaya perilaku multi-tautannya seragam.
 *   - `vendor_incidents.linked_breach_id` — jembatan ke register insiden TPRM,
 *     supaya satu kejadian tidak tercatat sebagai dua kasus yang tak berhubungan.
 *
 * Tanpa foreign key, sengaja: `linked_ropa_ids` pun JSON tanpa FK, dan kolom
 * penghubung di sisi TPRM harus tetap ada meski insidennya nanti dihapus lunak.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('breach_incidents') && ! Schema::hasColumn('breach_incidents', 'linked_vendor_ids')) {
            Schema::table('breach_incidents', function (Blueprint $t) {
                $t->json('linked_vendor_ids')->nullable();
            });
        }

        if (Schema::hasTable('vendor_incidents') && ! Schema::hasColumn('vendor_incidents', 'linked_breach_id')) {
            Schema::table('vendor_incidents', function (Blueprint $t) {
                $t->uuid('linked_breach_id')->nullable();
                $t->index('linked_breach_id', 'vendor_incidents_breach_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendor_incidents') && Schema::hasColumn('vendor_incidents', 'linked_breach_id')) {
            Schema::table('vendor_incidents', function (Blueprint $t) {
                $t->dropIndex('vendor_incidents_breach_idx');
                $t->dropColumn('linked_breach_id');
            });
        }

        if (Schema::hasTable('breach_incidents') && Schema::hasColumn('breach_incidents', 'linked_vendor_ids')) {
            Schema::table('breach_incidents', function (Blueprint $t) {
                $t->dropColumn('linked_vendor_ids');
            });
        }
    }
};
