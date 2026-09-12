<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token embed per tampilan — untuk menyematkan ringkasan RoPA/DPIA di halaman
 * lain tanpa membuka akun maupun kunci API.
 *
 * Kunci API mitra TIDAK boleh dipakai untuk ini: kunci itu hidup di browser
 * kalau dipakai dari halaman, dan cakupannya seluruh modul. Token embed
 * sebaliknya sempit dan sekali cabut mati: ia mengikat organisasi, satu modul,
 * daftar kolom yang boleh tampil, penyaring baris, dan daftar origin yang boleh
 * memasang iframe-nya.
 *
 * `fields` sengaja disimpan per token, bukan diwariskan dari modul: yang layak
 * tampil di situs publik berbeda dari yang layak tampil di portal mitra. Isi
 * kolom apa pun di luar daftar putih server ditolak saat penerbitan, sehingga
 * tidak ada jalan menaikkan cakupan lewat penyuntingan data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('embed_tokens')) {
            return;
        }

        Schema::create('embed_tokens', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id');
            $t->string('module', 20);              // ropa | dpia
            $t->string('label', 160);              // nama tampilan, mis. "Register publik situs utama"
            $t->string('token', 64)->unique();
            $t->json('fields')->nullable();        // daftar putih kolom yang tampil
            $t->json('filters')->nullable();       // penyaring baris (status, risk_level, division)
            $t->json('allowed_origins')->nullable(); // origin yang boleh memasang iframe; null = semua
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->unsignedInteger('view_count')->default(0);
            $t->uuid('created_by')->nullable();
            $t->timestamps();

            $t->index(['org_id', 'module']);
            // Pencarian token selalu disertai penyaring keaktifan.
            $t->index(['token', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('embed_tokens');
    }
};
