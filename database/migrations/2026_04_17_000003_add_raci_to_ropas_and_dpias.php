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
        if (Schema::hasTable('ropas') && !Schema::hasColumn('ropas', 'raci_matrix')) {
            Schema::table('ropas', function (Blueprint $table) {
                $table->json('raci_matrix')->nullable();
            });
        }

        if (Schema::hasTable('dpias') && !Schema::hasColumn('dpias', 'raci_matrix')) {
            Schema::table('dpias', function (Blueprint $table) {
                $table->json('raci_matrix')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ropas') && Schema::hasColumn('ropas', 'raci_matrix')) {
            Schema::table('ropas', function (Blueprint $table) {
                $table->dropColumn('raci_matrix');
            });
        }

        if (Schema::hasTable('dpias') && Schema::hasColumn('dpias', 'raci_matrix')) {
            Schema::table('dpias', function (Blueprint $table) {
                $table->dropColumn('raci_matrix');
            });
        }
    }
};
