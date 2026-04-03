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
        Schema::create('security_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->string('rule_code', 50)->index();        // e.g. 'dpa_expired', 'dsr_overdue', 'breach_open'
            $table->string('severity', 20)->default('medium'); // critical, high, medium, low
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('module', 50)->nullable();         // ropa, dpia, vendor-risk, dsr, breach
            $table->uuid('record_id')->nullable();            // related record
            $table->string('status', 20)->default('open');    // open, acknowledged, resolved, dismissed
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();             // extra context
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
