<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tag `regulation_code` pada knowledge_base_sections untuk gating add-on.
 *
 * Section tanpa regulation_code (null) = konten umum → selalu tampil.
 * Section ber-kode = konten spesifik regulasi → tampil hanya bila regulasi itu
 * core atau di-enable tenant. Backfill: entry pasal UU PDP (module_key
 * uupdp_*) → uu_pdp; entry pasal PP 33 (pp33_*) → pp_33 (keduanya core, jadi
 * tetap selalu tampil — tag ini menyiapkan filter untuk add-on lain nanti).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base_sections', function (Blueprint $table) {
            $table->string('regulation_code', 64)->nullable()->after('category')->index();
        });

        DB::table('knowledge_base_sections')->where('module_key', 'like', 'uupdp_%')->update(['regulation_code' => 'uu_pdp']);
        DB::table('knowledge_base_sections')->where('module_key', 'like', 'pp33_%')->update(['regulation_code' => 'pp_33']);
    }

    public function down(): void
    {
        Schema::table('knowledge_base_sections', function (Blueprint $table) {
            $table->dropColumn('regulation_code');
        });
    }
};
