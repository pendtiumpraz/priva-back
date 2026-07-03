<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadata kepatuhan per AI provider — supaya pemilihan provider AI benar-benar
 * sadar UU PDP (bukan sekadar disclaimer). Diisi oleh AiProviderComplianceSeeder
 * dari hasil riset publik (DPA URL, ZDR, GDPR, yurisdiksi, flag risiko PDP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $t) {
            if (! Schema::hasColumn('ai_providers', 'jurisdiction')) {
                $t->string('jurisdiction', 120)->nullable()->after('website');       // negara/region pemrosesan
            }
            if (! Schema::hasColumn('ai_providers', 'dpa_url')) {
                $t->string('dpa_url', 500)->nullable()->after('jurisdiction');        // URL tanda tangan/lihat DPA (null = tidak ada)
            }
            if (! Schema::hasColumn('ai_providers', 'privacy_url')) {
                $t->string('privacy_url', 500)->nullable()->after('dpa_url');         // URL kebijakan privasi provider
            }
            if (! Schema::hasColumn('ai_providers', 'zdr_available')) {
                $t->boolean('zdr_available')->default(false)->after('dpa_url');        // menyediakan Zero-Data-Retention?
            }
            if (! Schema::hasColumn('ai_providers', 'zdr_note')) {
                $t->string('zdr_note', 500)->nullable()->after('zdr_available');
            }
            if (! Schema::hasColumn('ai_providers', 'gdpr_status')) {
                $t->string('gdpr_status', 24)->nullable()->after('zdr_note');         // verified|compliant|partial|none
            }
            if (! Schema::hasColumn('ai_providers', 'no_training')) {
                $t->boolean('no_training')->nullable()->after('gdpr_status');         // tidak melatih model dari data API
            }
            if (! Schema::hasColumn('ai_providers', 'pdp_risk')) {
                $t->string('pdp_risk', 20)->nullable()->after('no_training');         // safe|caution|not_recommended
            }
            if (! Schema::hasColumn('ai_providers', 'compliance_note')) {
                $t->text('compliance_note')->nullable()->after('pdp_risk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $t) {
            foreach (['jurisdiction', 'dpa_url', 'zdr_available', 'zdr_note', 'gdpr_status', 'no_training', 'pdp_risk', 'compliance_note'] as $c) {
                if (Schema::hasColumn('ai_providers', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
