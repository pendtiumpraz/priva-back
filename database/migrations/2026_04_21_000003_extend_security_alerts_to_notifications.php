<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `security_alerts` from a security-scan output into a general
 * notification record. The name stays for backwards compat, but
 * business-logic-wise this becomes the `notifications` table.
 *
 * New dimensions:
 *   - kind         → alert | warning | info  (intent)
 *   - severity     → critical | high | medium | low  (existing)
 *   - recipient_id / recipient_role → user-targeted vs role-broadcast
 *   - read_at      → per-recipient read state
 *   - priority     → sortable 0-100 int for critical-first ordering
 *   - action_url   → deep-link or wa.me URL
 *
 * Also introduces notification_preferences for per-user toggles.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->string('kind', 16)->default('alert')->after('severity'); // alert|warning|info
            $table->uuid('recipient_id')->nullable()->after('record_id')->index();
            $table->string('recipient_role', 32)->nullable()->after('recipient_id')->index();
            $table->timestamp('read_at')->nullable()->after('recipient_role');
            $table->unsignedSmallInteger('priority')->default(50)->after('read_at');
            $table->string('action_url', 512)->nullable()->after('priority');
            $table->string('type', 64)->nullable()->after('rule_code'); // granular event code, e.g. 'ropa.assigned'
        });

        // Backfill existing rows — everything in the table today was a
        // security scan result, so tag it as kind='alert'.
        DB::table('security_alerts')->update(['kind' => 'alert']);

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('kind', 16);      // alert|warning|info
            $table->string('module', 40);    // ropa|dpia|dsr|breach|license|system|... or '*' for wildcard
            $table->string('channel', 16);   // in_app|email|wa|push
            $table->boolean('enabled')->default(true);
            $table->string('digest', 16)->default('instant'); // instant|hourly|daily|off
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'module', 'channel']);
        });

        Schema::create('notification_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable()->index();
            $table->string('record_type', 40)->nullable();
            $table->uuid('record_id')->nullable();
            $table->string('rule_key', 64);  // e.g. 'license.expiring.7d', 'ropa.review.90d'
            $table->timestamp('next_fire_at');
            $table->timestamp('last_fired_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['enabled', 'next_fire_at']);
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_schedules');
        Schema::dropIfExists('notification_preferences');
        Schema::table('security_alerts', function (Blueprint $table) {
            $table->dropColumn(['kind', 'recipient_id', 'recipient_role', 'read_at', 'priority', 'action_url', 'type']);
        });
    }
};
