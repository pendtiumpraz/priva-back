<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-org CRM credentials. Secret fields stored encrypted at rest via the
 * EncryptedString cast on the model.
 *
 * One row per (org_id, provider). provider IN {hubspot, mailchimp, salesforce, webhook}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('provider', 20);                // hubspot|mailchimp|salesforce|webhook
            $table->string('label', 120)->nullable();      // friendly tag e.g. "HubSpot Production"
            $table->boolean('is_active')->default(true);

            // Generic secret holders — semantics depend on provider.
            $table->text('api_key')->nullable();           // HubSpot Private App token, Mailchimp dc-key
            $table->text('api_secret')->nullable();        // optional secondary secret
            $table->string('endpoint_url', 500)->nullable(); // Webhook URL, Salesforce instance URL
            $table->string('list_or_object_ref', 200)->nullable(); // Mailchimp list_id, Salesforce object name
            $table->json('extra_config')->nullable();      // free-form (region, dc, mapping rules)

            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('rotated_at')->nullable();
            $table->softDeletesTz();
            $table->timestamps();

            $table->unique(['org_id', 'provider', 'label']);
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_credentials');
    }
};
