<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penemuan data store yang belum terdaftar.
 *
 * Dua mode, dan pemisahannya bukan soal fitur melainkan soal risiko:
 *
 *   passive — membaca jejak yang SUDAH ada (ekspor CMDB, berkas konfigurasi
 *             aplikasi, log koneksi) lalu mencocokkannya dengan sistem
 *             terdaftar. Tidak menyentuh jaringan sama sekali.
 *   active  — menyambung ke rentang IP yang ditentukan klien pada porta basis
 *             data yang lazim. Ini pemindaian jaringan sungguhan, dan di
 *             hampir semua bank hal itu dibatasi kebijakan keamanan internal
 *             serta akan memicu alarm SOC mereka sendiri.
 *
 * Karena itu mode aktif dijaga BERLAPIS: saklar platform di tangan superadmin,
 * lalu persetujuan tenant, lalu rentang IP yang harus disebut satu per satu.
 * Tidak ada satu pun lapis yang punya nilai bawaan yang membolehkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_probe_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            // passive | active
            $table->string('mode', 16)->default('passive');

            // Wajib diisi eksplisit untuk mode aktif; tidak pernah ada bawaan.
            $table->json('cidr_ranges')->nullable();
            $table->json('ports')->nullable();

            $table->boolean('is_enabled')->default(true);

            // Jejak persetujuan tenant atas pemindaian aktif — dicatat karena
            // pemindaian jaringan adalah tindakan yang harus dapat
            // dipertanggungjawabkan, bukan sekadar setelan.
            $table->uuid('active_scan_approved_by')->nullable();
            $table->timestamp('active_scan_approved_at')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique('org_id');
        });

        Schema::create('discovery_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');

            $table->string('host', 255);
            $table->unsignedInteger('port')->nullable();
            $table->string('service_hint', 64)->nullable();
            $table->string('name', 300)->nullable();

            // cmdb | config_file | connection_log | network_scan | manual
            $table->string('source', 32);
            $table->text('evidence')->nullable();

            // new | registered | ignored
            $table->string('status', 16)->default('new');
            $table->uuid('matched_system_id')->nullable();
            $table->text('note')->nullable();

            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->unique(['org_id', 'host', 'port'], 'dc_unique_endpoint');
            $table->index(['org_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_candidates');
        Schema::dropIfExists('discovery_probe_configs');
    }
};
