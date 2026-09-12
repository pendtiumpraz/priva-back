<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda anonimisasi pengguna (UU PDP Pasal 43 & 44).
 *
 * Menghapus pengguna selama ini hanya soft delete: barisnya tetap ada dengan
 * nama, surel, dan telepon utuh — selamanya. Untuk aplikasi yang dijual sebagai
 * alat kepatuhan PDP, itu tidak bisa dipertahankan.
 *
 * Yang TIDAK dilakukan: menghapus barisnya. `users.id` dirujuk jejak audit
 * ("siapa menyetujui RoPA ini"), assignees RoPA/DPIA, dan penerbit tautan.
 * Menghapus baris akan memutus bukti kepatuhan — menaati satu pasal dengan
 * melanggar yang lain. Yang dihapus adalah DATA PRIBADINYA, identitas barisnya
 * tetap.
 *
 * `anonymized_at` sekaligus menjadi kunci idempotensi: baris yang sudah
 * dianonimkan tidak diproses ulang, dan pemulihan akun ditolak karena tidak ada
 * lagi yang bisa dipulihkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || Schema::hasColumn('users', 'anonymized_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $t) {
            $t->timestamp('anonymized_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'anonymized_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('anonymized_at');
        });
    }
};
