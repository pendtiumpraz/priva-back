<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy Notice — naskah pemberitahuan privasi yang dikelola terpusat,
 * bertahap versi, melewati persetujuan, dan dapat dijadwalkan terbit.
 *
 * Dipecah menjadi tiga tabel karena ketiganya berubah pada irama yang berbeda:
 *
 *   privacy_notices          → identitas dokumen. Jarang berubah. Inilah yang
 *                              disematkan situs klien, sehingga embed_token-nya
 *                              harus tetap sama meski naskahnya berganti versi.
 *   privacy_notice_versions  → satu siklus persetujuan. Status, penjadwalan,
 *                              dan jejak siapa mengajukan/menyetujui melekat di
 *                              sini, bukan di naskahnya.
 *   privacy_notice_contents  → naskah per bahasa untuk satu versi.
 *
 * Alasan naskah per bahasa TIDAK diletakkan di tabel versi: persetujuan berlaku
 * atas satu versi secara utuh. Kalau tiap bahasa menjadi versinya sendiri, versi
 * Indonesia bisa terbit sementara versi Inggris masih menunggu persetujuan —
 * dan situs klien akan menyajikan dua naskah yang tidak setara secara hukum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_notices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // Unik global, bukan per-org — mengikuti pola kode modul lain.
            // Karena itu setiap penyisipan WAJIB lewat jalur retry, lihat
            // PrivacyNotice::createWithCodeRetry().
            $table->string('code')->unique();

            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('description')->nullable();

            // Token penyematan dibuat sekali dan tidak ikut berganti saat versi
            // naskah berubah, supaya pemasangan di situs klien tidak perlu
            // disentuh setiap kali naskah diperbarui.
            $table->string('embed_token', 64)->unique();

            $table->uuid('published_version_id')->nullable();
            $table->string('default_locale', 10)->default('id');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'is_active']);
        });

        Schema::create('privacy_notice_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('privacy_notice_id');
            $table->unsignedInteger('version_number');

            // draft → pending_approval → approved → (scheduled) → published
            //                         ↘ rejected → draft
            // published → superseded ketika versi berikutnya terbit.
            $table->string('status', 32)->default('draft');
            $table->text('change_note')->nullable();

            $table->uuid('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();

            // Terbit terjadwal. Null berarti terbit begitu disetujui dan
            // ditekan terbitkan; terisi berarti menunggu penjadwal.
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('privacy_notice_id')->references('id')->on('privacy_notices')->onDelete('cascade');
            $table->unique(['privacy_notice_id', 'version_number']);
            $table->index(['org_id', 'status']);
            // Dipakai penjadwal untuk memungut versi yang sudah waktunya terbit.
            $table->index(['status', 'publish_at']);
        });

        Schema::create('privacy_notice_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('version_id');
            $table->string('locale', 10);
            $table->string('title');
            $table->longText('body');
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('version_id')->references('id')->on('privacy_notice_versions')->onDelete('cascade');
            $table->unique(['version_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_notice_contents');
        Schema::dropIfExists('privacy_notice_versions');
        Schema::dropIfExists('privacy_notices');
    }
};
