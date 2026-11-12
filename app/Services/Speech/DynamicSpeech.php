<?php

namespace App\Services\Speech;

use App\Services\Abena\AbenaTextToSpeech;
use App\Services\Audio\AudioTranscoder;
use App\Services\Audio\AudioTranscodingFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Voices the dynamic English parts of a reply (amounts, dates, names,
 * references) with Abena's Ghanaian-English voice. Each phrase is
 * synthesized once and cached; on failure the client falls back to
 * reading the text itself.
 */
class DynamicSpeech
{
    public function __construct(
        private AbenaTextToSpeech $textToSpeech,
        private AudioTranscoder $audioTranscoder,
    ) {}

    /**
     * @return array{type: string, text: string, language: string, audio_url: string|null}
     */
    public function segment(string $text): array
    {
        return ['type' => 'speech', 'text' => $text, 'language' => 'en', 'audio_url' => $this->audioUrl($text)];
    }

    private function audioUrl(string $text): ?string
    {
        if (! config('services.abena.dynamic_speech')) {
            return null;
        }

        $voice = (string) config('services.abena.english_voice');
        $basePath = 'speech-cache/'.$voice.'/'.sha1(mb_strtolower(trim($text)));
        $disk = Storage::disk('public');

        if (! $disk->exists($basePath.'.mp3')) {
            try {
                $audio = $this->textToSpeech->synthesize($text, $voice);
                $disk->put($basePath.'.mp3', $this->audioTranscoder->forBrowserPlayback($audio));
                $disk->put($basePath.'-8k.wav', $this->audioTranscoder->forTelephony($audio));
            } catch (TextToSpeechFailed|AudioTranscodingFailed $exception) {
                Log::warning('Dynamic speech synthesis failed', ['error' => $exception->getMessage()]);

                return null;
            }
        }

        return $disk->url($basePath.'.mp3');
    }
}
