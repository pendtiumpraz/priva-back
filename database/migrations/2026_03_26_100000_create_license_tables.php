<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('license_key')->unique(); // PRIV-XXXX-XXXX-XXXX-XXXX
            $table->string('package_type'); // basic, ai, ai_agent
            $table->string('license_type'); // perpetual (beli putus), saas (subscription)
            $table->string('status')->default('active'); // active, expired, revoked, suspended
            $table->uuid('org_id')->nullable(); // assigned to which org
            $table->string('org_name')->nullable(); // for display
            $table->json('domain_whitelist')->nullable(); // ["app.example.com", "localhost"]
            $table->json('ip_log')->nullable(); // [{"ip": "1.2.3.4", "domain": "x.com", "at": "..."}]
            $table->integer('max_activations')->default(1);
            $table->integer('activation_count')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // null = perpetual/never expires
            $table->integer('duration_days')->nullable(); // for SaaS: 30, 90, 365
            $table->json('features')->nullable(); // granular feature flags
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('set null');
            $table->index('license_key');
            $table->index('org_id');
        });

        // License activation logs
        Schema::create('license_activations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('license_id');
            $table->string('ip_address');
            $table->string('domain')->nullable();
            $table->string('server_hostname')->nullable();
            $table->string('action'); // activate, verify, rejected
            $table->text('details')->nullable();
            $table->timestamps();

            $table->foreign('license_id')->references('id')->on('licenses')->onDelete('cascade');
            $table->index(['license_id', 'created_at']);
        });

        // Pricing configuration
        Schema::create('pricing_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name'); // Basic, Pro AI, Enterprise AI Agent
            $table->string('package_type')->unique(); // basic, ai, ai_agent
            $table->decimal('price_perpetual', 15, 2)->default(0); // one-time
            $table->decimal('price_monthly', 15, 2)->default(0);
            $table->decimal('price_yearly', 15, 2)->default(0);
            $table->json('features')->nullable(); // list of features included
            $table->boolean('is_popular')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('pricing_plans');
        Schema::dropIfExists('licenses');
    }
};
