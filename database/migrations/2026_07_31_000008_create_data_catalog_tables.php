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
        // Percobaan pertama migrasi ini gagal di MySQL setelah kedua tabel
        // terlanjur dibuat: indeks dcl_unique_edge melampaui batas panjang
        // kunci InnoDB (rinciannya di bawah). MySQL tidak dapat membatalkan
        // perubahan struktur, jadi tabel setengah jadi itu tertinggal dan
        // membuat percobaan ulang berhenti dengan "table already exists".
        //
        // Dua baris di bawah membersihkan sisa itu. Keduanya HANYA berjalan
        // pada basis data yang belum pernah mencatat migrasi ini selesai —
        // begitu ia sukses, Laravel tidak akan menjalankannya lagi. Jadi
        // sasarannya adalah puing percobaan yang gagal, bukan data yang hidup:
        // kedua tabel ini belum pernah berhasil terpasang di mana pun, dan
        // isinya dapat dibangun ulang lewat sinkronisasi katalog.
        Schema::dropIfExists('data_catalog_lineage');
        Schema::dropIfExists('data_catalog_assets');

        Schema::create('data_catalog_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // Kunci stabil yang diturunkan dari asalnya, mis.
            // "system:<uuid>", "dataset:<uuid>/users", "field:<uuid>/users/nik".
            // Deterministik supaya sinkronisasi ulang memperbarui baris yang
            // sama alih-alih menggandakannya.
            // Panjang 300 (bukan 400) karena kolom ini diindeks unik bersama
            // org_id. Di MySQL utf8mb4 satu karakter dihitung 4 byte, dan batas
            // panjang kunci InnoDB adalah 3072 byte — lihat catatan di indeks
            // dcl_unique_edge tabel silsilah di bawah. Kunci terpanjang yang
            // benar-benar dihasilkan berbentuk "field:<uuid>/<tabel>/<kolom>"
            // (~172 karakter, karena pengenal MySQL sendiri maksimal 64), jadi
            // 300 masih menyisakan ruang lebih dari cukup.
            $table->string('asset_key', 300);

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
            // Sama panjang dengan asset_key: nilainya memang berupa asset_key,
            // jadi keduanya harus sanggup menampung apa pun yang boleh masuk ke
            // sana. Membedakannya akan membuat aset berkunci panjang diam-diam
            // tidak bisa punya tepi silsilah.
            $table->string('from_key', 300);
            $table->string('to_key', 300);

            // feeds | derives | copies | exports | references | processes
            $table->string('relation', 32)->default('feeds');

            $table->string('description', 500)->nullable();
            $table->string('source', 32)->default('auto'); // auto | manual | imported
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            // Anggaran panjang kunci InnoDB (utf8mb4, 4 byte per karakter):
            //   org_id  char(36)     =  144 byte
            //   from_key varchar(300) = 1200 byte
            //   to_key   varchar(300) = 1200 byte
            //   relation varchar(32)  =  128 byte
            //                          -----------
            //                           2672 byte, di bawah batas 3072.
            // Versi pertama memakai 400 karakter untuk kedua kunci dan mencapai
            // 3472 byte, sehingga migrasinya gagal di MySQL. SQLite tidak punya
            // batas ini, jadi kegagalannya tidak muncul saat pengujian lokal.
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
