<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lms_user_bookmarks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id');
            $table->uuid('org_id');
            $table->unsignedBigInteger('lesson_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('lesson_id')->references('id')->on('lms_lessons')->cascadeOnDelete();
            $table->unique(['user_id', 'lesson_id']);
            $table->index(['org_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_user_bookmarks');
    }
};
