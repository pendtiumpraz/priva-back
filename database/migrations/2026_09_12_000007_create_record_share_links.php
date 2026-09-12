<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan berbagi satu record ke lembaga/regulator — 1 RoPA (atau DPIA) = 1 URL.
 *
 * Berbeda dari `embed_tokens` yang menyajikan DAFTAR terkurasi untuk dipasang
 * di situs: ini menyajikan SATU dokumen utuh kepada penerima tertentu, dijaga
 * kata sandi, dan mati sendiri setelah dikunjungi sekian kali.
 *
 * `max_views` ada karena dokumennya rahasia: tautan yang beredar selamanya
 * cepat atau lambat tersalin ke tempat yang tidak diniatkan. `view_count`
 * hanya bertambah saat kata sandi BENAR — kalau dihitung saat halaman dibuka,
 * perayap tautan (pratinjau di aplikasi pesan) bisa menghabiskan jatahnya
 * sebelum petugas lembaga sempat membuka.
 *
 * Kata sandi disimpan sebagai hash, bukan terenkripsi: tidak ada satu pun alur
 * yang perlu membacanya kembali. Hilang berarti terbitkan ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_share_links')) {
            return;
        }

        Schema::create('record_share_links', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id');
            $t->string('module', 20);               // ropa | dpia
            $t->uuid('record_id');
            $t->string('token', 64)->unique();
            $t->string('password_hash');
            $t->string('recipient_label', 160)->nullable();  // mis. "Kementerian Komdigi"
            $t->unsignedSmallInteger('max_views')->default(5);
            $t->unsignedSmallInteger('view_count')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoked_reason', 40)->nullable();    // max_views | manual | expired
            $t->timestamp('last_viewed_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamps();

            $t->index(['org_id', 'module', 'record_id']);
            $t->index(['token', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_share_links');
    }
};
