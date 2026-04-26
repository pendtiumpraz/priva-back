<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSR Executions — per-shard per-request_type execution log.
 *
 * Setelah admin klien execute SQL pack di DB mereka, mereka upload bukti
 * execution per shard ke sini. Privasimu cek: kalau semua execution
 * status = executed/skipped → DSR ter-complete + generate certificate.
 *
 * Privasimu sendiri TIDAK PERNAH execute SQL — hanya track evidence.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dsr_executions')) return;

        try {
            Schema::create('dsr_executions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('dsr_request_id');
                $table->uuid('information_system_id');
                $table->string('shard_name', 100)->nullable(); // null untuk non-sharded
                $table->string('request_type', 32);            // access | deletion | etc
                $table->text('sql_executed')->nullable();      // snapshot SQL yang dijalankan
                $table->integer('rows_affected')->nullable();
                $table->string('status', 32)->default('pending');
                // pending | executed | failed | skipped
                $table->timestamp('executed_at')->nullable();
                $table->string('executed_by_email', 200)->nullable(); // klien admin email
                $table->uuid('evidence_file_id')->nullable();  // FK ke documents (optional)
                $table->text('notes')->nullable();
                $table->text('failure_reason')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['dsr_request_id', 'status']);
                $table->index(['information_system_id', 'executed_at']);
                $table->index('status');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1050 || in_array($e->getCode(), ['42P07', '42S01'], true)) return;
            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dsr_executions');
    }
};
