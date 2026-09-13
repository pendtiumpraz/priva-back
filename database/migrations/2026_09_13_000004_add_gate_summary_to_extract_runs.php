<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan penjagaan pada tiap jalan ekstrak.
 *
 * Subjek yang LOLOS sengaja tidak dicatat satu per satu — satu jalan bisa
 * meliputi puluhan ribu orang. Kolom ini yang menjadi buktinya: penapis apa
 * yang dipakai, segmen apa yang dituju, set aturan mana yang berlaku, berapa
 * yang ditimbang, dan berapa yang ditahan. Tanpa itu, "ekstrak ini sudah
 * melewati aturan" hanya klaim tanpa catatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('extract_runs', 'gate_summary')) {
            Schema::table('extract_runs', function (Blueprint $t) {
                $t->json('gate_summary')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('extract_runs', 'gate_summary')) {
            Schema::table('extract_runs', function (Blueprint $t) {
                $t->dropColumn('gate_summary');
            });
        }
    }
};
