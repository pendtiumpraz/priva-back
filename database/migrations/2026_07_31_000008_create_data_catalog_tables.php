<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog metadata terpusat beserta silsilah (lineage) antar aset data.
 *
 * Sebelumnya metadata tersebar: sebagian di scan_results tiap sistem
 * informasi, sebagian di wizard RoPA, sebagian lagi hanya di kepala orang.
 * Pertanyaan yang paling sering diajukan pemeriksa — "data nasabah ada di mana
 * saja, dan mengalir ke mana" — karena itu tidak punya satu tempat untuk
 * dijawab.
 *
 * Aset SENGAJA dimaterialisasi sebagai baris, bukan dihitung ulang setiap kali
 * seperti peta alur RoPA. Alasannya berbeda: katalog harus dapat menampung
 * aset dari sistem LUAR (Collibra, Alation, Purview) yang tidak dapat
 * diturunkan dari data kami sendiri, dan harus dapat dicari lintas ratusan
 * ribu kolom tanpa memindai ulang seluruh sumber.
 *
 * Konsekuensinya katalog bisa basi, dan itu ditangani terang-terangan lewat
 * kolom last_synced_at serta perintah sinkronisasi — bukan disembunyikan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_catalog_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // Kunci stabil yang diturunkan dari asalnya, mis.
            // "system:<uuid>", "dataset:<uuid>/users", "field:<uuid>/users/nik".
            // Deterministik supaya sinkronisasi ulang memperbarui baris yang
            // sama alih-alih menggandakannya.
            $table->string('asset_key', 400);

            // system | dataset | field | file | report
            $table->string('asset_type', 32);

            $table->string('name', 400);

            // Nama bertingkat lengkap untuk ditampilkan dan dicari.
            $table->string('qualified_name', 600)->nullable();
            $table->text('description')->nullable();

            // internal | manual | collibra | alation | purview | custom
            $table->string('source', 32)->default('internal');
            $table->string('source_ref', 300)->nullable();

            $table->uuid('information_system_id')->nullable();
            $table->uuid('owner_user_id')->nullable();
            $table->string('steward', 200)->nullable();

            $table->string('classification', 32)->nullable();  // pii | sensitive | internal
            $table->string('pdp_category', 20)->nullable();     // umum | spesifik
            $table->boolean('encryption_required')->default(false);

            $table->json('tags')->nullable();
            $table->json('metadata')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'asset_key']);
            $table->index(['org_id', 'asset_type']);
            $table->index(['org_id', 'classification']);
            $table->index(['org_id', 'information_system_id']);
        });

        Schema::create('data_catalog_lineage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // Disimpan sebagai KUNCI, bukan foreign key ke id aset: silsilah
            // dari katalog luar kerap menyebut aset yang belum (atau tidak
            // pernah) diimpor, dan memaksakan relasi akan membuang tepi yang
            // justru paling menarik — yang menunjuk ke luar batas sistem kami.
            $table->string('from_key', 400);
            $table->string('to_key', 400);

            // feeds | derives | copies | exports | references | processes
            $table->string('relation', 32)->default('feeds');

            $table->string('description', 500)->nullable();
            $table->string('source', 32)->default('auto'); // auto | manual | imported
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'from_key', 'to_key', 'relation'], 'dcl_unique_edge');
            $table->index(['org_id', 'from_key']);
            $table->index(['org_id', 'to_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_catalog_lineage');
        Schema::dropIfExists('data_catalog_assets');
    }
};
