<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan LANGSUNG DPIA ↔ pihak ketiga.
 *
 * Sebelumnya hubungan itu hanya ada secara tidak langsung: DPIA → RoPA yang
 * dinilainya → pihak ketiga yang memproses RoPA itu. Peta koneksi bisa
 * menggambarnya sebagai rantai dua langkah, tetapi pertanyaan "DPIA ini menilai
 * risiko dari pihak ketiga mana saja" tidak punya jawaban yang bisa di-query,
 * dan lingkup sebuah DPIA tidak selalu sama dengan isi RoPA-nya — sebuah
 * penilaian bisa sengaja mempersempit atau justru memperluas.
 *
 * Bentuknya meniru `ropa_vendor` (2026_09_12_000001) supaya seluruh tautan
 * pihak ketiga konsisten: kunci gabungan tanpa kolom `id`, `org_id` ikut di
 * baris pivot, dan kosakata `role` yang sama.
 *
 * BACKFILL: tiap DPIA mewarisi pihak ketiga dari RoPA yang dinilainya, beserta
 * perannya. Pivot yang lahir kosong berarti peta tetap tidak menunjukkan apa
 * pun sampai seseorang mengisinya satu per satu — dan warisan itu memang
 * tebakan yang benar untuk keadaan awal: DPIA menilai RoPA itu, jadi pihak
 * ketiga yang memprosesnya memang ada di dalam lingkupnya. Sesudah ini tautan
 * dapat dikurasi lewat PUT /api/dpia/{id}/pihak-ketiga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dpia_vendor')) {
            return;
        }

        Schema::create('dpia_vendor', function (Blueprint $t) {
            $t->uuid('dpia_id');
            $t->uuid('vendor_id');
            $t->uuid('org_id');
            // controller | processor | joint_controller | sub_processor
            $t->string('role', 32)->default('processor');
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->primary(['dpia_id', 'vendor_id'], 'dpia_vendor_pk');
            $t->index('vendor_id', 'dpia_vendor_vendor_idx');
            $t->index(['org_id', 'role'], 'dpia_vendor_org_role_idx');
            $t->foreign('dpia_id', 'dpia_vendor_dpia_fk')
                ->references('id')->on('dpias')->cascadeOnDelete();
            $t->foreign('vendor_id', 'dpia_vendor_vendor_fk')
                ->references('id')->on('vendors')->cascadeOnDelete();
            $t->foreign('org_id', 'dpia_vendor_org_fk')
                ->references('id')->on('organizations')->cascadeOnDelete();
        });

        $this->warisiDariRopa();
    }

    /**
     * Warisi tautan dari RoPA yang dinilai tiap DPIA.
     *
     * Dibungkus try/catch dan dilewati bila `ropa_vendor` belum ada: migrasi
     * ini tidak boleh menjatuhkan seluruh `migrate` di lingkungan yang urutan
     * atau isinya berbeda. Kehilangan backfill hanya berarti pivotnya mulai
     * kosong, bukan skema yang rusak.
     */
    private function warisiDariRopa(): void
    {
        if (! Schema::hasTable('ropa_vendor') || ! Schema::hasTable('dpias')) {
            return;
        }

        try {
            DB::table('dpias')
                ->whereNotNull('ropa_id')
                ->orderBy('id')
                ->chunk(200, function ($dpias) {
                    $baris = [];
                    foreach ($dpias as $d) {
                        $tautan = DB::table('ropa_vendor')->where('ropa_id', $d->ropa_id)->get();
                        foreach ($tautan as $t) {
                            $baris[] = [
                                'dpia_id' => $d->id,
                                'vendor_id' => $t->vendor_id,
                                'org_id' => $d->org_id ?? $t->org_id,
                                'role' => $t->role ?? 'processor',
                                'notes' => null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                    if ($baris !== []) {
                        // insertOrIgnore: satu DPIA bisa terhubung ke RoPA yang
                        // sama lewat lebih dari satu jalur, dan kunci gabungannya
                        // yang menolak duplikat — bukan tugas migrasi ini gagal.
                        DB::table('dpia_vendor')->insertOrIgnore($baris);
                    }
                });
        } catch (Throwable $e) {
            // Sengaja diam: lihat catatan di docblock.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dpia_vendor');
    }
};
