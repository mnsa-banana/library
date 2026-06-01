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

    'netflix_kids' => [
        // Cookie header copied from a US-region, Kids-profile netflix.com session.
        'cookie' => env('NETFLIX_KIDS_COOKIE'),
        // SearchPageQueryResults persisted query — capture from browser devtools if rotated.
        'persisted_query_id' => env('NETFLIX_KIDS_PERSISTED_QUERY_ID'),
        'persisted_query_version' => (int) env('NETFLIX_KIDS_PERSISTED_QUERY_VERSION', 102),
        // Netflix's own maturityLevel ceiling for the "titles just for kids" experience (TV-PG).
        'maturity_ceiling' => (int) env('NETFLIX_KIDS_MATURITY_CEILING', 70),
        // Throttle between Stage-2 search calls (seconds, float ok).
        'search_delay' => (float) env('NETFLIX_KIDS_SEARCH_DELAY', 0.3),
        // Retry transient HTTP/TLS errors on Netflix calls before giving up.
        'retry_times' => (int) env('NETFLIX_KIDS_RETRY_TIMES', 4),
        'retry_sleep_ms' => (int) env('NETFLIX_KIDS_RETRY_SLEEP_MS', 1000),
        // Default refresh horizon: a no-flag run re-verifies titles older than this
        // (and skips more-recently-checked ones, so an aborted run resumes).
        'default_stale_days' => (int) env('NETFLIX_KIDS_DEFAULT_STALE_DAYS', 14),
    ],

    'tmdb' => [
        'api_key' => env('TMDB_API_KEY'),
    ],

    'revenuecat' => [
        'entitlement_id' => env('REVENUECAT_ENTITLEMENT_ID', 'Sponge Kids Pro'),
        'secret_key' => env('REVENUECAT_SECRET_KEY'),
        'purchase_link_url' => env('REVENUECAT_PURCHASE_LINK_URL'),
        'customer_center_url_pattern' => env('REVENUECAT_CUSTOMER_CENTER_URL', 'https://pay.rev.cat/customer/{appUserId}'),
    ],

    'mnsa' => [
        'base_url' => env('MNSA_BASE_URL'),
        'service_token' => env('MNSA_SERVICE_TOKEN'),
        'http_timeout' => (int) env('MNSA_HTTP_TIMEOUT', 30),
    ],

];
