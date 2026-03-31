<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_log_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->longText('raw_log_snippet');
            $table->jsonb('ai_analysis'); // will store the JSON response from AI
            $table->string('status')->default('success'); // success, failed
            $table->text('error_message')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_log_analyses');
    }
};
