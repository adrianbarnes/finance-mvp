<?php

namespace App\Services\Audio;

use App\Models\VoicePrompt;
use Illuminate\Support\Facades\Storage;

/**
 * Saves a prompt's audio in both playback formats: MP3 for the browser
 * phone and 8 kHz WAV for the IVR.
 */
class VoicePromptAudioStore
{
    public function __construct(private AudioTranscoder $audioTranscoder) {}

    /**
     * @throws AudioTranscodingFailed
     */
    public function store(VoicePrompt $prompt, string $sourceAudio): void
    {
        $basePath = 'voice-prompts/'.$prompt->audioFolder().'/'.$prompt->key.'-'.now()->format('YmdHis');
        $audioPath = $basePath.'.mp3';
        $telephonyAudioPath = $basePath.'-8k.wav';

        Storage::disk('public')->put($audioPath, $this->audioTranscoder->forBrowserPlayback($sourceAudio));
        Storage::disk('public')->put($telephonyAudioPath, $this->audioTranscoder->forTelephony($sourceAudio));

        $this->delete($prompt);
        $prompt->update(['audio_path' => $audioPath, 'telephony_audio_path' => $telephonyAudioPath]);
    }

    public function delete(VoicePrompt $prompt): void
    {
        Storage::disk('public')->delete(array_filter([$prompt->audio_path, $prompt->telephony_audio_path]));
    }
}
