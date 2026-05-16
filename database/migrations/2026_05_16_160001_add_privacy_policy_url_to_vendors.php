<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 3 — URL privacy policy vendor.
 *
 * Field opsional. Tenant input manual saat onboarding vendor / di wizard
 * Step 1. Backend akan fetch URL ini saat AI Screening dijalankan untuk
 * cek kepatuhan klausa UU PDP.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('privacy_policy_url', 500)->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('privacy_policy_url');
        });
    }
};
