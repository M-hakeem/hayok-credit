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

    'partner' => [
        'api_key' => env('PARTNER_API_KEY'),
    ],

    'insucare' => [
        'secret_key' => env('INSUCARE_SECRET_KEY'),
        'base_url' => env('INSUCARE_BASE_URL', 'https://api.insucare.ng'),
    ],

    'claimify_wallet' => [
        'base_url' => env('CLAIMIFY_WALLET_BASE_URL', 'https://claimify-api.hayokmedicare.ng/api/v1'),
        'token' => env('CLAIMIFY_WALLET_TOKEN'),
        'timeout' => env('CLAIMIFY_WALLET_TIMEOUT', 20),
    ],

    'first_central' => [
    'base_url' => env('FIRST_CENTRAL_BASE_URL'),
    'api_key' => env('FIRST_CENTRAL_API_KEY'),
    'token' => env('FIRST_CENTRAL_TOKEN'),
    'username' => env('FIRST_CENTRAL_USERNAME'),
    'password' => env('FIRST_CENTRAL_PASSWORD'),
    'timeout' => env('FIRST_CENTRAL_TIMEOUT', 20),
    ],

];
