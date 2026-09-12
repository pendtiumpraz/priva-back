<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar insiden PLATFORM — insiden pada aplikasi ini sendiri, bukan pada
 * tenant (UU PDP Pasal 46: pemberitahuan paling lambat 3x24 jam).
 *
 * Celah yang ditutup: seluruh modul Breach mencatat insiden YANG DIALAMI
 * tenant. Tidak ada satu pun tempat untuk mencatat insiden yang dialami
 * PLATFORM — padahal terhadap tenant kami berkedudukan sebagai Prosesor, dan
 * satu insiden di sisi kami otomatis menjadi kewajiban pemberitahuan bagi
 * SETIAP Pengendali yang datanya kami proses. Tanpa daftar ini, tenant tidak
 * pernah tahu bahwa jam 3x24 mereka sudah berjalan.
 *
 * Sengaja TIDAK tenant-scoped: tidak ada `org_id` dan tidak memakai
 * BelongsToOrg. Barisnya milik platform, dan modelnya dipatri ke koneksi
 * landlord (LandlordPinned) supaya tetap terbaca meskipun koneksi bawaan
 * sedang dialihkan ke database tenant.
 *
 * `affected_org_ids` sengaja JSON tanpa foreign key: daftar ini adalah CATATAN
 * SEJARAH tentang siapa yang terdampak saat itu. Kalau sebuah organisasi kelak
 * dihapus, buktinya tidak boleh ikut hilang — justru itu yang harus bertahan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_incidents')) {
            return;
        }

        Schema::create('platform_incidents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('incident_code')->unique();      // PLT-YYYY-NNN
            $t->string('title');
            $t->text('description')->nullable();        // dienkripsi di model
            $t->string('severity', 20)->default('medium');  // low|medium|high|critical
            $t->string('status', 24)->default('draft')->index(); // draft|notified|closed
            $t->timestamp('detected_at')->nullable();

            // all = seluruh tenant aktif; selected = hanya affected_org_ids.
            $t->string('affected_scope', 16)->default('all');
            $t->json('affected_org_ids')->nullable();

            // Hasil penyebaran per tenant — termasuk yang GAGAL. Kegagalan
            // diam-diam pada pemberitahuan insiden adalah kegagalan kepatuhan,
            // jadi hasilnya disimpan, bukan sekadar di-log.
            $t->timestamp('fanned_out_at')->nullable();
            $t->json('fanout_results')->nullable();

            $t->text('remediation')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_incidents');
    }
};
