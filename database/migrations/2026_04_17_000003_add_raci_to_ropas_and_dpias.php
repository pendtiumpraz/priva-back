<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C2: RACI matrix column on ROPA and DPIA.
 * Format: [{ task: "...", responsible: user_id, accountable: user_id, consulted: [user_id,...], informed: [user_id,...] }]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->json('raci_matrix')->nullable()->after('wizard_data');
        });

        Schema::table('dpias', function (Blueprint $table) {
            $table->json('raci_matrix')->nullable()->after('wizard_data');
        });
    }

    public function down(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->dropColumn('raci_matrix');
        });

        Schema::table('dpias', function (Blueprint $table) {
            $table->dropColumn('raci_matrix');
        });
    }
};
