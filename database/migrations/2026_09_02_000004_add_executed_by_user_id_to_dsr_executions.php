<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautkan pelaksana eksekusi DSR ke user platform.
 *
 * Sebelumnya eksekusi per-shard hanya menyimpan `executed_by_email` (string
 * bebas) — beda dengan alur saudaranya (verifikasi manual) yang mencatat
 * verified_by_user_id + email. Kolom ini menautkan pelaksana ke user; email
 * tetap disimpan sebagai salinan denormalisasi untuk sertifikat/riwayat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dsr_executions') || Schema::hasColumn('dsr_executions', 'executed_by_user_id')) {
            return;
        }

        Schema::table('dsr_executions', function (Blueprint $table) {
            $table->uuid('executed_by_user_id')->nullable()->after('executed_by_email');
            $table->index('executed_by_user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dsr_executions') || ! Schema::hasColumn('dsr_executions', 'executed_by_user_id')) {
            return;
        }

        Schema::table('dsr_executions', function (Blueprint $table) {
            $table->dropIndex(['executed_by_user_id']);
            $table->dropColumn('executed_by_user_id');
        });
    }
};
