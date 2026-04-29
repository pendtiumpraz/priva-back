<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — CBDT becomes a real transfer inventory instead of a 5-field
 * stub. The new columns capture what a DPO/CISO actually needs to file
 * Pasal 56 records and what TIA Sprint X2 needs as upstream data so the
 * assessor doesn't re-ask everything.
 *
 * Field mapping rationale:
 *   - transfer_volume_band & frequency: drive risk_data_leak metric
 *   - data_sensitivity:               drives sensitive-data weighting
 *   - transfer_mechanism:             clarifies attack surface
 *   - encryption_*:                   feed security_protocol_score
 *   - retention_period_days:          regulatory exposure window
 *   - recipient_dpo_*:                Pasal 56 ayat 2 — controller
 *                                     wajib tahu PIC penerima
 *   - linked_ropa_id:                 prevents orphaned transfers
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            // Transfer profile
            $table->string('transfer_volume_band')->nullable()->after('data_categories');
            // small (<1k), medium (1k-100k), large (100k-1M), mass (>1M records)
            $table->string('transfer_frequency')->nullable()->after('transfer_volume_band');
            // one_time, monthly, weekly, daily, realtime
            $table->string('data_sensitivity')->nullable()->after('transfer_frequency');
            // general, personal, sensitive_specific (UU PDP Pasal 4 ayat 2), extra_sensitive
            $table->string('transfer_mechanism')->nullable()->after('data_sensitivity');
            // api, batch_export, replication, manual_email, cloud_sync, file_share

            // Security controls
            $table->boolean('encryption_in_transit')->nullable()->after('transfer_mechanism');
            $table->boolean('encryption_at_rest')->nullable()->after('encryption_in_transit');
            $table->boolean('data_minimization_applied')->nullable()->after('encryption_at_rest');
            $table->integer('retention_period_days')->nullable()->after('data_minimization_applied');

            // Recipient PIC (Pasal 56 ayat 2)
            $table->string('recipient_dpo_name')->nullable()->after('retention_period_days');
            $table->string('recipient_dpo_email')->nullable()->after('recipient_dpo_name');

            // Tie back to processing activity so transfer isn't orphaned
            $table->uuid('linked_ropa_id')->nullable()->after('recipient_dpo_email');
            $table->foreign('linked_ropa_id')->references('id')->on('ropas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropForeign(['linked_ropa_id']);
            $table->dropColumn([
                'transfer_volume_band', 'transfer_frequency', 'data_sensitivity', 'transfer_mechanism',
                'encryption_in_transit', 'encryption_at_rest', 'data_minimization_applied',
                'retention_period_days', 'recipient_dpo_name', 'recipient_dpo_email',
                'linked_ropa_id',
            ]);
        });
    }
};
