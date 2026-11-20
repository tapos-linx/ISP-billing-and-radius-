<?php

declare(strict_types=1);

$resolveSecret = static function (string $name, ?string $envVal = null): ?string {
    $secretFile = "/run/secrets/{$name}";
    if (file_exists($secretFile) && is_readable($secretFile)) {
        $val = trim((string) file_get_contents($secretFile));
        if ($val !== '') {
            return $val;
        }
    }

    return $envVal;
};

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
        'webhook_secret' => $resolveSecret('bkash_secret', env('BKASH_WEBHOOK_SECRET', env('BKASH_APP_SECRET'))),
        'app_secret' => $resolveSecret('bkash_secret', env('BKASH_APP_SECRET')),
    ],

    'nagad' => [
        'webhook_secret' => $resolveSecret('nagad_secret', env('NAGAD_WEBHOOK_SECRET', env('NAGAD_MERCHANT_KEY'))),
        'merchant_key' => $resolveSecret('nagad_secret', env('NAGAD_MERCHANT_KEY')),
    ],

];
