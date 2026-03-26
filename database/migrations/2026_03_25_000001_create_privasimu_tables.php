<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        // =============================================
        // Organizations (Multi-tenant)
        // =============================================
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('industry')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('privacy_policy_url')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->jsonb('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // =============================================
        // Alter Users table — add org + role
        // =============================================
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('org_id')->nullable()->after('id');
            $table->string('role')->default('viewer')->after('email'); // admin, dpo, maker, viewer
            $table->string('phone')->nullable()->after('role');
            $table->string('avatar_url')->nullable()->after('phone');
            $table->string('position')->nullable()->after('avatar_url');
            $table->boolean('is_active')->default(true)->after('position');
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // =============================================
        // Gap Assessments
        // =============================================
        Schema::create('gap_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('version');
            $table->decimal('score', 5, 2)->default(0);
            $table->decimal('progress', 5, 2)->default(0);
            $table->string('compliance_level')->default('low'); // low, medium, high
            $table->jsonb('answers')->nullable();
            $table->jsonb('summary')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // ROPA (Record of Processing Activity)
        // =============================================
        Schema::create('ropas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('registration_number')->unique();
            $table->string('processing_activity');
            $table->string('division')->nullable();
            $table->string('assign_group')->nullable();
            $table->string('risk_level')->default('low'); // low, medium, high
            $table->string('status')->default('draft'); // draft, waiting, revision, in_progress, approved
            $table->text('purpose')->nullable();
            $table->text('legal_basis')->nullable();
            $table->jsonb('data_categories')->nullable();
            $table->jsonb('data_subjects')->nullable();
            $table->jsonb('recipients')->nullable();
            $table->string('retention_period')->nullable();
            $table->date('retention_due_date')->nullable();
            $table->text('security_measures')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // DPIA (Data Protection Impact Assessment)
        // =============================================
        Schema::create('dpias', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('registration_number')->unique();
            $table->uuid('ropa_id')->nullable();
            $table->string('risk_level')->default('low');
            $table->string('status')->default('draft'); // draft, waiting, revision, in_progress, approved
            $table->text('description')->nullable();
            $table->jsonb('risk_assessment')->nullable();
            $table->jsonb('mitigation_measures')->nullable();
            $table->uuid('approver_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('ropa_id')->references('id')->on('ropas')->onDelete('set null');
            $table->foreign('approver_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // Data Discovery — Information Systems
        // =============================================
        Schema::create('information_systems', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->uuid('owner_id')->nullable();
            $table->string('source_type')->nullable(); // mysql, postgresql, mongodb, api, file
            $table->jsonb('connection_config')->nullable(); // encrypted
            $table->string('scanning_status')->default('not_started'); // not_started, in_progress, done, failed
            $table->decimal('scanning_progress', 5, 2)->default(0);
            $table->integer('pdp_alert_count')->default(0);
            $table->integer('pii_alert_count')->default(0);
            $table->jsonb('scan_results')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // DSR (Data Subject Request)
        // =============================================
        Schema::create('dsr_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('request_id')->unique(); // DSR-2026-001
            $table->string('request_type'); // access, rectification, erasure, portability, restriction, objection
            $table->string('requester_name');
            $table->string('requester_email');
            $table->string('requester_phone')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('new'); // new, new_reply, replied, rejected, closed
            $table->string('verification_status')->default('pending'); // pending, verified, failed
            $table->text('response')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('deadline_at')->nullable(); // 3-day alarm
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // DSR Settings
        // =============================================
        Schema::create('dsr_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->unique();
            $table->string('mailer')->default('smtp');
            $table->string('mail_host')->nullable();
            $table->integer('mail_port')->nullable();
            $table->string('mail_username')->nullable();
            $table->text('mail_password')->nullable(); // encrypted
            $table->string('mail_encryption')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('otp_method')->default('email'); // email, sms
            $table->string('embed_token')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });

        // =============================================
        // Consent Management — Collection Points
        // =============================================
        Schema::create('consent_collection_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('collection_id')->unique(); // numeric ID
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('redirect_url')->nullable();
            $table->jsonb('settings')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // Consent Management — Consent Items (Templates)
        // =============================================
        Schema::create('consent_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('collection_point_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('full_text')->nullable(); // full legal text
            $table->string('version')->default('1.0');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('collection_point_id')->references('id')->on('consent_collection_points')->onDelete('cascade');
        });

        // =============================================
        // Consent Management — User Consents (Records)
        // =============================================
        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('consent_item_id');
            $table->uuid('collection_point_id');
            $table->string('subject_identifier'); // email, phone, user_id
            $table->string('subject_name')->nullable();
            $table->string('channel')->default('digital'); // digital, cs, third_party
            $table->boolean('is_granted')->default(false);
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->text('proof')->nullable(); // signature data, recording ref
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->uuid('recorded_by')->nullable(); // for CS channel
            $table->timestamps();

            $table->foreign('consent_item_id')->references('id')->on('consent_items')->onDelete('cascade');
            $table->foreign('collection_point_id')->references('id')->on('consent_collection_points')->onDelete('cascade');
            $table->index(['subject_identifier', 'consent_item_id']);
        });

        // =============================================
        // Data Breach Management — Incidents
        // =============================================
        Schema::create('breach_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('incident_code')->unique(); // BRC-2026-001 or SIM-2026-001
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity')->default('low'); // low, medium, high, critical
            $table->string('source')->default('manual'); // manual, siem, whistleblower, automated
            $table->string('status')->default('detected'); // detected, assessing, containment, notification, closed
            $table->boolean('is_simulation')->default(false);
            $table->jsonb('affected_data_types')->nullable();
            $table->integer('affected_subjects_count')->default(0);
            $table->text('root_cause')->nullable();
            $table->text('containment_actions')->nullable();
            $table->jsonb('containment_checklist')->nullable();
            $table->text('remediation_plan')->nullable();
            $table->boolean('notification_required')->default(false);
            $table->timestamp('notification_deadline')->nullable(); // 72 hours
            $table->timestamp('notified_komdigi_at')->nullable();
            $table->timestamp('notified_subjects_at')->nullable();
            $table->jsonb('notification_template')->nullable();
            $table->uuid('detected_by')->nullable();
            $table->uuid('incident_commander')->nullable();
            $table->uuid('dpo_id')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamp('contained_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->jsonb('timeline_log')->nullable(); // full incident timeline
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('detected_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('incident_commander')->references('id')->on('users')->onDelete('set null');
            $table->foreign('dpo_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // Breach Simulations (Fire Drill)
        // =============================================
        Schema::create('breach_simulations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('incident_id')->nullable();
            $table->string('scenario_type'); // easy, medium, hard, critical, random, custom
            $table->string('scenario_title');
            $table->text('scenario_description')->nullable();
            $table->jsonb('scenario_data')->nullable();
            $table->string('timer_mode')->default('accelerated_2h'); // realtime, accelerated_2h, accelerated_30m, no_timer
            $table->decimal('timer_ratio', 8, 2)->default(36.0);
            $table->jsonb('participants')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('overall_score')->nullable();
            $table->jsonb('score_breakdown')->nullable();
            $table->jsonb('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->string('status')->default('scheduled'); // scheduled, running, completed, cancelled
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('incident_id')->references('id')->on('breach_incidents')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        // =============================================
        // Simulation Responses (per participant)
        // =============================================
        Schema::create('simulation_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('simulation_id');
            $table->uuid('user_id');
            $table->string('role'); // dpo, ciso, it_security, legal
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('first_action_at')->nullable();
            $table->jsonb('actions_log')->nullable();
            $table->integer('response_time_seconds')->nullable();
            $table->integer('individual_score')->nullable();
            $table->timestamps();

            $table->foreign('simulation_id')->references('id')->on('breach_simulations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // =============================================
        // Notifications
        // =============================================
        Schema::create('notifications_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('user_id')->nullable(); // target user
            $table->string('type'); // breach_alert, dsr_deadline, consent_update, simulation
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('channel')->default('in_app'); // in_app, email, sms, whatsapp
            $table->boolean('is_read')->default(false);
            $table->jsonb('data')->nullable(); // metadata
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // =============================================
        // Audit Log
        // =============================================
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('user_id')->nullable();
            $table->string('action'); // create, update, delete, login, export, etc
            $table->string('module'); // ropa, dpia, breach, consent, etc
            $table->string('entity_type')->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['org_id', 'module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notifications_log');
        Schema::dropIfExists('simulation_responses');
        Schema::dropIfExists('breach_simulations');
        Schema::dropIfExists('breach_incidents');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('consent_items');
        Schema::dropIfExists('consent_collection_points');
        Schema::dropIfExists('dsr_settings');
        Schema::dropIfExists('dsr_requests');
        Schema::dropIfExists('information_systems');
        Schema::dropIfExists('dpias');
        Schema::dropIfExists('ropas');
        Schema::dropIfExists('gap_assessments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropColumn(['org_id', 'role', 'phone', 'avatar_url', 'position', 'is_active', 'deleted_at']);
        });

        Schema::dropIfExists('organizations');
    }
};
