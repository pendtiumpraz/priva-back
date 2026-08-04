<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pustaka kontrol/pengamanan yang dapat dipakai ulang lintas DPIA.
 *
 * Sisi RISIKO sudah punya pustakanya sendiri lewat dpia/framework
 * (DpiaCategory + DpiaCategoryRisk), tetapi sisi KONTROL belum pernah ada:
 * langkah mitigasi selama ini diketik bebas pada tiap dokumen. Akibatnya dua
 * DPIA yang menghadapi risiko sama dapat menuliskan kontrol yang berbeda, dan
 * tidak ada cara menjawab pertanyaan paling wajar dalam audit — kontrol apa
 * yang organisasi ini andalkan, dan di mana saja ia dipakai.
 *
 * Baris dimiliki org, mengikuti pola dpia/framework: default disemai per
 * tenant lewat ControlLibraryService::ensureSeeded(), bukan disimpan sebagai
 * baris sistem bersama. Konsekuensinya tenant bebas menyunting dan menghapus
 * apa pun tanpa menyentuh tenant lain, dan `reset` mengembalikannya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_library_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // Kode pendek untuk dirujuk di dokumen dan laporan, mis. TEK-01.
            $table->string('code', 32)->nullable();

            $table->string('category', 80);          // teknis | organisasi | hukum | fisik
            $table->string('title', 300);
            $table->text('description')->nullable();

            // preventif | detektif | korektif — menentukan kapan kontrol bekerja
            // relatif terhadap insiden, dan dipakai untuk menilai apakah bauran
            // kontrol timpang (mis. semuanya preventif, tanpa deteksi).
            $table->string('control_type', 20)->default('preventif');

            $table->text('implementation_guidance')->nullable();

            // Rujukan dasar hukum atau standar, mis. "UU PDP Pasal 35".
            $table->string('reference', 200)->nullable();

            // Perkiraan efektivitas 1-3, dipakai sebagai usulan awal ketika
            // kontrol diterapkan ke DPIA. DPO tetap dapat menimpanya.
            $table->tinyInteger('default_effectiveness')->nullable();

            $table->integer('sequence')->default(0);
            $table->boolean('is_active')->default(true);

            // Penanda asal semai. Bukan penghalang penyuntingan — hanya
            // penjelas di antarmuka dan penanda bagi `reset`.
            $table->boolean('is_seeded')->default(false);

            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'category', 'sequence']);
            $table->index(['org_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_library_items');
    }
};
