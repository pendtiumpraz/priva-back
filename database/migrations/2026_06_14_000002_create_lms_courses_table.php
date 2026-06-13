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
        Schema::create('lms_courses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('org_id')->nullable();
            $table->string('slug');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('level')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('regulation_code')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->boolean('published')->default(false);
            $table->unsignedInteger('order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['org_id', 'slug']);
            $table->index(['published', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_courses');
    }
};
