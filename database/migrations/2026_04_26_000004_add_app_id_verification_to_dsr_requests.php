<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add app_id + verification + completion fields ke dsr_requests.
 *
 * Sekarang DSR ter-link ke specific app klien (via app_id) dan punya
 * verification flow (OTP email/SMS). Backward-compat: app_id nullable
 * untuk DSR legacy yang dibuat sebelum dsr_apps register.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('dsr_requests')) return;

        $cols = [
            'app_id'                          => fn(Blueprint $t) => $t->uuid('app_id')->nullable()->after('org_id'),
            'verification_token'              => fn(Blueprint $t) => $t->string('verification_token', 64)->nullable()->after('verification_status'),
            'verification_expires_at'         => fn(Blueprint $t) => $t->timestamp('verification_expires_at')->nullable()->after('verification_token'),
            'verification_method'             => fn(Blueprint $t) => $t->string('verification_method', 20)->default('email_otp')->after('verification_expires_at'),
            'verified_at'                     => fn(Blueprint $t) => $t->timestamp('verified_at')->nullable()->after('verification_method'),
            'completion_certificate_doc_id'   => fn(Blueprint $t) => $t->uuid('completion_certificate_doc_id')->nullable()->after('closed_at'),
            'subject_data'                    => fn(Blueprint $t) => $t->json('subject_data')->nullable()->after('description'),
            'closed_reason'                   => fn(Blueprint $t) => $t->string('closed_reason', 50)->nullable()->after('closed_at'),
        ];

        foreach ($cols as $name => $fn) {
            if (Schema::hasColumn('dsr_requests', $name)) continue;
            try {
                Schema::table('dsr_requests', function (Blueprint $t) use ($fn) { $fn($t); });
            } catch (\Illuminate\Database\QueryException $e) {
                $code = $e->errorInfo[1] ?? null;
                if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) continue;
                throw $e;
            }
        }

        // Indexes untuk query performance (SLA cron, app filter)
        try {
            Schema::table('dsr_requests', function (Blueprint $t) {
                $t->index(['app_id', 'status'], 'dsr_requests_app_status_idx');
            });
        } catch (\Throwable $e) { /* index already exists */ }

        try {
            Schema::table('dsr_requests', function (Blueprint $t) {
                $t->index(['deadline_at', 'status'], 'dsr_requests_deadline_status_idx');
            });
        } catch (\Throwable $e) { /* index already exists */ }
    }

    public function down(): void
    {
        if (!Schema::hasTable('dsr_requests')) return;

        $cols = [
            'app_id', 'verification_token', 'verification_expires_at', 'verification_method',
            'verified_at', 'completion_certificate_doc_id', 'subject_data', 'closed_reason',
        ];

        foreach ($cols as $name) {
            if (!Schema::hasColumn('dsr_requests', $name)) continue;
            try {
                Schema::table('dsr_requests', function (Blueprint $t) use ($name) { $t->dropColumn($name); });
            } catch (\Throwable $e) { /* ignore */ }
        }
    }
};
