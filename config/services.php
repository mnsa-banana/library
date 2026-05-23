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
        'token' => env('POSTMARK_TOKEN'),
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

    'streaming_availability' => [
        'api_key' => env('STREAMING_AVAILABILITY_API_KEY'),
        'base_url' => env('STREAMING_AVAILABILITY_BASE_URL', 'https://api.movieofthenight.com/v4'),
        'qps' => (int) env('STREAMING_AVAILABILITY_QPS', 5),
    ],

    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
    ],

    'mail_brand' => [
        'accent_hex' => env('MAIL_ACCENT_HEX', '#4f46e5'),
        'logo_url' => env('MAIL_LOGO_URL'),
    ],

    'revenuecat' => [
        'entitlement_id' => env('REVENUECAT_ENTITLEMENT_ID', 'Sponge Kids Pro'),
        'secret_key' => env('REVENUECAT_SECRET_KEY'),
        'purchase_link_url' => env('REVENUECAT_PURCHASE_LINK_URL'),
        'customer_center_url_pattern' => env('REVENUECAT_CUSTOMER_CENTER_URL', 'https://pay.rev.cat/customer/{appUserId}'),
    ],

];
