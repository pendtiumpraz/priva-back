<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tag `regulation_code` pada question_libraries (bank pertanyaan TPRM) untuk
 * gating add-on. Library tanpa kode (null) = netral → selalu tampil. Library
 * kepatuhan UU PDP (category pdp_compliance) → uu_pdp (core, selalu tampil).
 * Library regulasi lain (mis. GDPR) di masa depan dapat di-tag & di-gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_libraries', function (Blueprint $table) {
            $table->string('regulation_code', 64)->nullable()->after('category')->index();
        });

        DB::table('question_libraries')->where('category', 'pdp_compliance')->update(['regulation_code' => 'uu_pdp']);
    }

    public function down(): void
    {
        Schema::table('question_libraries', function (Blueprint $table) {
            $table->dropColumn('regulation_code');
        });
    }
};
