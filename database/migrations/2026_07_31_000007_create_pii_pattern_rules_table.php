<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pola pengenal data pribadi yang dapat ditambah tenant sendiri.
 *
 * Pola bawaan (NIK, NPWP, dan sejenisnya) tetap tinggal di ContentPiiScanner
 * karena berlaku universal di Indonesia. Yang tidak dapat diketahui di muka
 * adalah pola milik masing-masing organisasi: nomor CIF bank, nomor polis
 * asuransi, nomor rekam medis. Tanpa ini, kolom berisi pengenal semacam itu
 * tidak akan pernah terdeteksi sebagai data pribadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pii_pattern_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('key', 64);
            $table->string('label', 150);

            // Ekspresi reguler PCRE lengkap beserta pembatasnya, mis. /^\d{10}$/
            $table->string('pattern', 500);

            $table->string('pdp_category', 20)->default('umum');   // umum | spesifik
            $table->string('classification', 20)->default('pii');  // pii | sensitive | internal
            $table->boolean('encryption_required')->default(false);

            // Bobot 0-1: seberapa yakin kecocokan pola ini menandakan PII.
            // Dipakai saat beberapa pola cocok pada kolom yang sama.
            $table->decimal('weight', 3, 2)->default(1.00);

            $table->string('reason', 300)->nullable();

            // Contoh nilai yang WAJIB cocok, dipakai memvalidasi polanya saat
            // disimpan. Pola yang tidak mencocokkan contohnya sendiri hampir
            // selalu salah tulis, dan kesalahan itu baru ketahuan berbulan-bulan
            // kemudian ketika ada yang menyadari kolomnya tidak pernah terdeteksi.
            $table->string('sample_value', 200)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sequence')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'key']);
            $table->index(['org_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pii_pattern_rules');
    }
};
