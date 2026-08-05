<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tandai aset katalog yang klasifikasinya ditetapkan manusia.
 *
 * Katalog diisi dari dua arah: sinkronisasi hasil pemindaian sendiri, dan
 * tarikan dari katalog luar seperti Purview. Keduanya menulis ulang barisnya
 * setiap kali dijalankan.
 *
 * Tanpa penanda ini, keputusan klasifikasi yang ditetapkan manusia akan hilang
 * pada sinkronisasi berikutnya — dan hilangnya tidak terlihat. Tidak ada pesan
 * galat, tidak ada baris yang berkurang; hanya sebuah kolom yang tadinya
 * "spesifik" kembali menjadi "umum" karena Purview memang tidak tahu apa-apa
 * soal keputusan itu.
 *
 * Itu membatalkan seluruh gagasan satu pintu: organisasi menetapkan
 * klasifikasi di sini, lalu sinkronisasi rutin diam-diam mengembalikannya ke
 * versi sistem luar.
 *
 * Data Discovery sudah menerapkan pola yang sama di tingkat kolom lewat
 * `manually_classified` pada scan_results; migrasi ini menyamakan katalog
 * dengannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('data_catalog_assets') || Schema::hasColumn('data_catalog_assets', 'manually_classified')) {
            return;
        }

        Schema::table('data_catalog_assets', function (Blueprint $table) {
            $table->boolean('manually_classified')->default(false)->after('encryption_required');
            $table->uuid('classified_by')->nullable()->after('manually_classified');
            $table->timestamp('classified_at')->nullable()->after('classified_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('data_catalog_assets')) {
            return;
        }

        Schema::table('data_catalog_assets', function (Blueprint $table) {
            foreach (['manually_classified', 'classified_by', 'classified_at'] as $column) {
                if (Schema::hasColumn('data_catalog_assets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
