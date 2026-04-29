<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pool Registry — Database Pools.
 *
 * Each row = one Postgres/MySQL cluster Privasimu can provision tenant
 * databases into. Managed by root/superadmin via UI; rows are encrypted
 * at column level (provisioner_password, ca_cert) using Laravel's Crypt.
 *
 * Tenant assignment lives on `organizations.db_pool_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
            $table->text('description')->nullable();

            // Engine + connection
            $table->string('engine', 20);                    // 'pgsql' | 'mysql'
            $table->string('host', 255);
            $table->integer('port')->default(5432);

            // Provisioner credentials (encrypted at rest)
            $table->string('provisioner_user', 64);
            $table->text('provisioner_password');            // Crypt::encryptString
            $table->string('sslmode', 20)->default('require');
            $table->text('ca_cert')->nullable();             // Crypt::encryptString

            // Capacity & status
            $table->string('region', 40)->nullable();        // 'ap-southeast-3', 'on-prem-jakarta'
            $table->string('status', 20)->default('active'); // 'active' | 'disabled' | 'draining'
            $table->integer('max_tenants')->nullable();      // soft cap; null = unlimited
            $table->integer('current_tenants_count')->default(0);

            $table->json('metadata')->nullable();            // tier label, AWS account, dll

            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('region');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_pools');
    }
};
