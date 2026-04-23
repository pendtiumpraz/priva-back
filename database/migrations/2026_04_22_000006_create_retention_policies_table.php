<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retention master data (Sprint E3) — per-tenant reusable library.
 *
 * A single ROPA can reference multiple retention_policies via
 * `wizard_data.retensi_keamanan.retensi_list[].policy_id`. Lets compliance
 * teams standardize retention rules across many processing activities
 * instead of re-typing each time.
 *
 * Soft-deleted so in-use policies can be restored; hard delete is blocked
 * while any active ROPA still references the policy.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('retention_policies')) return;
        try {
            Schema::create('retention_policies', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id')->index();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->unsignedInteger('duration_value')->nullable(); // null = indefinite
                $table->string('duration_unit', 16)->default('year');  // day|month|year|indefinite
                $table->string('trigger_event', 255)->nullable();      // e.g. "Karyawan resign / PHK"
                $table->string('disposal_method', 32)->default('delete'); // delete|anonymize|archive
                $table->string('legal_basis', 255)->nullable();        // e.g. "UU 13/2003 Pasal 65"
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['org_id', 'deleted_at']);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            // MySQL 1050 / Postgres 42P07 = "table already exists".
            if ($code === 1050 || in_array($e->getCode(), ['42P07', '42S01'], true)) return;
            throw $e;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
