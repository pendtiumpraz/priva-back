<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil scanner Peta Koneksi seluruh modul (DSPM).
 *
 * Satu baris = satu kali scan. Graf hasil scan (JSON) ditulis ke STORAGE
 * tenant bila organisasi memasang storage eksternal (S3/MinIO/GCS/…) — baris
 * ini lalu hanya memegang lokasi + sidik jarinya. Tanpa storage eksternal,
 * graf disimpan langsung di kolom `payload` (backend).
 *
 * Ringkasan (jumlah simpul/tepi, rekap per modul) selalu ada di baris supaya
 * riwayat scan dapat ditampilkan tanpa membuka grafnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connection_map_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // 'storage' = file JSON di storage tenant | 'database' = kolom payload.
            $table->string('storage_location', 16);
            $table->string('storage_driver', 32)->nullable();
            $table->string('storage_path')->nullable();
            // Diisi bila storage terpasang tetapi gagal ditulis → jatuh ke database.
            $table->string('storage_note')->nullable();

            $table->json('payload')->nullable();
            $table->string('payload_sha256', 64)->nullable();
            $table->unsignedInteger('payload_bytes')->default(0);

            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('edge_count')->default(0);
            $table->json('summary')->nullable();

            $table->uuid('scanned_by')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['org_id', 'scanned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_map_scans');
    }
};
