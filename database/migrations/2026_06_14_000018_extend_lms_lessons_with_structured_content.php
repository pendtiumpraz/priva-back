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
        Schema::table('lms_lessons', function (Blueprint $table) {
            $table->jsonb('steps')->nullable();
            $table->jsonb('tips')->nullable();
            $table->jsonb('tags')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lms_lessons', function (Blueprint $table) {
            $table->dropColumn(['steps', 'tips', 'tags']);
        });
    }
};
