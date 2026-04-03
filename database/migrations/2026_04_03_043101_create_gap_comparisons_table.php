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
        Schema::create('gap_comparisons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('title');
            $table->string('regulation_code');
            $table->json('assessment_ids')->nullable(); // Arrays of [id, id]
            $table->json('chart_data')->nullable(); // the result array for plotting
            $table->longText('system_analysis')->nullable(); // Built-in calculated trend texts
            $table->longText('ai_analysis')->nullable(); // GPT generated insight
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gap_comparisons');
    }
};
