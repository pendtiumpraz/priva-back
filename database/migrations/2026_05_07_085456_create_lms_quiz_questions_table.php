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
        Schema::create('lms_quiz_questions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('quiz_id');
            $table->enum('type', ['mcq', 'true_false']);
            $table->text('prompt');
            $table->jsonb('options')->nullable();
            $table->jsonb('correct_answer');
            $table->unsignedSmallInteger('points')->default(1);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('quiz_id')->references('id')->on('lms_quizzes')->cascadeOnDelete();
            $table->index(['quiz_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_quiz_questions');
    }
};
