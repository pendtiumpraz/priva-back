<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci luar untuk pihak ketiga — dasar impor massal dan integrasi pengadaan.
 *
 * Tabel `vendors` sama sekali tidak punya kunci unik: dua kali impor berkas
 * yang sama menghasilkan dua baris untuk perusahaan yang sama. NPWP tidak bisa
 * dipakai sebagai penanda karena tersimpan terenkripsi dengan IV acak —
 * ciphertext-nya berbeda tiap penyimpanan sehingga tidak bisa dicari maupun
 * diindeks.
 *
 * `external_ref` menyimpan id pihak ketiga di sistem asal (mis. nomor rekanan
 * di sistem pengadaan tenant). Unik per organisasi, sehingga impor ulang dan
 * kiriman API menjadi "perbarui yang sudah ada", bukan "buat kembar".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendors') || Schema::hasColumn('vendors', 'external_ref')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $t) {
            $t->string('external_ref', 120)->nullable();
        });

        try {
            Schema::table('vendors', function (Blueprint $t) {
                $t->unique(['org_id', 'external_ref'], 'vendors_org_external_ref_unique');
            });
        } catch (Throwable $e) {
            // Basis data lama bisa saja sudah punya indeks serupa — abaikan.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('vendors') || ! Schema::hasColumn('vendors', 'external_ref')) {
            return;
        }

        try {
            Schema::table('vendors', function (Blueprint $t) {
                $t->dropUnique('vendors_org_external_ref_unique');
            });
        } catch (Throwable $e) {
            // indeks mungkin tidak pernah dibuat — abaikan
        }

        Schema::table('vendors', function (Blueprint $t) {
            $t->dropColumn('external_ref');
        });
    }
};
