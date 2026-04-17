<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C4: Threaded comments on ROPA/DPIA/Breach/etc records.
 * parent_id nullable → self-referential threading.
 */
return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('module_comments');
    }
};
