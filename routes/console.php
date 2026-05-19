<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('privasimu:scan-scheduled-systems')->daily();
\Illuminate\Support\Facades\Schedule::command('entitlements:cleanup-expired')->dailyAt('02:00');
\Illuminate\Support\Facades\Schedule::command('tenants:cleanup-archived')->dailyAt('03:00');
\Illuminate\Support\Facades\Schedule::command('consent:recount')->everyFiveMinutes()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('notifications:scan-license-expiry')->dailyAt('06:00');
\Illuminate\Support\Facades\Schedule::command('notifications:scan-all')->dailyAt('07:00');
\Illuminate\Support\Facades\Schedule::command('notifications:digest daily')->dailyAt('08:00');
\Illuminate\Support\Facades\Schedule::command('notifications:digest weekly')->weeklyOn(1, '08:00'); // Monday 08:00
\Illuminate\Support\Facades\Schedule::command('dsr:scan-sla')->hourly()->withoutOverlapping();
\Illuminate\Support\Facades\Schedule::command('consent:prune-cookie-logs')->dailyAt('02:30');
// Phase 3a — daily privacy posture snapshot per org. Drives trend chart.
\Illuminate\Support\Facades\Schedule::command('privasimu:posture-snapshot')->dailyAt('05:00')->withoutOverlapping();
// Audit log retention — prune entries lebih lama dari security.audit_log_retention_days.
// No-op kalau setting = 0 (keep forever, default).
\Illuminate\Support\Facades\Schedule::command('audit-logs:prune')->dailyAt('04:00');

// Audit log hash-chain verify — daily integrity check.
// Kalau chain disabled, command no-op (langsung exit). Kalau enabled +
// chain rusak, log warning level (akan muncul di alert dashboard).
\Illuminate\Support\Facades\Schedule::command('audit-logs:chain verify')->dailyAt('04:30');

// Sanctum — prune expired personal access tokens (24+ jam past expiry).
// SanctumTokenRefresh tidak delete old token saat rotate (cegah race condition
// dengan in-flight parallel requests), jadi cleanup happens here. Built-in
// Sanctum command pakai sanctum.expiration config (10080 menit = 7 hari).
\Illuminate\Support\Facades\Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('05:30');
