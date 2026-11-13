<?php

namespace App\Services\Khaya;

use App\Enums\SpeechRecognition;
use App\Models\CallSession;
use App\Models\KhayaApiCall;
use App\Models\Language;
use App\Services\Speech\SpeechRecognitionFailed;
use App\Services\Speech\SpeechRecognizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Khaya ASR v3: POST {asr_url}/transcribe?language=twi with raw audio bytes.
 */
class KhayaSpeechRecognizer implements SpeechRecognizer
{
    public const string Endpoint = 'asr.transcribe';

    /**
     * Audio formats accepted by Khaya, keyed by the MIME types browsers and
     * fileinfo report for them.
     *
     * @var array<string, string>
     */
    private const array ContentTypes = [
        'audio/wav' => 'audio/wav',
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/vnd.wave' => 'audio/wav',
        'audio/mpeg' => 'audio/mpeg',
        'audio/flac' => 'audio/flac',
        'audio/x-flac' => 'audio/flac',
        'audio/ogg' => 'audio/ogg',
    ];

    public function __construct(private KhayaQuotaGuard $quotaGuard) {}

    public function transcribe(string $audio, string $mimeType, Language $language, ?CallSession $callSession = null): string
    {
        if ($language->speech_recognition !== SpeechRecognition::Khaya || blank($language->khaya_code)) {
            throw new SpeechRecognitionFailed('UNSUPPORTED_LANGUAGE', "{$language->name} speech is not recognised by Khaya.");
        }

        $apiKey = config('services.khaya.key');

        if (blank($apiKey)) {
            throw new SpeechRecognitionFailed('MISSING_API_KEY', 'KHAYA_API_KEY is not configured.');
        }

        $contentType = self::ContentTypes[strtolower($mimeType)]
            ?? throw new SpeechRecognitionFailed('INVALID_AUDIO_FORMAT', "Unsupported audio type [{$mimeType}].");

        $this->quotaGuard->ensureAvailable();

        $startedAt = hrtime(true);

        try {
            $response = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $apiKey])
                ->acceptJson()
                ->timeout((int) config('services.khaya.timeout'))
                ->withQueryParameters(['language' => $language->khaya_code])
                ->withBody($audio, $contentType)
                ->post(rtrim((string) config('services.khaya.asr_url'), '/').'/transcribe');
        } catch (ConnectionException $exception) {
            $this->logCall($callSession, $language, null, 'CONNECTION_FAILED', $startedAt);

            throw new SpeechRecognitionFailed('CONNECTION_FAILED', $exception->getMessage());
        }

        if ($response->failed()) {
            $errorCode = $this->errorCode($response);
            $this->logCall($callSession, $language, $response->status(), $errorCode, $startedAt);

            throw new SpeechRecognitionFailed($errorCode, (string) $response->json('error.message', 'Khaya ASR request failed.'));
        }

        $this->logCall($callSession, $language, $response->status(), null, $startedAt);

        return trim((string) $response->json('text', ''));
    }

    private function errorCode(Response $response): string
    {
        return (string) ($response->json('error.details.0.code')
            ?? $response->json('error.code')
            ?? 'HTTP_'.$response->status());
    }

    private function logCall(?CallSession $callSession, Language $language, ?int $httpStatus, ?string $errorCode, int $startedAt): void
    {
        KhayaApiCall::query()->create([
            'call_session_id' => $callSession?->id,
            'endpoint' => self::Endpoint,
            'language' => $language->code,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'latency_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
