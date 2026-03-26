<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            // Rename 'score' to 'overall_score' to match model
            $table->renameColumn('score', 'overall_score');
            // Rename 'summary' to 'recommendations' to match model
            $table->renameColumn('summary', 'recommendations');
        });
    }

    public function down(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            $table->renameColumn('overall_score', 'score');
            $table->renameColumn('recommendations', 'summary');
        });
    }
};
