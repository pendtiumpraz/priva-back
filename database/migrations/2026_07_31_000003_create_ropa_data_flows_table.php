<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lapisan MANUAL peta alur data RoPA.
 *
 * Graf otomatisnya sengaja TIDAK disimpan — ia diturunkan ulang dari RoPA
 * setiap kali diminta. Menyimpannya berarti menciptakan salinan kedua yang
 * dapat basi diam-diam: RoPA berubah, peta tetap menampilkan keadaan lama, dan
 * tidak ada yang menyadarinya sampai ada yang membandingkan keduanya.
 *
 * Yang disimpan di sini hanyalah apa yang TIDAK dapat diturunkan: simpul dan
 * panah yang ditambahkan sendiri oleh pengguna, penyuntingan atas simpul
 * otomatis, elemen yang disembunyikan, dan tata letak kanvas.
 *
 * Pola gabungnya mengikuti ColumnAutoAssigner::mergePreserveUserEdits() pada
 * Data Discovery — keputusan manusia tidak boleh hilang saat sumbernya
 * dipindai atau diturunkan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ropa_data_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('ropa_id');

            // Simpul tambahan buatan pengguna, mis. pihak ketiga yang tidak
            // tercatat di wizard karena baru muncul saat pemetaan berlangsung.
            $table->json('manual_nodes')->nullable();

            // Panah tambahan buatan pengguna.
            $table->json('manual_edges')->nullable();

            // Penyuntingan atas elemen OTOMATIS, dikunci per node_key/edge_key.
            // Disimpan terpisah dari manual_nodes agar penurunan ulang tetap
            // memperbarui bagian yang tidak disunting pengguna.
            $table->json('overrides')->nullable();

            // Elemen otomatis yang disembunyikan. Tidak dihapus, karena
            // penurunan berikutnya akan memunculkannya kembali.
            $table->json('hidden_keys')->nullable();

            // Koordinat kanvas per elemen.
            $table->json('positions')->nullable();

            $table->text('notes')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('ropa_id')->references('id')->on('ropas')->onDelete('cascade');
            $table->unique('ropa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ropa_data_flows');
    }
};
