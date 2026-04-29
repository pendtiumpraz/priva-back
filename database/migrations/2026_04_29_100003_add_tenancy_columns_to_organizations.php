<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add tenancy columns to organizations.
 *
 * `tenant_db_provider` is the discriminator that controls how
 * TenantDatabaseService resolves a tenant's connection at request time:
 *   - 'shared' → use landlord DB (Tier 1; tenant_db_config ignored)
 *   - 'pool'   → use tenant_db_config; db_pool_id references the source pool
 *   - 'byodb'  → use tenant_db_config; db_pool_id NULL (client-managed)
 *
 * `tenant_db_state` is the lifecycle the tenant is in. Routing only
 * connects to the dedicated DB once state == 'isolated'. Mid-migration
 * states ('provisioning', 'migrating') keep the tenant on the shared DB
 * until cutover so no traffic lands in a half-built database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Pool assignment (nullable — shared and BYODB tenants don't reference a pool)
            $table->uuid('db_pool_id')->nullable()->after('storage_config');
            $table->uuid('storage_pool_id')->nullable()->after('db_pool_id');

            // Discriminator + lifecycle
            $table->string('tenant_db_provider', 20)->default('shared')->after('storage_pool_id');
            $table->string('tenant_db_state', 20)->default('shared')->after('tenant_db_provider');

            // Per-tenant connection config — encrypted JSON
            // (different from db_pool_id: pool stores provisioner creds,
            //  this stores THIS tenant's own DB credentials produced at provisioning)
            $table->text('tenant_db_config')->nullable()->after('tenant_db_state');

            // Lifecycle timestamps
            $table->timestamp('tenant_db_provisioned_at')->nullable()->after('tenant_db_config');
            $table->timestamp('tenant_db_isolated_at')->nullable()->after('tenant_db_provisioned_at');
            $table->text('tenant_db_error')->nullable()->after('tenant_db_isolated_at');

            $table->index('tenant_db_state');
            $table->index('tenant_db_provider');
            $table->index('db_pool_id');
            $table->index('storage_pool_id');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['tenant_db_state']);
            $table->dropIndex(['tenant_db_provider']);
            $table->dropIndex(['db_pool_id']);
            $table->dropIndex(['storage_pool_id']);

            $table->dropColumn([
                'db_pool_id',
                'storage_pool_id',
                'tenant_db_provider',
                'tenant_db_state',
                'tenant_db_config',
                'tenant_db_provisioned_at',
                'tenant_db_isolated_at',
                'tenant_db_error',
            ]);
        });
    }
};
