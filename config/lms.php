<?php

return [
    /*
    |--------------------------------------------------------------------------
    | LMS Foundation Config
    |--------------------------------------------------------------------------
    |
    | Toggle the LMS subsystem on/off platform-wide. When `enabled` is false,
    | the EnsureLmsEntitled middleware short-circuits with 503 regardless of
    | per-org entitlements. Used during foundation rollout.
    |
    */

    'enabled' => env('LMS_ENABLED', false),

    'certificate' => [
        'verify_route_name' => 'lms.certificate.verify',
    ],
];
