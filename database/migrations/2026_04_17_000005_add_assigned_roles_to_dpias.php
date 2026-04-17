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
        if (Schema::hasTable('dpias') && !Schema::hasColumn('dpias', 'assigned_roles')) {
            Schema::table('dpias', function (Blueprint $table) {
                $table->json('assigned_roles')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dpias') && Schema::hasColumn('dpias', 'assigned_roles')) {
            Schema::table('dpias', function (Blueprint $table) {
                $table->dropColumn('assigned_roles');
            });
        }
    }
};
