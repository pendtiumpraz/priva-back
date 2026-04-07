<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partner API Keys
        Schema::create('partner_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name'); // e.g. "PDP Privasimu Integration"
            $table->string('key_prefix', 12); // visible part: "pk_live_xxxx"
            $table->string('key_hash'); // bcrypt hash of full key
            $table->json('permissions')->nullable(); // ['breach.read', 'breach.write', etc]
            $table->json('allowed_ips')->nullable(); // IP whitelist
            $table->string('environment')->default('live'); // live or test
            $table->bigInteger('rate_limit_per_minute')->default(60);
            $table->timestamp('last_used_at')->nullable();
            $table->bigInteger('total_requests')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['key_prefix']);
            $table->index(['org_id', 'is_active']);
        });

        // API Request Logs
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('api_key_id');
            $table->uuid('org_id');
            $table->string('method', 10); // GET, POST, PUT, DELETE
            $table->string('endpoint', 500);
            $table->integer('status_code');
            $table->integer('response_time_ms')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at');

            $table->foreign('api_key_id')->references('id')->on('partner_api_keys')->onDelete('cascade');
            $table->index(['api_key_id', 'created_at']);
            $table->index(['org_id', 'created_at']);
        });

        // Webhooks
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->string('url', 1000);
            $table->string('secret', 255)->nullable(); // HMAC signing secret
            $table->json('events'); // ['breach.created', 'breach.status_changed', etc]
            $table->boolean('is_active')->default(true);
            $table->integer('retry_count')->default(3);
            $table->integer('timeout_seconds')->default(10);
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('total_deliveries')->default(0);
            $table->integer('failed_deliveries')->default(0);
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('partner_api_keys');
    }
};
