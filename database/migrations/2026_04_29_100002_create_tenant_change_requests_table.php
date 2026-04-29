<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow approval table for tenant-initiated infrastructure changes.
 *
 * Tenant admin POST-s a request (assign DB pool, switch to BYODB, etc).
 * Root/superadmin reviews from the queue and either approves+executes
 * (dispatches ExecuteChangeRequestJob) or denies. Status tracks the
 * lifecycle so the requesting tenant sees progress in their UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_change_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('requested_by')->nullable();

            // What kind of change — see BYODB.md §2.6 for the full list.
            $table->string('request_type', 40);
            // One of:
            //   db_assign_pool, db_change_pool, db_switch_to_byodb,
            //   storage_assign_pool, storage_change_pool, storage_switch_to_byos,
            //   reset_to_shared

            // Target config or pool — shape depends on request_type.
            $table->json('payload');

            $table->text('reason')->nullable();

            // Lifecycle: pending → approved → executing → executed (or failed/denied)
            $table->string('status', 20)->default('pending');

            // Review trail
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Execution trail
            $table->timestamp('executed_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index('org_id');
            $table->index('status');
            $table->index(['status', 'created_at']);  // for the approval queue
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_change_requests');
    }
};
