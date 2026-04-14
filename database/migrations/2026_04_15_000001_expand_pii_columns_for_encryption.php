<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand PII columns from varchar(255) to TEXT to accommodate AES-256-CBC ciphertext.
 * Encrypted values are ~4x larger than plaintext (base64 JSON with iv+value+mac).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Users
        Schema::table('users', function (Blueprint $table) {
            $table->text('name')->change();
            $table->text('phone')->nullable()->change();
        });

        // Organizations
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('phone')->nullable()->change();
            $table->text('address')->nullable()->change();
        });

        // DSR Requests
        Schema::table('dsr_requests', function (Blueprint $table) {
            $table->text('requester_name')->nullable()->change();
            $table->text('requester_email')->nullable()->change();
            $table->text('requester_phone')->nullable()->change();
            // description is likely already text
        });

        // Consent Records
        Schema::table('consent_records', function (Blueprint $table) {
            $table->text('subject_identifier')->nullable()->change();
            $table->text('subject_name')->nullable()->change();
            $table->text('ip_address')->nullable()->change();
        });

        // Breach Incidents
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->text('pic_name')->nullable()->change();
            // description is likely already text
        });

        // Vendors
        Schema::table('vendors', function (Blueprint $table) {
            $table->text('contact_name')->nullable()->change();
            $table->text('contact_email')->nullable()->change();
        });

        // Organization Apps (credentials)
        Schema::table('organization_apps', function (Blueprint $table) {
            $table->text('staging_db_username')->nullable()->change();
            $table->text('staging_db_password')->nullable()->change();
            $table->text('prod_db_username')->nullable()->change();
            $table->text('prod_db_password')->nullable()->change();
        });

        // Tenant SSO
        Schema::table('tenant_ssos', function (Blueprint $table) {
            $table->text('client_secret')->nullable()->change();
        });

        // Webhooks
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('secret')->nullable()->change();
        });

        // License Activations
        Schema::table('license_activations', function (Blueprint $table) {
            $table->text('ip_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revert would lose data if ciphertext > 255 chars, so we don't revert
    }
};
