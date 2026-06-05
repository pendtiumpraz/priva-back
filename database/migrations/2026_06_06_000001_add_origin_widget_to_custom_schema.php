<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full schema-driven wizard (RoPA) — fondasi.
 *
 * - `origin`: 'built_in' (di-seed dari default schema kanonik) vs 'custom'
 *   (ditambahkan admin). Built-in field BISA di-edit label/aktif/hide/urut,
 *   tapi reset-to-default mengembalikannya ke definisi default.
 * - `widget`: penanda renderer khusus untuk field kompleks (risk_level_auto,
 *   divisi_picker, system_picker, person_repeater, legal_basis_group, dll).
 *   Field dengan widget != null TIDAK boleh diubah tipenya (perilakunya
 *   di-hardcode di komponen React); hanya boleh hide/relabel/reorder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_custom_fields', function (Blueprint $table) {
            $table->string('origin', 16)->default('custom')->after('module');
            $table->string('widget', 48)->nullable()->after('field_type');
        });

        Schema::table('module_custom_sections', function (Blueprint $table) {
            $table->string('origin', 16)->default('custom')->after('module');
        });
    }

    public function down(): void
    {
        Schema::table('module_custom_fields', function (Blueprint $table) {
            $table->dropColumn(['origin', 'widget']);
        });

        Schema::table('module_custom_sections', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
