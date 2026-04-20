<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dpia_risk_event_templates')) return;

        Schema::create('dpia_risk_event_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Domain bucket (legal_basis, retensi, enkripsi_structured, etc.).
            // Not 1:1 with Nexus UU-PDP principle categories — this is pdp
            // existing's control-area taxonomy. DPO picks risks across any
            // bucket to attach to their DPIA assessment.
            $table->string('category_key', 80);
            $table->string('category_label', 200);
            // Within the category, stable ordering for display consistency.
            $table->integer('sequence')->default(0);
            $table->string('risk_event', 400);
            $table->text('default_description')->nullable();
            // Optional default scoring hint — DPO can override.
            $table->tinyInteger('default_dampak')->nullable();        // 1-5
            $table->tinyInteger('default_probabilitas')->nullable();  // 1-5
            $table->tinyInteger('default_kontrol')->nullable();       // 1-3
            $table->string('default_penanganan', 20)->nullable();     // mitigate|accept|transfer|terminate
            $table->boolean('is_system')->default(true); // seeded (not user-created)
            $table->uuid('org_id')->nullable();          // for future tenant-custom templates
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category_key');
            $table->index(['category_key', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dpia_risk_event_templates');
    }
};
