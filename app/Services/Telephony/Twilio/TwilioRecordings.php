<?php

namespace App\Services\Telephony\Twilio;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches what a caller said from Twilio, as WAV, so it can be sent to
 * Khaya like browser phone audio.
 */
class TwilioRecordings
{
    /**
     * A recording can take a moment to be ready after <Record> finishes,
     * so a missing one is asked for again a few times.
     */
    private const int Attempts = 4;

    private const int RetryDelayMs = 400;

    /**
     * The recording as WAV, or null when it cannot be fetched.
     *
     * @param  array<string, mixed>  $config  The provider's entry in config/telephony.php
     */
    public function download(string $recordingUrl, array $config): ?string
    {
        if (! $this->isTwilioUrl($recordingUrl)) {
            Log::warning('Ignored a recording that is not hosted by Twilio', ['url' => $recordingUrl]);

            return null;
        }

        $request = Http::timeout(15)->retry(
            self::Attempts,
            self::RetryDelayMs,
            fn (\Throwable $exception): bool => $exception instanceof RequestException && $exception->response->status() === 404,
            throw: false,
        );

        if (filled($config['account_sid'] ?? null) && filled($config['auth_token'] ?? null)) {
            $request->withBasicAuth((string) $config['account_sid'], (string) $config['auth_token']);
        }

        try {
            $response = $request->get($recordingUrl);
        } catch (ConnectionException $exception) {
            Log::warning('Twilio recording could not be fetched', ['error' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Twilio recording could not be fetched', ['status' => $response->status()]);

            return null;
        }

        return $response->body();
    }

    /**
     * Only Twilio's own hosts are fetched, and only they get the account's
     * credentials.
     */
    private function isTwilioUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https' && ($host === 'twilio.com' || str_ends_with($host, '.twilio.com'));
    }
}
