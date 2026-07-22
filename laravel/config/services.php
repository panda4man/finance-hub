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

    'simplefin' => [
        'timeout' => (int) env('SIMPLEFIN_TIMEOUT', 30),
        'connect_timeout' => (int) env('SIMPLEFIN_CONNECT_TIMEOUT', 10),
        // Retry logic lives at the SyncService level (see config/finance.php's
        // retry_backoff_ms), not baked into the HTTP client — this stays 0 so
        // the two retry layers don't stack.
        'retries' => (int) env('SIMPLEFIN_RETRIES', 0),
    ],

];
