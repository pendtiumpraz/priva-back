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
        Schema::create('lms_lessons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('module_id');
            $table->string('slug');
            $table->string('title');
            $table->longText('body')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('video_id')->nullable();
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('lms_modules')->cascadeOnDelete();
            $table->foreign('video_id')->references('id')->on('lms_videos')->nullOnDelete();
            $table->unique(['module_id', 'slug']);
            $table->index(['module_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_lessons');
    }
};
