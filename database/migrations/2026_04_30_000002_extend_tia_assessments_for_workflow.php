<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint X2 — extend tia_assessments to match the full PDF spec
 * (docs/new_feat/Fitur TIA.pdf).
 *
 * Adds:
 *   - tia_code (LIA-style identifier per-org)
 *   - linked_ropa_id + linked_vendor_id (in addition to existing
 *     linked_cross_border_id) — TIA can be created from any of the three
 *   - transfer description: volume, frequency, basis (kontrak/consent/BCR/other)
 *   - country/recipient assessment: has_pdp_law, has_pdp_authority,
 *     recipient_maturity_score (1-10), sender_maturity_score (1-10)
 *   - 6 risk metrics 1-10 (regulation_mismatch, contractual_breach,
 *     admin_sanctions, data_leak, data_integrity, sovereign_access)
 *   - 2 security metrics 1-10 (protocol, encryption)
 *   - supplementary_doc_ids (JSON array — refs to Document model files
 *     for akta/kontrak/lainnya)
 *   - overall_risk_score (auto-computed weighted average of the 8 metrics)
 *   - RACI workflow columns (mirror Sprint X1 LIA pattern)
 *
 * Lifecycle (mirror LIA):
 *   draft → submitted → checked → approved | rejected
 *
 * Sister migration: 2026_04_30_000001_extend_lia_assessments_for_workflow
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            // Identifier
            if (!Schema::hasColumn('tia_assessments', 'tia_code')) {
                $table->string('tia_code', 64)->nullable()->after('id');
            }

            // Source linkages (linked_cross_border_id sudah ada)
            if (!Schema::hasColumn('tia_assessments', 'linked_ropa_id')) {
                $table->uuid('linked_ropa_id')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'linked_vendor_id')) {
                $table->uuid('linked_vendor_id')->nullable();
            }

            // Transfer description (PDF Section 2)
            if (!Schema::hasColumn('tia_assessments', 'transfer_volume')) {
                $table->string('transfer_volume', 32)->nullable();        // 'low'|'medium'|'high'
            }
            if (!Schema::hasColumn('tia_assessments', 'transfer_frequency')) {
                $table->string('transfer_frequency', 32)->nullable();     // 'one_time'|'periodic'|'continuous'
            }
            if (!Schema::hasColumn('tia_assessments', 'transfer_basis')) {
                $table->string('transfer_basis', 64)->nullable();         // 'contract'|'consent'|'bcr'|'other'
            }
            if (!Schema::hasColumn('tia_assessments', 'transfer_basis_other')) {
                $table->string('transfer_basis_other', 255)->nullable();
            }

            // Country + recipient assessment (PDF Section 3)
            if (!Schema::hasColumn('tia_assessments', 'destination_country')) {
                $table->string('destination_country', 64)->nullable();    // ISO code or human name
            }
            if (!Schema::hasColumn('tia_assessments', 'destination_has_pdp_law')) {
                $table->boolean('destination_has_pdp_law')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'destination_has_pdp_authority')) {
                $table->boolean('destination_has_pdp_authority')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'recipient_maturity_score')) {
                $table->unsignedTinyInteger('recipient_maturity_score')->nullable();   // 1-10
            }
            if (!Schema::hasColumn('tia_assessments', 'sender_maturity_score')) {
                $table->unsignedTinyInteger('sender_maturity_score')->nullable();      // 1-10
            }

            // 6 risk metrics 1-10 (PDF Section 4) — high score = risky
            if (!Schema::hasColumn('tia_assessments', 'risk_regulation_mismatch')) {
                $table->unsignedTinyInteger('risk_regulation_mismatch')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'risk_contractual_breach')) {
                $table->unsignedTinyInteger('risk_contractual_breach')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'risk_admin_sanctions')) {
                $table->unsignedTinyInteger('risk_admin_sanctions')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'risk_data_leak')) {
                $table->unsignedTinyInteger('risk_data_leak')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'risk_data_integrity')) {
                $table->unsignedTinyInteger('risk_data_integrity')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'risk_sovereign_access')) {
                $table->unsignedTinyInteger('risk_sovereign_access')->nullable();
            }

            // 2 security metrics 1-10 (PDF Section 5) — high score = good
            if (!Schema::hasColumn('tia_assessments', 'security_protocol_score')) {
                $table->unsignedTinyInteger('security_protocol_score')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'security_encryption_score')) {
                $table->unsignedTinyInteger('security_encryption_score')->nullable();
            }

            // Computed overall risk score (weighted average — see TiaAssessment::computeOverallRisk())
            if (!Schema::hasColumn('tia_assessments', 'overall_risk_score')) {
                $table->decimal('overall_risk_score', 5, 2)->nullable();
            }

            // Supplementary docs (PDF Section 6 — file uploads)
            if (!Schema::hasColumn('tia_assessments', 'supplementary_doc_ids')) {
                $table->json('supplementary_doc_ids')->nullable();
            }

            // RACI workflow (mirror LIA Sprint X1)
            if (!Schema::hasColumn('tia_assessments', 'maker_id')) {
                $table->uuid('maker_id')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'checker_id')) {
                $table->uuid('checker_id')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'approver_id')) {
                $table->uuid('approver_id')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'checked_at')) {
                $table->timestamp('checked_at')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'is_locked')) {
                $table->boolean('is_locked')->default(false);
            }
            if (!Schema::hasColumn('tia_assessments', 'unlocked_by')) {
                $table->uuid('unlocked_by')->nullable();
            }
            if (!Schema::hasColumn('tia_assessments', 'unlocked_at')) {
                $table->timestamp('unlocked_at')->nullable();
            }

            // Conclusion verdict (single overall, since TIA's risk model is
            // numeric — no 3-test split like LIA)
            if (!Schema::hasColumn('tia_assessments', 'conclusion_verdict')) {
                $table->string('conclusion_verdict', 16)->nullable();   // 'approved'|'conditional'|'rejected'
            }
            if (!Schema::hasColumn('tia_assessments', 'conclusion_notes')) {
                $table->text('conclusion_notes')->nullable();
            }
        });

        // Indexes
        Schema::table('tia_assessments', function (Blueprint $table) {
            $idx = collect(Schema::getIndexes('tia_assessments'))->pluck('name')->all();
            if (!in_array('tia_assessments_org_tia_code_idx', $idx, true)) {
                $table->index(['org_id', 'tia_code'], 'tia_assessments_org_tia_code_idx');
            }
            if (!in_array('tia_assessments_linked_vendor_idx', $idx, true)) {
                $table->index('linked_vendor_id', 'tia_assessments_linked_vendor_idx');
            }
            if (!in_array('tia_assessments_overall_risk_idx', $idx, true)) {
                $table->index('overall_risk_score', 'tia_assessments_overall_risk_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tia_assessments', function (Blueprint $table) {
            $columns = [
                'tia_code', 'linked_ropa_id', 'linked_vendor_id',
                'transfer_volume', 'transfer_frequency', 'transfer_basis', 'transfer_basis_other',
                'destination_country', 'destination_has_pdp_law', 'destination_has_pdp_authority',
                'recipient_maturity_score', 'sender_maturity_score',
                'risk_regulation_mismatch', 'risk_contractual_breach', 'risk_admin_sanctions',
                'risk_data_leak', 'risk_data_integrity', 'risk_sovereign_access',
                'security_protocol_score', 'security_encryption_score',
                'overall_risk_score', 'supplementary_doc_ids',
                'maker_id', 'checker_id', 'approver_id',
                'submitted_at', 'checked_at', 'approved_at', 'rejected_at',
                'rejection_reason', 'is_locked', 'unlocked_by', 'unlocked_at',
                'conclusion_verdict', 'conclusion_notes',
            ];
            foreach ($columns as $c) {
                if (Schema::hasColumn('tia_assessments', $c)) $table->dropColumn($c);
            }
        });
    }
};
