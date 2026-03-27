<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->string('action_type'); // autofill_ropa, autofill_dpia, analysis, chat, drill_scenario
            $table->decimal('credits_used', 5, 2)->default(1.0);
            $table->string('status')->default('success'); // success, failed, refunded
            $table->string('module')->nullable(); // ropa, dpia, breach, dsr, consent, gap, chat
            $table->uuid('record_id')->nullable(); // related record ID
            $table->json('metadata')->nullable(); // {prompt_tokens, response_tokens, model, duration_ms}
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['org_id', 'created_at']);
            $table->index(['org_id', 'action_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_logs');
    }
};
