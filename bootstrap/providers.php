<?php

use App\Providers\AppServiceProvider;
use App\Providers\SettingsServiceProvider;

return [
    AppServiceProvider::class,
    // Registered AFTER AppServiceProvider so the landlord connection alias is
    // already wired before we read from system_settings.
    SettingsServiceProvider::class,
];
