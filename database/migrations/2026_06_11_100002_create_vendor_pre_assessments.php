<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Pre-Assessment (triage / "Penyaringan Lingkup PDP").
 *
 * Gate BEFORE the full vendor assessment. A short triage questionnaire whose
 * answers AUTO-SUGGEST whether the third party is IN SCOPE (touches personal
 * data → needs full assessment) or OUT OF SCOPE (e.g. furniture/AC vendor →
 * recorded, no full assessment). A reviewer confirms/overrides the suggestion;
 * OUT OF SCOPE additionally requires DPO approval + justification.
 *
 * Fillable INTERNALLY or by the third party via a PUBLIC LINK — mirroring the
 * vendor_assessments public-token columns (assessment_token / token_expires_at
 * / token_consumed_at + submitted_ip / submitted_user_agent) so the same
 * public-token middleware pattern can gate it.
 *
 * Lifecycle: draft → submitted (answers in, scope suggested) → decided
 * (reviewer set final_scope; vendor.pdp_scope_status updated). One active
 * pre-assessment per vendor enforced in code (re-screen creates a new row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_pre_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('vendor_id')->index();

            // Map question_code => 'ya' | 'tidak' | null
            $table->json('answers')->nullable();

            // Auto-suggested vs reviewer-confirmed scope.
            $table->string('suggested_scope', 32)->nullable();  // in_scope | out_of_scope
            $table->string('final_scope', 32)->nullable();      // in_scope | out_of_scope
            $table->text('justification')->nullable();
            $table->boolean('overridden')->default(false);

            // draft | submitted | decided
            $table->string('status', 32)->default('draft')->index();

            // 'internal' | 'public_token'
            $table->string('filled_by', 32)->nullable();

            // Reviewer decision (scope confirm/override).
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();

            // DPO approval for out-of-scope.
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Public-token columns — mirror vendor_assessments naming so the
            // public-token middleware/service can treat both uniformly.
            $table->uuid('assessment_token')->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_consumed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->text('submitted_user_agent')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'vendor_id'], 'vendor_pre_assessment_org_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_pre_assessments');
    }
};
