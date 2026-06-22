<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panduan Nilai Penilaian Risiko DPIA yang bisa di-custom PER TENANT.
 *
 * Menyimpan satu blob JSON per org berisi tabel skoring (dampak indikator+level,
 * probabilitas, kontrol, penanganan). Kalau org belum punya baris, frontend
 * memakai nilai DEFAULT bawaan — jadi tabel ini hanya menyimpan override tenant.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dpia_scoring_guidance')) {
            return;
        }
        Schema::create('dpia_scoring_guidance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->unique();
            $table->json('payload');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpia_scoring_guidance');
    }
};
