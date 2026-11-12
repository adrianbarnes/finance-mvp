<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'khaya' => [
        'driver' => env('KHAYA_DRIVER', 'fake'),
        'key' => env('KHAYA_API_KEY'),
        'asr_url' => env('KHAYA_ASR_URL', 'https://translation-api.ghananlp.org/asr/v3'),
        'timeout' => (int) env('KHAYA_TIMEOUT', 30),
        'monthly_quota' => (int) env('KHAYA_MONTHLY_QUOTA', 100),
        'per_minute_limit' => (int) env('KHAYA_PER_MINUTE_LIMIT', 10),
    ],

    'abena' => [
        'key' => env('ABENA_API_KEY'),
        'url' => env('ABENA_API_URL', 'https://abena.mobobi.com/playground/api/v1'),
        'timeout' => (int) env('ABENA_TIMEOUT', 60),
        'speed' => (float) env('ABENA_SPEED', 1.0),
        // Seconds between requests when voicing many prompts (free tier: 20 per minute).
        'pause_seconds' => (float) env('ABENA_PAUSE_SECONDS', 3.5),
        // Voice used for each call language; null means no voice is available yet.
        'voices' => [
            'twi' => env('ABENA_TWI_VOICE', 'abena_twi_high'),
            'fat' => env('ABENA_FANTE_VOICE'),
            'gaa' => env('ABENA_GA_VOICE'),
            'ewe' => env('ABENA_EWE_VOICE', 'mawuli_ewe'),
            'eng' => env('ABENA_ENGLISH_PROMPT_VOICE', 'akua_eng'),
        ],
        // Ghanaian-accent English voice for amounts, dates, names and references.
        'english_voice' => env('ABENA_ENGLISH_VOICE', 'akua_eng'),
        // Voice the dynamic English parts of replies during calls (cached per phrase).
        'dynamic_speech' => (bool) env('ABENA_DYNAMIC_SPEECH', false),
    ],

    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'min_speech_seconds' => (float) env('MIN_SPEECH_SECONDS', 0.3),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
