<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesin aturan consent lintas collection point.
 *
 * Kebutuhannya: tenant menyusun sendiri logika antar consent item di BEBERAPA
 * collection point sebelum datanya dikirim ke CRM. Contoh nyatanya: subjek yang
 * menyetujui marketing syariah tidak boleh ditawari marketing konvensional.
 *
 * Kenapa TIDAK dimodelkan sebagai "logika antar 2 collection point": begitu
 * angka 2 mengeras di skema, collection point ketiga menuntut bongkar ulang.
 * Di sini kondisi adalah DAFTAR yang tiap barisnya menyebut (collection point,
 * item, keadaan) — sehingga 2, 3, atau sepuluh tidak berbeda bagi mesinnya.
 *
 * Bentuk logikanya sengaja dibatasi: kondisi dalam SATU aturan di-AND, dan OR
 * ditulis sebagai aturan kedua. Pohon boolean bersarang adalah tempat penyusun
 * aturan berubah menjadi tidak terjelaskan — dan keputusan consent yang tidak
 * dapat dijelaskan tidak ada gunanya sebagai bukti kepatuhan.
 *
 * Konflik diselesaikan EKSPLISIT: aturan berurutan, yang cocok pertama menang
 * untuk satu segmen, dan tiap set wajib punya tindakan bawaan. Presedensi yang
 * muncul sendiri adalah cara tercepat membuat hasilnya tak bisa dipertanggung-
 * jawabkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consent_rule_sets')) {
            Schema::create('consent_rule_sets', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();
                $t->string('name', 191);
                $t->text('description')->nullable();
                // Tindakan saat TIDAK ADA aturan yang cocok. Bawaannya `block`:
                // untuk consent, diam berarti tidak boleh — bukan boleh.
                $t->string('default_action', 16)->default('block'); // block | send
                $t->boolean('is_active')->default(true)->index();
                $t->uuid('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('consent_rules')) {
            Schema::create('consent_rules', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();
                $t->uuid('rule_set_id')->index();
                // Urutan menentukan siapa yang menang — karena itu ia data,
                // bukan urutan kemunculan baris di basis data.
                $t->unsignedSmallInteger('sequence')->default(0);
                $t->string('name', 191);
                // exclude_segment | include_segment | block
                $t->string('action', 32);
                $t->string('segment', 191)->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();

                $t->index(['org_id', 'rule_set_id', 'sequence']);
            });
        }

        if (! Schema::hasTable('consent_rule_conditions')) {
            Schema::create('consent_rule_conditions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();
                $t->uuid('rule_id')->index();
                $t->uuid('collection_point_id');
                $t->uuid('consent_item_id');
                // granted | not_granted | never
                //
                // `never` (belum pernah ditanya) SENGAJA dipisah dari
                // `not_granted` (ditanya lalu menolak, atau menarik kembali).
                // Menyamakan keduanya akan menghasilkan pengiriman yang salah,
                // dan menurut UU PDP keduanya memang bukan hal yang sama.
                $t->string('state', 16);
                $t->timestamps();

                $t->index(['org_id', 'rule_id']);
            });
        }

        if (! Schema::hasTable('consent_rule_decisions')) {
            Schema::create('consent_rule_decisions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();
                $t->uuid('rule_set_id')->index();
                $t->string('subject_identifier', 191)->index();
                $t->boolean('blocked')->default(false);
                $t->json('segments')->nullable();
                // Aturan mana yang menyala, DAN keadaan consent saat itu.
                // Tanpa keduanya, keputusan lama tidak dapat dijelaskan ulang —
                // dan itulah satu-satunya alasan tabel ini ada.
                $t->json('matched')->nullable();
                $t->json('states')->nullable();
                $t->timestamp('decided_at');
                $t->timestamps();

                $t->index(['org_id', 'rule_set_id', 'decided_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_rule_decisions');
        Schema::dropIfExists('consent_rule_conditions');
        Schema::dropIfExists('consent_rules');
        Schema::dropIfExists('consent_rule_sets');
    }
};
