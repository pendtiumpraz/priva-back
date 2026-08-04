<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanal keluar dan masuk untuk permohonan subjek data.
 *
 * Sebelumnya DSR hanya punya satu pintu masuk (formulir publik) dan satu pintu
 * keluar (unduhan manual oleh petugas). Dua-duanya mensyaratkan manusia hadir,
 * padahal tenggat 3x24 jam berjalan terus.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tujuan pengiriman otomatis, mis. Salesforce atau sistem CRM lain.
        Schema::create('dsr_outbound_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->string('url', 500);

            // generic | salesforce — menentukan bentuk payload. Salesforce
            // memakai penamaan field sendiri, dan memaksanya memakai bentuk
            // generik berarti setiap klien harus menulis lapisan penerjemah.
            $table->string('payload_format', 32)->default('generic');

            // Header otentikasi disimpan terenkripsi: isinya token pembawa
            // milik sistem klien.
            $table->text('auth_header')->nullable();

            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('timeout_seconds')->default(15);
            $table->unsignedTinyInteger('retry_count')->default(2);
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedInteger('total_deliveries')->default(0);
            $table->unsignedInteger('failed_deliveries')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'is_active']);
        });

        // Kanal penerimaan permohonan di luar formulir publik.
        Schema::create('dsr_inbound_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('app_id')->nullable();
            $table->string('name');

            // webhook | imap. Webhook dipakai penyedia surel yang meneruskan
            // pesan masuk (inbound parse); imap menarik sendiri dari kotak surat.
            $table->string('type', 16)->default('webhook');

            // Rahasia URL untuk jalur webhook. Unik global karena dipakai pada
            // endpoint tanpa otentikasi.
            $table->string('inbound_token', 64)->nullable()->unique();

            // Host, port, folder, kredensial untuk jalur imap — terenkripsi.
            $table->text('config')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedInteger('total_received')->default(0);
            $table->unsignedInteger('total_rejected')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsr_inbound_channels');
        Schema::dropIfExists('dsr_outbound_targets');
    }
};
