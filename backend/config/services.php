<?php

return [

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bkash' => [
        'webhook_secret' => env('BKASH_WEBHOOK_SECRET', env('BKASH_APP_SECRET', 'local-test-bkash-secret-123456')),
        'app_secret'     => env('BKASH_APP_SECRET', 'sandbox_bkash_secret_2026'),
    ],

    'nagad' => [
        'webhook_secret' => env('NAGAD_WEBHOOK_SECRET', env('NAGAD_MERCHANT_KEY', 'local-test-nagad-secret-123456')),
        'merchant_key'   => env('NAGAD_MERCHANT_KEY', 'sandbox_nagad_merchant_2026'),
    ],

];
