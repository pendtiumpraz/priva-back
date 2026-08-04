<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard yang disusun sendiri oleh tenant.
 *
 * Susunan widget disimpan sebagai JSON, bukan tabel widget tersendiri. Widget
 * tidak pernah dikueri satu per satu di luar konteks dashboardnya, dan
 * memecahnya menjadi tabel hanya menambah join tanpa menjawab pertanyaan baru.
 *
 * `visible_roles` menyimpan tenant role yang boleh melihat dashboard. Ini
 * lapisan KEDUA, bukan satu-satunya: penyaringan sesungguhnya terjadi saat
 * render, ketika tiap widget dicek terhadap izin modul pengguna. Tanpa lapisan
 * kedua itu, dashboard yang dibagikan bisa membocorkan angka dari modul yang
 * tidak boleh diakses penerimanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_dashboards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->text('description')->nullable();

            // ropa | dpia | all — menentukan tempat dashboard muncul.
            $table->string('module', 32)->default('all');

            $table->json('widgets')->nullable();

            // Null berarti dashboard milik organisasi (dibagikan); terisi
            // berarti milik satu pengguna.
            $table->uuid('owner_user_id')->nullable();

            $table->json('visible_roles')->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('sequence')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'module']);
            $table->index(['org_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_dashboards');
    }
};
