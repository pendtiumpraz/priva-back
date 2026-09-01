<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kebijakan Masa Retensi sebagai DOKUMEN — PP 33/2026 Pasal 75(2).
 * Sebelumnya RetentionPolicy hanya aturan per-field (durasi + metode). Pasal
 * 75(2) mensyaratkan dokumen kebijakan memuat paling sedikit: subjek yang
 * diatur, jenis/komponen data, ketentuan arsip, deidentifikasi untuk statistik/
 * penelitian, serta metode pemusnahan elektronik dan nonelektronik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_policies', function (Blueprint $table) {
            $table->text('subjects_covered')->nullable()->after('legal_basis');
            $table->text('data_components')->nullable()->after('subjects_covered');
            $table->text('archival_provision')->nullable()->after('data_components');
            $table->text('deidentification_note')->nullable()->after('archival_provision');
            $table->text('destruction_electronic')->nullable()->after('deidentification_note');
            $table->text('destruction_nonelectronic')->nullable()->after('destruction_electronic');
        });
    }

    public function down(): void
    {
        Schema::table('retention_policies', function (Blueprint $table) {
            $table->dropColumn(['subjects_covered', 'data_components', 'archival_provision', 'deidentification_note', 'destruction_electronic', 'destruction_nonelectronic']);
        });
    }
};
