<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Country adequacy lookup. UU PDP Pasal 56 doesn't publish an
 * official adequacy list yet (Komdigi still drafting), so we maintain a
 * working tier classification based on:
 *   - GDPR adequacy decisions (Tier 1, "adequate")
 *   - ASEAN PDP harmonization (Tier 2, "comparable")
 *   - countries with PDP law but concerns (Tier 3, "limited")
 *   - countries with no framework or sovereign-access risk (Tier 4, "none")
 *
 * Tier maps to default risk_regulation_mismatch score (1-10) so a fresh
 * TIA pre-fills with a sensible starting risk based on destination.
 *
 * Platform-level data — same list applies across all tenants. No org_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('country_adequacies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('country_code', 8);     // ISO 3166-1 alpha-2 (e.g. ID, SG)
            $table->string('country_name');
            $table->string('region')->nullable();  // ASEAN, EU/EEA, North America, etc.

            // Tier classification
            $table->string('tier');                // adequate, comparable, limited, none
            $table->string('basis')->nullable();   // 'GDPR Art 45', 'ASEAN PDP harmonization', etc.
            $table->text('notes')->nullable();     // free-form explanation surfaced in UI

            // Default TIA pre-fill scores (1-10 scale, higher = riskier)
            $table->tinyInteger('default_regulation_mismatch')->default(5);
            $table->tinyInteger('default_sovereign_access_risk')->default(5);
            $table->tinyInteger('default_admin_sanctions')->default(5);

            // Pasal 56 ayat 1 hints
            $table->boolean('has_pdp_law')->default(false);
            $table->boolean('has_pdp_authority')->default(false);
            $table->boolean('recommended_safeguards_required')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('country_code');
            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_adequacies');
    }
};
