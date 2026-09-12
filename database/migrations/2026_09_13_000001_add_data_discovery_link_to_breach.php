<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyambungkan Data Discovery ↔ Insiden ↔ Pihak Ketiga.
 *
 * Sebelum ini `breach_incidents.affected_data_types` hanya larik teks bebas yang
 * diketik manual. Padahal hasil pindai sistem SUDAH menyimpan persis jawabannya:
 * `information_systems.scan_results.tables[].columns[]` memuat `pii_detected` dan
 * `pdp_category` per kolom. Yang hilang cuma jembatannya.
 *
 * `affected_systems` menyimpan pilihan terstruktur (sistem → tabel → kolom PII),
 * sedangkan `affected_data_types` tetap dipertahankan sebagai daftar datar nama
 * kolom — kolom itu sudah dibaca ekspor, templat notifikasi, dan port backend
 * lain, jadi maknanya tidak diubah. `affected_data_categories` ditambahkan
 * terpisah karena surat ke KOMDIGI dan subjek data berbicara dalam kategori PDP
 * (umum/spesifik), bukan nama kolom teknis.
 *
 * `information_system_vendor` menutup lubang yang lebih mendasar: tidak ada satu
 * pun kaitan sistem → pihak ketiga di skema ini. `information_systems.owner_id`
 * adalah foreign key ke `users` (pemilik internal), dan `owner` sekadar teks
 * bebas. Akibatnya "SaaS mana yang memegang data ini" tidak pernah bisa dijawab
 * kecuali kebetulan sistemnya sudah ditautkan ke sebuah RoPA.
 *
 * Perannya memakai `Vendor::ROLES` yang sama dengan pivot `ropa_vendor` —
 * satu pihak ketiga bisa berperan beda pada sistem yang berbeda, persis seperti
 * ia bisa berperan beda pada kegiatan pemrosesan yang berbeda.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('breach_incidents')) {
            Schema::table('breach_incidents', function (Blueprint $t) {
                if (! Schema::hasColumn('breach_incidents', 'affected_systems')) {
                    $t->json('affected_systems')->nullable();
                }
                if (! Schema::hasColumn('breach_incidents', 'affected_data_categories')) {
                    $t->json('affected_data_categories')->nullable();
                }
            });
        }

        if (! Schema::hasTable('information_system_vendor')) {
            Schema::create('information_system_vendor', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();
                $t->uuid('information_system_id')->index();
                $t->uuid('vendor_id')->index();
                // controller | processor | joint_controller | sub_processor
                $t->string('role', 32)->nullable();
                $t->text('notes')->nullable();
                $t->timestamps();

                $t->unique(['information_system_id', 'vendor_id']);
                $t->index(['org_id', 'information_system_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('information_system_vendor');

        if (Schema::hasTable('breach_incidents')) {
            Schema::table('breach_incidents', function (Blueprint $t) {
                foreach (['affected_systems', 'affected_data_categories'] as $col) {
                    if (Schema::hasColumn('breach_incidents', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
