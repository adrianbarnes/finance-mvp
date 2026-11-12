<?php

namespace App\Services\Abena;

use App\Services\Speech\TextToSpeechFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Abena AI text-to-speech: POST {url}/tts/synthesize/ with text and voice,
 * returns Base64 WAV. Works without a key on the small free tier; an API
 * key raises the allowance.
 */
class AbenaTextToSpeech
{
    public const int MaxCharacters = 500;

    private const int MaxAttempts = 3;

    private const int MaxRetryAfterSeconds = 30;

    /**
     * Synthesize speech and return the WAV bytes.
     *
     * @throws TextToSpeechFailed
     */
    public function synthesize(string $text, string $voice): string
    {
        if (mb_strlen($text) > self::MaxCharacters) {
            throw new TextToSpeechFailed('Text is longer than '.self::MaxCharacters.' characters.');
        }

        $request = Http::acceptJson()->asJson()->timeout((int) config('services.abena.timeout'));
        $apiKey = config('services.abena.key');

        if (filled($apiKey)) {
            $request = $request->withToken($apiKey);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $request->post(rtrim((string) config('services.abena.url'), '/').'/tts/synthesize/', [
                    'text' => $text,
                    'voice' => $voice,
                    'speed' => (float) config('services.abena.speed'),
                ]);
            } catch (ConnectionException $exception) {
                throw new TextToSpeechFailed('Could not reach Abena AI: '.$exception->getMessage());
            }

            if ($response->status() !== 503 || $attempt >= self::MaxAttempts) {
                break;
            }

            // At capacity: wait for the Retry-After interval, then retry the same request.
            Sleep::for(min(self::MaxRetryAfterSeconds, max(1, (int) $response->header('Retry-After'))))->seconds();
        }

        $audio = base64_decode((string) $response->json('audio_base64'), true);

        if ($response->failed() || $response->json('status') !== 'success' || $audio === false || $audio === '') {
            throw new TextToSpeechFailed(
                (string) ($response->json('message') ?? $response->json('error') ?? 'Abena AI returned HTTP '.$response->status().'.'),
                $response->status(),
            );
        }

        return $audio;
    }
}
