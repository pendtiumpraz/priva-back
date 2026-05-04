<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat perubahan status QA test result.
 * Dipakai untuk regression detection: hari ini pass, besok fail — siapa
 * yang ubah, kapan, dengan catatan apa.
 *
 * Append-only — tidak ada edit/delete (tetapi cascade-deleted kalau parent
 * test_result di-delete). Tiap update di qa_test_results auto-generate
 * 1 row di sini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_test_result_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('test_result_id');
            $table->string('previous_status', 32)->nullable();
            $table->string('status', 32);
            $table->string('tester_name', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('changed_at');

            $table->foreign('test_result_id')->references('id')->on('qa_test_results')->cascadeOnDelete();
            $table->index(['test_result_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_test_result_history');
    }
};
