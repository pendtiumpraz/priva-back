<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DPIA ↔ RoPA many-to-many. Saat ini dpias.ropa_id hanya 1 parent FK,
 * tapi 1 DPIA bisa cover banyak RoPA (e.g. DPIA "Marketing Stack" cover
 * ROPA-001 customer outreach + ROPA-002 retargeting + ROPA-003 lookalike).
 *
 * Source of truth: pivot. Legacy `ropa_id` tetap kept untuk backwards-compat
 * (treat sebagai "primary linked RoPA"), tapi rendering pakai pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dpia_ropa')) {
            return;
        }
        try {
            Schema::create('dpia_ropa', function (Blueprint $t) {
                $t->uuid('dpia_id');
                $t->uuid('ropa_id');
                $t->uuid('org_id');
                $t->text('notes')->nullable();
                $t->timestamps();

                $t->primary(['dpia_id', 'ropa_id'], 'dpia_ropa_pk');
                $t->index('ropa_id', 'dpia_ropa_ropa_idx');
                $t->index(['org_id', 'dpia_id'], 'dpia_ropa_org_idx');
                $t->foreign('dpia_id', 'dpia_ropa_dpia_fk')
                    ->references('id')->on('dpias')->cascadeOnDelete();
                $t->foreign('ropa_id', 'dpia_ropa_ropa_fk')
                    ->references('id')->on('ropas')->cascadeOnDelete();
                $t->foreign('org_id', 'dpia_ropa_org_fk')
                    ->references('id')->on('organizations')->cascadeOnDelete();
            });
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dpia_ropa');
    }
};
