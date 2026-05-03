<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C4: Threaded comments on RoPA/DPIA/Breach/etc records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_comments')) {
            return;
        }

        try {
            Schema::create('module_comments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('module', 32);
                $table->uuid('record_id');
                $table->uuid('user_id');
                $table->uuid('parent_id')->nullable();
                $table->text('comment');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->index(['org_id', 'module', 'record_id'], 'module_comments_record_idx');
                $table->index('parent_id');
            });
        } catch (Throwable $e) {
            if (! $this->alreadyExists($e)) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('module_comments');
    }

    private function alreadyExists(Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'already exists')
            || str_contains($msg, '1050')
            || str_contains($msg, '42S01')
            || str_contains($msg, '42P07');
    }
};
