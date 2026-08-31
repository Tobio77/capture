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

    /*
     * WORKA — sistem kepegawaian Disnakertrans. SI-ABSEN hanya membaca
     * master data pegawai dan unit kerja dari sana (FR-PEG-01, FR-PEG-02).
     *
     * URL dan token juga dapat diatur lewat menu Setting → Integrasi WORKA;
     * nilai pada tabel `pengaturan` mengalahkan nilai di .env.
     */
    'worka' => [
        'api_url' => env('WORKA_API_URL', 'http://worka.test'),
        'api_token' => env('WORKA_API_TOKEN'),
        'timeout' => env('WORKA_API_TIMEOUT', 30),
        'sync_interval' => env('WORKA_SYNC_INTERVAL', 1440),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
