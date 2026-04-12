<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('discovery_changelogs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable()->index();
            $table->uuid('information_system_id')->index();
            $table->date('scan_date')->comment('Tanggal log daily changelog');
            $table->integer('total_changes')->default(0);
            $table->json('logs_data')->nullable()->comment('Menyimpan array daftar tabel yang berubah, IP, dan script SQL');
            $table->string('status')->default('success');
            $table->timestamps();
            
            $table->foreign('information_system_id')->references('id')->on('information_systems')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovery_changelogs');
    }
};
