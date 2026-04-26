<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSR Request Scopes — pivot DSR ↔ Information System.
 *
 * Setiap DSR bisa affect multiple Information Systems. DPO pick scope
 * setelah verify DSR. Untuk system sharded, pilih juga shards mana yang
 * perlu di-execute.
 *
 * Per-scope per request_type: misal user minta "deletion" untuk Customer
 * DB tapi cuma "withdraw_consent" untuk Marketing CRM — disimpan di
 * request_types JSON array.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('dsr_request_scopes')) return;

        try {
            Schema::create('dsr_request_scopes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('dsr_request_id');
                $table->uuid('information_system_id');
                $table->json('shards_affected')->nullable();   // ["shard_01", "shard_02"]
                $table->json('request_types');                  // ["access", "deletion"]
                $table->string('sql_pack_status', 32)->default('pending');
                // pending | generated | downloaded | partial_executed | executed | failed
                $table->text('sql_pack_url')->nullable();      // signed URL (temporary)
                $table->timestamp('sql_pack_generated_at')->nullable();
                $table->timestamp('sql_pack_downloaded_at')->nullable();
                $table->timestamps();

                $table->unique(['dsr_request_id', 'information_system_id'], 'dsr_scope_unique');
                $table->index('dsr_request_id');
                $table->index('information_system_id');
                $table->index('sql_pack_status');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1050 || in_array($e->getCode(), ['42P07', '42S01'], true)) return;
            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dsr_request_scopes');
    }
};
