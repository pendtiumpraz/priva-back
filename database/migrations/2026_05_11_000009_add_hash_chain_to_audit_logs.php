<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hash-chain untuk audit_logs — tamper-evident audit trail.
 *
 *   content_hash  = sha256(serialize_row_canonical)
 *   prev_hash     = content_hash dari row sebelumnya (FIFO by id/created_at)
 *
 * Verifikasi periodic: jalankan ulang hash dari awal, compare dengan
 * stored value. Kalau ada admin DB yang tamper isi row, hash gak match
 * → terdeteksi.
 *
 * Untuk backward compat, kolom nullable. Hash chain hanya aktif saat
 * setting security.audit_log_hash_chain_enabled = true.
 *
 * Performance: SHA-256 per insert + lookup prev row. Untuk volume audit
 * log normal (< 1M rows), overhead minimal. Untuk tenant volume tinggi,
 * pakai index pada created_at + id supaya lookup prev cepat.
 *
 * Default OFF supaya admin enable saat siap (running re-hash one-time
 * untuk seed value awal pada deploy pertama dengan feature ON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('changes');
            $table->string('prev_hash', 64)->nullable()->after('content_hash');
            $table->index(['content_hash'], 'audit_logs_content_hash_idx');
        });

        // Seed default setting (default OFF — opt-in)
        $exists = DB::table('system_settings')->where('key', 'security.audit_log_hash_chain_enabled')->exists();
        if (! $exists) {
            DB::table('system_settings')->insert([
                'key' => 'security.audit_log_hash_chain_enabled',
                'value' => json_encode(false),
                'is_encrypted' => false,
                'section' => 'security',
                'updated_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_content_hash_idx');
            $table->dropColumn(['content_hash', 'prev_hash']);
        });
        DB::table('system_settings')->where('key', 'security.audit_log_hash_chain_enabled')->delete();
    }
};
