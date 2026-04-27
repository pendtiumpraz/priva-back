<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend consent_logs for IDENTIFIABLE consent (app_consent kind).
 *
 * After this migration:
 *   - cookie_banner kind: writes to cookie_logs (separate table)
 *   - app_consent kind:    writes to consent_logs (this table) with
 *                          email/name/phone + parsed UA + country
 *
 * The legacy `user_identifier` column is preserved but soft-deprecated.
 * It will continue to be populated for backwards-compat (mirror of email)
 * until embed-script v1 sunsets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_logs', function (Blueprint $table) {
            // Identifiable subject fields
            if (! Schema::hasColumn('consent_logs', 'email')) {
                $table->string('email', 200)->nullable()->after('user_identifier');
                $table->index('email');
            }
            if (! Schema::hasColumn('consent_logs', 'name')) {
                $table->string('name', 200)->nullable()->after('email');
            }
            if (! Schema::hasColumn('consent_logs', 'phone')) {
                $table->string('phone', 50)->nullable()->after('name');
            }
            if (! Schema::hasColumn('consent_logs', 'user_id')) {
                $table->uuid('user_id')->nullable()->after('phone');
                $table->index('user_id');
            }
            if (! Schema::hasColumn('consent_logs', 'external_user_ref')) {
                $table->string('external_user_ref', 120)->nullable()->after('user_id');
                $table->index('external_user_ref');
            }

            // Denormalized purpose keys for fast filter (e.g. ['marketing','newsletter']).
            // Mirrors the keys from consented_items where value=true.
            if (! Schema::hasColumn('consent_logs', 'purpose_keys')) {
                $table->json('purpose_keys')->nullable()->after('consented_items');
            }

            // Source form context: register | checkout | newsletter | embed
            if (! Schema::hasColumn('consent_logs', 'source_form')) {
                $table->string('source_form', 40)->nullable()->after('policy_version');
                $table->index('source_form');
            }

            // Network/client signals (parsed)
            if (! Schema::hasColumn('consent_logs', 'ip_country')) {
                $table->char('ip_country', 2)->nullable()->after('ip_address');
            }
            if (! Schema::hasColumn('consent_logs', 'browser_name')) {
                $table->string('browser_name', 40)->nullable()->after('user_agent');
                $table->string('browser_version', 20)->nullable()->after('browser_name');
                $table->string('os_name', 40)->nullable()->after('browser_version');
                $table->string('device_type', 20)->nullable()->after('os_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('consent_logs', function (Blueprint $table) {
            foreach ([
                'email', 'name', 'phone', 'user_id', 'external_user_ref',
                'purpose_keys', 'source_form',
                'ip_country', 'browser_name', 'browser_version', 'os_name', 'device_type',
            ] as $col) {
                if (Schema::hasColumn('consent_logs', $col)) {
                    try { $table->dropColumn($col); } catch (\Throwable $e) {}
                }
            }
        });
    }
};
