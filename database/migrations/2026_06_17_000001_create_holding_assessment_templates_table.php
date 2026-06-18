<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding Compliance Assessment — Templates.
 *
 * A HOLDING org authors reusable assessment templates (mirip GAP Assessment):
 * pertanyaan custom yang dikelompokkan per kategori, terikat ke satu regulasi.
 * Template ini lalu di-dispatch ke sub-holding / anak perusahaan jadi instance
 * yang diisi via public link (pola TPRM).
 *
 * Owner = org HOLDING (org_id). Multi-DB safe: string bukan ENUM, FK cascade.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('holding_assessment_templates')) {
            return;
        }
        Schema::create('holding_assessment_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id'); // org HOLDING pemilik template

            $table->string('name');
            $table->text('description')->nullable();

            // Regulasi yang dinilai (mis. uupdp, iso27001) — free-form / ref ke
            // regulation_frameworks.code. Disimpan + label snapshot.
            $table->string('regulation_code', 40)->nullable();
            $table->string('regulation_name')->nullable();

            // draft | published | archived
            $table->string('status', 20)->default('draft');

            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'status'], 'hold_assess_tpl_org_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_assessment_templates');
    }
};
