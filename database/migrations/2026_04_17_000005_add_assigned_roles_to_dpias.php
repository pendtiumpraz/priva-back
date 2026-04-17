<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C8: per-DPIA role assignments (reviewer / approver / dpo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpias', function (Blueprint $table) {
            if (!Schema::hasColumn('dpias', 'assigned_roles')) {
                $table->json('assigned_roles')->nullable()->after('raci_matrix');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dpias', function (Blueprint $table) {
            if (Schema::hasColumn('dpias', 'assigned_roles')) {
                $table->dropColumn('assigned_roles');
            }
        });
    }
};
