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
        Schema::table('information_systems', function (Blueprint $table) {
            $table->json('ai_scan_results')->nullable()->after('scan_results');
        });

        Schema::create('ai_specific_searches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('system_id')->constrained('information_systems')->cascadeOnDelete();
            $table->string('user_prompt');
            $table->json('generated_sql')->nullable();
            $table->integer('found_rows_count')->default(0);
            $table->json('ai_analysis_insight')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_specific_searches');

        Schema::table('information_systems', function (Blueprint $table) {
            $table->dropColumn('ai_scan_results');
        });
    }
};
