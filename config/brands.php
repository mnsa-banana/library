<?php

return [
    'domains' => [
        'makenetflixsafeagain.com' => 'mnsa-safe',
        'makenetflixstraightagain.com' => 'mnsa-straight',
        'sponge.kids' => 'sponge-kids',
    ],

    'brands' => [
        'mnsa-safe' => [
            'name' => 'Make Netflix Safe Again',
            'entitlement_id' => 'MNSA Pro',
            'revenuecat_secret_key' => env('REVENUECAT_MNSA_SECRET_KEY'),
            'purchase_link_url' => 'https://pay.rev.cat/sandbox/lnwjlvtngwiusvft/',
            'purchase_link_url_prod' => 'https://pay.rev.cat/vhofslaynsspcpdp/',
            'allowed_origin' => 'https://makenetflixsafeagain.com',
            'accent_hex' => '#e23636',
            'mail_from_address' => env('MNSA_SAFE_MAIL_FROM', 'noreply@imbuo.app'),
            'mail_from_name' => 'Make Netflix Safe Again',
            'customer_center_url_pattern' => env(
                'MNSA_SAFE_CUSTOMER_CENTER_URL',
                'https://pay.rev.cat/customer/{appUserId}'
            ),
            'spa_origin_local' => 'http://localhost:5173',
            'mail_logo_url' => null,
        ],
        'mnsa-straight' => [
            'name' => 'Make Netflix Straight Again',
            'entitlement_id' => 'MNSA Pro',
            'revenuecat_secret_key' => env('REVENUECAT_MNSA_SECRET_KEY'),
            'purchase_link_url' => 'https://pay.rev.cat/sandbox/lnwjlvtngwiusvft/',
            'purchase_link_url_prod' => 'https://pay.rev.cat/vhofslaynsspcpdp/',
            'allowed_origin' => 'https://makenetflixstraightagain.com',
            'accent_hex' => '#e23636',
            'mail_from_address' => env('MNSA_STRAIGHT_MAIL_FROM', 'noreply@imbuo.app'),
            'mail_from_name' => 'Make Netflix Straight Again',
            'customer_center_url_pattern' => env(
                'MNSA_STRAIGHT_CUSTOMER_CENTER_URL',
                'https://pay.rev.cat/customer/{appUserId}'
            ),
            'spa_origin_local' => 'http://localhost:5173',
            'mail_logo_url' => null,
        ],
        'sponge-kids' => [
            'name' => 'Sponge Kids',
            'entitlement_id' => 'Sponge Kids Pro',
            'revenuecat_secret_key' => env('REVENUECAT_SK_SECRET_KEY'),
            'purchase_link_url' => 'https://pay.rev.cat/sandbox/kdywecdxbqwzhdym/',
            'purchase_link_url_prod' => 'https://pay.rev.cat/ikdukzuuuwuoyiii/',
            'allowed_origin' => 'https://sponge.kids',
            'accent_hex' => '#f5c518',
            'mail_from_address' => env('SPONGE_KIDS_MAIL_FROM', 'noreply@imbuo.app'),
            'mail_from_name' => 'Sponge Kids',
            'customer_center_url_pattern' => env(
                'SPONGE_KIDS_CUSTOMER_CENTER_URL',
                'https://pay.rev.cat/customer/{appUserId}'
            ),
            'spa_origin_local' => 'http://localhost:5173',
            'mail_logo_url' => null,
        ],
    ],

    'default' => 'sponge-kids',
];
