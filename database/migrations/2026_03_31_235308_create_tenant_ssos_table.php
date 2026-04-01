<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_ssos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('provider'); // google, azure, keycloak
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('tenant_id')->nullable(); // For Azure
            $table->string('custom_domain')->nullable(); // For Keycloak/Custom OIDC
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_ssos');
    }
};
