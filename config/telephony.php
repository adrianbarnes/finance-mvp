<?php

use App\Services\Telephony\Twilio\TwilioProvider;

/*
|--------------------------------------------------------------------------
| Telephony
|--------------------------------------------------------------------------
|
| Phone providers that carry IVR calls. Each provider's webhooks live under
| /telephony/{provider}/: point the provider's incoming-call webhook at
| /telephony/{provider}/voice (add ?draft=1 to test the draft flow) and its
| call-status webhook at /telephony/{provider}/status.
|
| To connect another provider, write a class that implements
| App\Services\Telephony\TelephonyProvider and add it below.
|
*/

return [

    /*
    | The provider the call console rings phones through.
    */
    'default' => env('TELEPHONY_PROVIDER', 'twilio'),

    /*
    | The public https address providers reach this app on, e.g. a tunnel
    | such as `herd share` or ngrok. Used for audio and webhook links, and to
    | check request signatures.
    */
    'public_url' => env('TELEPHONY_PUBLIC_URL'),

    'providers' => [

        'twilio' => [
            'driver' => TwilioProvider::class,
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            // The Twilio number calls to phones are made from, in E.164.
            'phone_number' => env('TWILIO_PHONE_NUMBER'),
            // Reject webhooks without a valid X-Twilio-Signature.
            'validate_signature' => (bool) env('TWILIO_VALIDATE_SIGNATURE', true),
            // Twilio's own speech recognition, for languages set to browser
            // recognition (English, for testing).
            'speech_language' => env('TWILIO_SPEECH_LANGUAGE', 'en-GB'),
            // Voice for wording that has no recording yet. Empty for Twilio's default.
            'say_voice' => env('TWILIO_SAY_VOICE'),
            // Seconds of silence that end a caller's answer in Khaya languages.
            'record_silence_seconds' => (int) env('TWILIO_RECORD_SILENCE_SECONDS', 4),
            'record_max_seconds' => (int) env('TWILIO_RECORD_MAX_SECONDS', 20),
        ],

    ],

];
