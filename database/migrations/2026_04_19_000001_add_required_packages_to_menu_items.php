<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package-gate a menu item. required_packages = JSON array of package_type
 * values (basic | pro | ai | ai_agent | perpetual). If empty/null → available
 * to all licence tiers. Evaluated at Layer 0.5 (between entitlement and
 * whitelist) so AI-only features vanish for basic-plan tenants automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_items')) return;
        if (Schema::hasColumn('menu_items', 'required_packages')) return;

        Schema::table('menu_items', function (Blueprint $table) {
            $table->json('required_packages')->nullable()->after('hideable');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_items') && Schema::hasColumn('menu_items', 'required_packages')) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->dropColumn('required_packages');
            });
        }
    }
};
