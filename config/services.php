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

        /*
         * Kode unit OPD induk pada hirarki WORKA. Anak langsung simpul inilah
         * yang menjadi "unit kerja level teratas" di SI-ABSEN — unit yang
         * dikelola admin dan dipilih pada event/kiosk (lihat SDD §3.1).
         */
        'kode_opd' => env('WORKA_KODE_OPD', 'DISNAKERTRANS'),

        /*
         * Induk unit kerja milik SI-ABSEN sendiri, dalam bentuk
         * kode unit lokal => kode unit induk di WORKA.
         *
         * Unit di sini tidak dikirim WORKA (mis. DISNAKER, tempat kepala
         * dinas — tempat bernaung akun Admin Dinas dan kiosk kantor dinas),
         * sehingga induknya tidak dapat ditarik dari jawaban API. Tautannya
         * ditegakkan ulang setiap kali `pegawai:sinkron` selesai, jadi
         * hirarki tetap utuh tanpa bergantung urutan seeding.
         */
        'induk_unit_lokal' => [
            'DISNAKER' => 'DISNAKERTRANS',
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
