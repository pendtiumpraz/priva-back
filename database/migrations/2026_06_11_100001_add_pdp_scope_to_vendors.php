<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Pre-Assessment ("Penyaringan Lingkup PDP") — vendor scope gate.
 *
 * Adds the PDP-scope decision fields directly on `vendors` so the TPRM
 * list/table can render scope tabs + badges without a join. The decision is
 * AUTO-SUGGESTED from triage answers (vendor_pre_assessments) and may be
 * OVERRIDDEN by a reviewer; OUT OF SCOPE additionally requires DPO approval.
 *
 * pdp_scope_status state machine:
 *   unscreened            → belum disaring (default untuk vendor lama + baru)
 *   in_scope              → menyentuh data pribadi → wajib full assessment
 *   out_of_scope_pending  → reviewer memutuskan di luar lingkup, menunggu DPO
 *   out_of_scope          → DPO menyetujui → tidak perlu full assessment
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('pdp_scope_status', 32)->default('unscreened')->index()->after('risk_level');
            $table->timestamp('scope_decided_at')->nullable()->after('pdp_scope_status');
            $table->uuid('scope_decided_by')->nullable()->after('scope_decided_at');
            $table->text('scope_justification')->nullable()->after('scope_decided_by');
            $table->boolean('scope_overridden')->default(false)->after('scope_justification');
            $table->uuid('scope_approved_by')->nullable()->after('scope_overridden');
            $table->timestamp('scope_approved_at')->nullable()->after('scope_approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'pdp_scope_status',
                'scope_decided_at',
                'scope_decided_by',
                'scope_justification',
                'scope_overridden',
                'scope_approved_by',
                'scope_approved_at',
            ]);
        });
    }
};
