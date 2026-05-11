<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Foundation untuk 20 template PDF elegan (ROPA/DPIA/GAP).
 *
 * Menambahkan empat kolom pada tabel `document_templates`:
 *  - blade_view      : nama Blade view spesifik per template
 *                      (mis. "reports.templates.midnight-indigo").
 *                      Bila terisi, controller me-render Blade ini alih-alih
 *                      Blade generic `reports.templates.preview`.
 *  - engine          : engine render PDF. Saat ini hanya `dompdf` yang
 *                      digunakan; nilai `browsershot` disiapkan untuk
 *                      fase berikutnya yang membutuhkan rendering modern
 *                      (CSS grid, web font, dsb).
 *  - status          : `available` atau `coming_soon`. Frontend memakai
 *                      kolom ini untuk menonaktifkan tombol aktivasi pada
 *                      template yang belum diimplementasikan.
 *  - style_category  : kategori visual untuk pengelompokan UI
 *                      (mis. `minimalist`, `editorial`, `tech`, `premium`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('blade_view', 191)->nullable()->after('preview_image');
            $table->string('engine', 32)->default('dompdf')->after('blade_view');
            $table->string('status', 32)->default('available')->after('engine');
            $table->string('style_category', 64)->nullable()->after('status');

            $table->index('blade_view');
            $table->index('status');
            $table->index('style_category');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            // Hapus index terlebih dahulu (urutan penting di MySQL).
            $table->dropIndex(['blade_view']);
            $table->dropIndex(['status']);
            $table->dropIndex(['style_category']);

            $table->dropColumn(['blade_view', 'engine', 'status', 'style_category']);
        });
    }
};
