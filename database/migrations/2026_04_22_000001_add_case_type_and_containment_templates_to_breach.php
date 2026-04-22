<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Breach case-type classification + tenant-customizable containment
 * templates.
 *
 * - `breach_incidents.case_type` — classification picked on creation
 *   (ransomware, phishing, insider_threat, etc). Drives which containment
 *   template gets applied.
 * - `containment_templates` — library of step-by-step SOPs per case type.
 *   System defaults seeded for 15 common attack vectors (org_id=null).
 *   Tenants can create their own (org_id=tenant-uuid) or clone + edit the
 *   defaults.
 *
 * The `containment_checklist` JSON column on breach_incidents is repurposed
 * from `Record<string, bool>` to `Record<string, StepState>` where each
 * step holds: done, notes, evidence_files[], completed_by, completed_at.
 * Legacy rows keep working because readers treat missing fields as null.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->string('case_type', 40)->nullable()->after('severity')->index();
            $table->uuid('containment_template_id')->nullable()->after('case_type');
        });

        Schema::create('containment_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable()->index(); // null = system default
            $table->string('case_type', 40)->index();     // ransomware/phishing/...
            $table->string('label', 150);                 // "Ransomware — Standard SOP"
            $table->text('description')->nullable();
            $table->json('steps');                        // [{key, label, category, requires_evidence, hint}...]
            $table->boolean('is_default')->default(false); // seed flag — tenants inherit the first matching default
            $table->boolean('is_system')->default(false);  // true for seeded rows — cannot be deleted by tenant
            $table->unsignedInteger('usage_count')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'case_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('containment_templates');
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->dropColumn(['case_type', 'containment_template_id']);
        });
    }
};
