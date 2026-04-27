<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cookie / Consent log retention
    |--------------------------------------------------------------------------
    |
    | cookie_log_retention_days: anonymous cookie banner logs auto-pruned
    |   after this many days by `php artisan consent:prune-cookie-logs`.
    |   90 days is the default (UU PDP minimization principle).
    |
    | consent_log_retention_days: identifiable consent logs (register/checkout
    |   forms). Longer per audit obligation. 5 years (1825 days) default.
    */
    'cookie_log_retention_days' => env('COOKIE_LOG_RETENTION_DAYS', 90),
    'consent_log_retention_days' => env('CONSENT_LOG_RETENTION_DAYS', 1825),

    /*
    |--------------------------------------------------------------------------
    | GeoIP
    |--------------------------------------------------------------------------
    | If you ship the GeoLite2-City.mmdb file under
    | storage/app/private/geoip/GeoLite2-City.mmdb and `composer require
    | geoip2/geoip2`, IpGeoResolver will auto-pick it up. Otherwise country
    | resolution returns null (still safe).
    */
    'geoip_enabled' => env('GEOIP_ENABLED', true),
];
