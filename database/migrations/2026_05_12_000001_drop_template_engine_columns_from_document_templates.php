<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop kolom-kolom Blade PDF template (blade_view, engine, status,
 * style_category) dari tabel `document_templates`.
 *
 * Latar belakang: rendering PDF/DOCX kompleks dipindah ke frontend
 * (Next.js + @react-pdf/renderer + docx) sehingga server tidak lagi
 * memerlukan referensi ke Blade view per-template ataupun engine
 * Browsershot/dompdf di level template. Kapabilitas DOCX template
 * upload pengguna (kolom `docx_templates`) tetap dipertahankan.
 *
 * Migration ini melengkapi (tidak menghapus) migration sebelumnya
 * `2026_05_11_120000_add_template_engine_to_document_templates.php`
 * agar lingkungan yang sudah pernah memigrasi tetap konsisten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // Hapus index terlebih dahulu (urutan penting di MySQL). Bungkus
            // dengan try/catch karena pada beberapa lingkungan (mis. SQLite
            // memory di test) index mungkin tidak terdaftar.
            foreach (['blade_view', 'status', 'style_category'] as $idx) {
                try {
                    $table->dropIndex([$idx]);
                } catch (\Throwable $e) {
                    // Index tidak ada — abaikan.
                }
            }
        });

        Schema::table('document_templates', function (Blueprint $table) {
            $cols = ['blade_view', 'engine', 'status', 'style_category'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('document_templates', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('document_templates', 'blade_view')) {
                $table->string('blade_view', 191)->nullable()->after('preview_image');
            }
            if (! Schema::hasColumn('document_templates', 'engine')) {
                $table->string('engine', 32)->default('dompdf')->after('blade_view');
            }
            if (! Schema::hasColumn('document_templates', 'status')) {
                $table->string('status', 32)->default('available')->after('engine');
            }
            if (! Schema::hasColumn('document_templates', 'style_category')) {
                $table->string('style_category', 64)->nullable()->after('status');
            }

            $table->index('blade_view');
            $table->index('status');
            $table->index('style_category');
        });
    }
};
