<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'license' => [
        'public_key' => env('LICENSE_PUBLIC_KEY'),
    ],

    /*
    | Mux Video — LMS lesson video with signed playback (M1).
    | token_id/secret authenticate the management API (asset ingest).
    | signing_key_id + signing_key_private_key mint RS256 playback JWTs.
    | Empty config => Mux disabled (YouTube still works).
    */
    'mux' => [
        'token_id' => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
        'signing_key_id' => env('MUX_SIGNING_KEY_ID'),
        'signing_key_private_key' => env('MUX_SIGNING_KEY_PRIVATE_KEY'),
        'playback_token_ttl' => (int) env('MUX_PLAYBACK_TOKEN_TTL', 21600),
        'default_playback_policy' => env('MUX_DEFAULT_PLAYBACK_POLICY', 'signed'),
    ],

];
