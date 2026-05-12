<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint G — Tambah field intake "Pihak Ketiga" sesuai Request Perubahan
 * Modules (2).docx:
 *   - departemen_kontak: departemen internal yang berhubungan dengan
 *     pihak ketiga
 *   - bidang: array bidang usaha (IT/Legal/HR/Finance/Procurement/...)
 *   - jenis_entitas: badan_hukum | individual — mempengaruhi dokumen
 *     legal yang wajib di-upload (Akta Notaris vs KTP)
 *
 * Field `description`, `documents`, `category` sudah ada di migrasi
 * sebelumnya — re-use, tidak duplicate.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('departemen_kontak', 255)->nullable()->after('contact_email');
            $table->json('bidang')->nullable()->after('departemen_kontak'); // ['IT', 'Legal', ...]
            $table->enum('jenis_entitas', ['badan_hukum', 'individual'])
                ->default('badan_hukum')
                ->after('bidang');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['departemen_kontak', 'bidang', 'jenis_entitas']);
        });
    }
};
