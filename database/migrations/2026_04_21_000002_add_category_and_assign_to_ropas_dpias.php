<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends RoPA + DPIA with naming-category link, optional custom number,
 * and assign-group/assignees. These back the new Step-0 wizard screen
 * and the locked-after-submit assign rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->uuid('category_id')->nullable()->after('regulation_code')->index();
            $table->string('custom_number', 40)->nullable()->after('category_id');
            // assign_group column already exists as a free-text label; keep it.
            // Add explicit assignees[] for per-user assignment.
            $table->json('assignees')->nullable()->after('assign_group');
        });

        Schema::table('dpias', function (Blueprint $table) {
            $table->uuid('category_id')->nullable()->after('regulation_code')->index();
            $table->string('custom_number', 40)->nullable()->after('category_id');
            $table->string('assign_group')->nullable()->after('custom_number');
            $table->json('assignees')->nullable()->after('assign_group');
        });
    }

    public function down(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->dropColumn(['category_id', 'custom_number', 'assignees']);
        });
        Schema::table('dpias', function (Blueprint $table) {
            $table->dropColumn(['category_id', 'custom_number', 'assign_group', 'assignees']);
        });
    }
};
