<?php

declare(strict_types=1);

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

    'fiscalization' => [
        'url' => env('FISCALIZATION_SERVICE_URL', 'http://localhost:8001'),
        'timeout' => (int) env('FISCALIZATION_SERVICE_TIMEOUT', 10),
    ],

    'as4' => [
        'timeout' => (int) env('AS4_TIMEOUT', 15),
        'default_peer_url' => env('AS4_DEFAULT_PEER_URL', 'http://localhost:8002'),
        // Comma-separated "oib=url" pairs; parsed by the provider into a map.
        'peers' => env('AS4_PEER_MAP', ''),
    ],

    // AMS — central locator operated by Porezna uprava (the erakun-porezna sibling).
    // Maps a recipient OIB to the MPS that publishes it.
    'ams' => [
        'base_url' => env('AMS_BASE_URL', 'http://localhost:8001'),
    ],

    // MPS — the metadata service we publish for our own parties. Derived from
    // the `parties` table; `as4_endpoint` is the AS4 inbox we expose to peers.
    'mps' => [
        'base_url' => env('MPS_BASE_URL', 'http://localhost:8000'),
        'as4_endpoint' => env('MPS_AS4_ENDPOINT', 'http://localhost:8000/api/as4/inbox'),
    ],

];
