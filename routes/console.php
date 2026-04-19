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
