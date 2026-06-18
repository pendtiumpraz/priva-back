<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding Compliance Assessment — Per-question evidence.
 *
 * Mirror persis pola TPRM `vendor_assessment_evidence`: 1:N (satu pertanyaan
 * bisa banyak bukti). Pihak yang dinilai meng-upload via public link
 * (uploaded_by_token=true). Upload ulang TIDAK overwrite — file baru is_active=true,
 * yang lama is_active=false (jejak audit). Analisis AI dilakukan di sisi reviewer
 * dashboard (bukan di public page).
 *
 * question_id = string identifier pertanyaan yang dipakai pada answers JSON
 * (id snapshot / question_code), bukan selalu UUID — string biar fleksibel.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('holding_assessment_evidence')) {
            return;
        }
        Schema::create('holding_assessment_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();      // org HOLDING (tenant penyimpanan)
            $table->uuid('instance_id')->index();
            $table->string('question_id')->index();

            $table->string('file_path');          // relative ke disk tenant
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);

            // Public token upload → uploaded_by_user_id NULL, uploaded_by_token=true.
            $table->uuid('uploaded_by_user_id')->nullable();
            $table->boolean('uploaded_by_token')->default(false);
            $table->string('uploaded_ip', 45)->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['instance_id', 'question_id', 'is_active'], 'hold_assess_ev_inst_q_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holding_assessment_evidence');
    }
};
