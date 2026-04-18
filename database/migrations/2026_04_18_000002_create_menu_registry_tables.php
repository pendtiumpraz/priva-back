<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu Registry — 2-tier visibility system.
 *
 *   Layer 1 (Root): role_menu_whitelist → globally ALLOW a menu for a role
 *   Layer 2 (Tenant admin): tenant_menu_override → within allowed set, hide
 *                           the menu for a given org+role
 *
 * menu_items is the seeded source of truth; every menu in the sidebar
 * corresponds to one row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_items')) {
            Schema::create('menu_items', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('menu_key', 64)->unique(); // e.g. 'ropa', 'api-hub'
                $table->string('label');
                $table->string('href');
                $table->string('icon', 64)->nullable();   // Lucide icon name (client resolves)
                $table->string('section', 64)->nullable();
                $table->integer('sort_order')->default(0);
                $table->boolean('hideable')->default(true); // if false, tenant cannot hide
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('role_menu_whitelist')) {
            Schema::create('role_menu_whitelist', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('menu_id');
                $table->string('role', 32); // root | superadmin | admin | dpo | maker | viewer
                $table->boolean('is_allowed')->default(true);
                $table->timestamps();

                $table->foreign('menu_id')->references('id')->on('menu_items')->onDelete('cascade');
                $table->unique(['menu_id', 'role'], 'rmw_menu_role_unique');
            });
        }

        if (!Schema::hasTable('tenant_menu_override')) {
            Schema::create('tenant_menu_override', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->uuid('menu_id');
                $table->string('role', 32);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();

                $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
                $table->foreign('menu_id')->references('id')->on('menu_items')->onDelete('cascade');
                $table->unique(['org_id', 'menu_id', 'role'], 'tmo_org_menu_role_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_menu_override');
        Schema::dropIfExists('role_menu_whitelist');
        Schema::dropIfExists('menu_items');
    }
};
