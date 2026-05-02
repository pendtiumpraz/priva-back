<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * data_discovery_scan_results — hits with masking + (OnPrem) encrypted blob.
 *
 * Row per hit per table. `masked_row` is always populated and what the
 * frontend renders. `encrypted_row` is OnPrem-only — decrypted via Reveal
 * action (audit-logged, separate `data_discovery,reveal` permission).
 *
 * See DATA_DISCOVERY_SEARCH_PLAN.md §3.3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_discovery_scan_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('scan_plan_id')->index();
            $t->uuid('plan_system_id')->index();
            $t->uuid('information_system_id')->index();
            $t->string('table_name', 191);
            $t->string('confidence', 16);             // high|medium|low
            $t->json('matched_columns');              // ["email", "name"]
            $t->unsignedInteger('match_count')->default(1);
            $t->json('row_pks');                      // [{pk_col: value}]
            $t->json('masked_row');                   // safe-to-display
            $t->longText('encrypted_row')->nullable(); // Crypt::encryptString(json) — OnPrem only
            $t->boolean('revealed')->default(false);
            $t->uuid('revealed_by')->nullable();
            $t->timestamp('revealed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['org_id', 'scan_plan_id']);
            $t->index(['org_id', 'plan_system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_discovery_scan_results');
    }
};
