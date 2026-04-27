<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cookie Logs — anonymous visitor consent capture from public site / homepage.
 *
 * Distinct from consent_logs (which after extension is reserved for
 * IDENTIFIABLE app/register-form consent with email/name/phone). This table
 * stores anonymous-only signals: visitor_id, IP-derived country, parsed
 * browser/OS, and the choices they made on the cookie banner.
 *
 * Retention: auto-prune after 90 days via PruneCookieLogsCommand
 * (config('privasimu.cookie_log_retention_days')).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookie_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('collection_id')->nullable();

            // Anonymous visitor identifiers
            $table->string('visitor_id', 80);                  // localStorage UUID v4
            $table->string('session_id', 80)->nullable();      // per-page-load nonce

            // Network signals
            $table->string('ip_address', 45)->nullable();
            $table->char('ip_country', 2)->nullable();         // ISO-3166-1 alpha-2
            $table->string('ip_city', 120)->nullable();

            // Client signals (parsed from User-Agent server-side)
            $table->string('user_agent', 500)->nullable();
            $table->string('browser_name', 40)->nullable();
            $table->string('browser_version', 20)->nullable();
            $table->string('os_name', 40)->nullable();
            $table->string('device_type', 20)->nullable();     // desktop|mobile|tablet|bot
            $table->string('referrer', 500)->nullable();
            $table->string('page_url', 500)->nullable();

            // Captured choices
            $table->json('choices');                           // {necessary,analytics,marketing,preferences}
            $table->string('policy_version', 20)->nullable();

            $table->timestampTz('captured_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable()->index();

            $table->softDeletesTz();
            $table->timestamps();

            $table->index(['org_id', 'visitor_id']);
            $table->index(['collection_id', 'captured_at']);

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('collection_id')->references('id')->on('consent_collection_points')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_logs');
    }
};
