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
        Schema::create('lms_quizzes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('owner_type', ['module', 'feature_doc']);
            $table->string('owner_key'); // module id (string) or feature_doc section slug
            $table->unsignedTinyInteger('passing_score')->default(70); // 0–100
            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_quizzes');
    }
};
