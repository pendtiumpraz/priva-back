<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tinjauan & pemusnahan masa retensi RoPA (UU PDP Pasal 40–42, PP 33 Pasal 80).
 *
 * Sebelum ini `retention_due_date` hanya memicu NOTIFIKASI
 * (`notifications:scan-ropa-retention`). Tanggalnya lewat, pengingat terkirim,
 * lalu tidak terjadi apa-apa — selamanya. Kewajiban memusnahkan data yang tidak
 * lagi diperlukan tidak pernah benar-benar ditegakkan.
 *
 * Yang TIDAK dilakukan: cron yang menghapus sendiri. Yang hilang kalau salah
 * bukan cuma data, melainkan bukti bahwa kegiatan pemrosesan itu pernah
 * dicatat — dan catatan itu sendiri kewajiban Pasal 31. Karena itu pemusnahan
 * menunggu keputusan DPO, dan yang dimusnahkan adalah ISI data pribadinya,
 * bukan barisnya.
 *
 * Kenapa kolom tersendiri, bukan menumpang `retention_due_date`: kolom itu
 * DITURUNKAN ULANG dari `wizard_data` pada SETIAP penyimpanan lewat hook
 * `saving()` di model Ropa. Menyimpan keputusan di sana akan tertimpa pada
 * penyimpanan berikutnya.
 *
 * `retention_due_date_at_destruction` karena itu diisi saat DPO MENYETUJUI,
 * bukan saat pemusnahan dijalankan: penyimpanan pada langkah persetujuan pun
 * sudah memicu hitung ulang tersebut, dan setelah wizard dikosongkan hasilnya
 * null — tanggal yang menjadi dasar keputusan akan hilang justru pada saat ia
 * paling dibutuhkan sebagai bukti.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ropas') || Schema::hasColumn('ropas', 'retention_review_status')) {
            return;
        }

        Schema::table('ropas', function (Blueprint $t) {
            // pending | extended | approved_for_destruction | destroyed
            $t->string('retention_review_status', 32)->nullable()->index();
            $t->uuid('retention_reviewed_by')->nullable();
            $t->timestamp('retention_reviewed_at')->nullable();
            $t->text('retention_review_notes')->nullable();
            $t->timestamp('retention_destroyed_at')->nullable();
            $t->date('retention_due_date_at_destruction')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ropas') || ! Schema::hasColumn('ropas', 'retention_review_status')) {
            return;
        }

        Schema::table('ropas', function (Blueprint $t) {
            $t->dropColumn([
                'retention_review_status',
                'retention_reviewed_by',
                'retention_reviewed_at',
                'retention_review_notes',
                'retention_destroyed_at',
                'retention_due_date_at_destruction',
            ]);
        });
    }
};
