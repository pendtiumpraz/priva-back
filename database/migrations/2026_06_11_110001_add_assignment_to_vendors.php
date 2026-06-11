<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM assignment + division-scoped visibility for vendors (pihak ketiga).
 *
 * Mirrors RoPA/DPIA's assign_group + assignees model so a vendor can be
 * assigned to All / a division (assign_group = division name) / specific
 * users (assignees[] = user UUIDs). Drives applyVendorScope() so non-admin
 * users don't see vendors from other divisions unless assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('assign_group')->nullable()->after('bidang');
            $table->json('assignees')->nullable()->after('assign_group');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['assign_group', 'assignees']);
        });
    }
};
