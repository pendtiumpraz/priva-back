<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPDP (Pejabat/Petugas yang Melaksanakan Fungsi Pelindungan Data Pribadi).
 *
 * Menutup gap PP 33/2026 Pasal 142-143: sebelumnya platform hanya punya role
 * RBAC bernama `dpo` (sekadar hak login), bukan CATATAN PENUNJUKAN PPDP yang
 * sah secara hukum. Tabel ini menyimpan penunjukan formal beserta:
 *   - 3 pemicu kewajiban Pasal 142(1) (pelayanan publik / pemantauan
 *     sistematis skala besar / skala besar data spesifik-pidana) — dipakai
 *     mengevaluasi apakah organisasi WAJIB menunjuk PPDP;
 *   - kualifikasi/kompetensi Pasal 143 (profesionalitas, pengetahuan hukum,
 *     praktik Pelindungan Data Pribadi).
 *
 * Kontak PPDP di sini adalah sumber untuk RoPA Pasal 74(2) huruf b.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppdp_appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // Data penunjuk (PPDP).
            $table->string('appointee_name', 200);
            $table->string('appointee_email', 200)->nullable();
            $table->string('appointee_phone', 60)->nullable();
            $table->string('appointee_position', 200)->nullable(); // jabatan
            $table->boolean('is_internal')->default(true);         // internal vs pihak ketiga

            // Dokumen & masa penunjukan.
            $table->string('sk_number', 120)->nullable();          // nomor SK penunjukan
            $table->date('sk_date')->nullable();
            $table->text('appointment_basis')->nullable();         // dasar penunjukan
            $table->text('scope')->nullable();                     // ruang lingkup tugas
            $table->string('reporting_line', 200)->nullable();     // jalur pelaporan
            $table->date('term_start')->nullable();
            $table->date('term_end')->nullable();
            $table->string('status', 20)->default('active');       // active | inactive | revoked

            // Pemicu kewajiban Pasal 142(1).
            $table->boolean('trigger_public_service')->default(false);
            $table->boolean('trigger_large_scale_monitoring')->default(false);
            $table->boolean('trigger_large_scale_specific_criminal')->default(false);

            // Kompetensi Pasal 143.
            $table->boolean('competency_professional')->default(false);
            $table->boolean('competency_legal_knowledge')->default(false);
            $table->boolean('competency_pdp_practice')->default(false);
            $table->text('certifications')->nullable();
            $table->text('competency_notes')->nullable();

            // Apakah kontak PPDP dipublikasikan (mengalir ke pemberitahuan/RoPA).
            $table->boolean('contact_published')->default(false);

            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppdp_appointments');
    }
};
