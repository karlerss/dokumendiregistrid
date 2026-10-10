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
        'token' => env('POSTMARK_TOKEN'),
    ],

    // Purging adr-docs.karlerss.com URLs from Cloudflare's edge cache when
    // the app deletes or withholds a public file (R2 objects are cached for
    // up to 4 hours). Token needs only Zone > Cache Purge > Purge.
    'cloudflare' => [
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    'openai' => [
        'secret' => env('OPENAI_SECRET'),
        'model' => env('OPENAI_MODEL', 'gpt-5.4'),
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

    'chrome' => [
        'binary' => env('CHROME_BINARY', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'),
    ],

    'docker' => [
        'binary' => env('DOCKER_BINARY', '/usr/bin/docker'),
    ]

];
