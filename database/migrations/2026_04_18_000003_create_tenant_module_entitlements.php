<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-level module entitlement (Layer 0 in the 3-layer visibility model).
 *
 * Set by ROOT only. Says which menu/module a given tenant is licensed to use.
 * Independent of role_menu_whitelist (which constrains by role) and
 * tenant_menu_override (which lets tenant admin hide within their allowed set).
 *
 * Resolution:
 *   final_visible = entitled ∧ role_whitelisted ∧ not_tenant_hidden
 *
 * If no row exists for (org_id, menu_id): treat as entitled=true for backward
 * compatibility. Root explicitly revokes by setting is_entitled=false.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_module_entitlements')) return;

        Schema::create('tenant_module_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('menu_id');
            $table->boolean('is_entitled')->default(true);
            $table->date('valid_until')->nullable(); // optional expiry
            $table->text('notes')->nullable();       // root's note (e.g. "Beli putus on-prem")
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('menu_id')->references('id')->on('menu_items')->onDelete('cascade');
            $table->unique(['org_id', 'menu_id'], 'tme_org_menu_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_module_entitlements');
    }
};
