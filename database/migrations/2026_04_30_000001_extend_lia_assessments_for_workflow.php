<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint X1 — extend lia_assessments to match the full PDF spec
 * (docs/new_feat/Fitur LIA.pdf).
 *
 * Adds:
 *   - lia_code (unique per-org identifier, format LIA-[UNIT]-[ACTIVITY]-[N])
 *   - linked_dpia_id (FK to dpias, optional secondary linkage besides ropa)
 *   - legitimate_interest_basis + legitimate_interest_reason
 *   - balancing_risk_events JSON (risk register table for the Balancing Test)
 *   - subject_loses_control + reason
 *   - 3 conclusion verdicts (purpose / necessity / balancing) — filled by Approver
 *   - RACI workflow columns (maker/checker/approver, timestamps, is_locked)
 *
 * State machine after this migration:
 *   draft → submitted (Maker) → checked (Checker, optional)
 *                            → approved (Approver) | rejected (back to Maker)
 * is_locked flips to true on submitted; only root can unlock for emergency edits.
 *
 * See backend/docs/LIA_TIA_MATURITY_TRACKER.md for the full sprint plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lia_assessments', function (Blueprint $table) {
            // Identifier
            if (!Schema::hasColumn('lia_assessments', 'lia_code')) {
                $table->string('lia_code', 64)->nullable()->after('id');
            }

            // Secondary linkage (linked_ropa_id already exists)
            if (!Schema::hasColumn('lia_assessments', 'linked_dpia_id')) {
                $table->uuid('linked_dpia_id')->nullable()->after('linked_ropa_id');
            }

            // Section 3: Dasar Pemrosesan
            if (!Schema::hasColumn('lia_assessments', 'legitimate_interest_basis')) {
                $table->string('legitimate_interest_basis', 16)->nullable();   // 'yes' | 'no'
            }
            if (!Schema::hasColumn('lia_assessments', 'legitimate_interest_reason')) {
                $table->text('legitimate_interest_reason')->nullable();
            }

            // Section 6: Balancing Test risk register
            // Shape: array of { event, impact, probability, inherent_risk, control_type, control_name, residual_risk }
            if (!Schema::hasColumn('lia_assessments', 'balancing_risk_events')) {
                $table->json('balancing_risk_events')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'subject_loses_control')) {
                $table->string('subject_loses_control', 16)->nullable();       // 'yes' | 'no'
            }
            if (!Schema::hasColumn('lia_assessments', 'subject_loses_control_reason')) {
                $table->text('subject_loses_control_reason')->nullable();
            }

            // Section 8: Conclusion (filled by Checker/Approver)
            if (!Schema::hasColumn('lia_assessments', 'conclusion_purpose')) {
                $table->string('conclusion_purpose', 16)->nullable();          // 'lulus' | 'tidak_lulus'
            }
            if (!Schema::hasColumn('lia_assessments', 'conclusion_necessity')) {
                $table->string('conclusion_necessity', 16)->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'conclusion_balancing')) {
                $table->string('conclusion_balancing', 16)->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'conclusion_notes')) {
                $table->text('conclusion_notes')->nullable();
            }

            // RACI workflow
            if (!Schema::hasColumn('lia_assessments', 'maker_id')) {
                $table->uuid('maker_id')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'checker_id')) {
                $table->uuid('checker_id')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'approver_id')) {
                $table->uuid('approver_id')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'checked_at')) {
                $table->timestamp('checked_at')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (!Schema::hasColumn('lia_assessments', 'is_locked')) {
                $table->boolean('is_locked')->default(false);
            }
            if (!Schema::hasColumn('lia_assessments', 'unlocked_by')) {
                $table->uuid('unlocked_by')->nullable();   // root override audit trail
            }
            if (!Schema::hasColumn('lia_assessments', 'unlocked_at')) {
                $table->timestamp('unlocked_at')->nullable();
            }
        });

        // Indexes for the common query patterns
        Schema::table('lia_assessments', function (Blueprint $table) {
            $idx = collect(Schema::getIndexes('lia_assessments'))->pluck('name')->all();
            if (!in_array('lia_assessments_org_lia_code_idx', $idx, true)) {
                $table->index(['org_id', 'lia_code'], 'lia_assessments_org_lia_code_idx');
            }
            if (!in_array('lia_assessments_status_idx', $idx, true)) {
                $table->index('status', 'lia_assessments_status_idx');
            }
            if (!in_array('lia_assessments_is_locked_idx', $idx, true)) {
                $table->index('is_locked', 'lia_assessments_is_locked_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lia_assessments', function (Blueprint $table) {
            $columns = [
                'lia_code', 'linked_dpia_id',
                'legitimate_interest_basis', 'legitimate_interest_reason',
                'balancing_risk_events', 'subject_loses_control', 'subject_loses_control_reason',
                'conclusion_purpose', 'conclusion_necessity', 'conclusion_balancing', 'conclusion_notes',
                'maker_id', 'checker_id', 'approver_id',
                'submitted_at', 'checked_at', 'approved_at', 'rejected_at',
                'rejection_reason', 'is_locked', 'unlocked_by', 'unlocked_at',
            ];
            foreach ($columns as $c) {
                if (Schema::hasColumn('lia_assessments', $c)) $table->dropColumn($c);
            }
        });
    }
};
