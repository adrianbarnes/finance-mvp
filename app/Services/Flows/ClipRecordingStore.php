<?php

namespace App\Services\Flows;

use App\Models\AudioClip;
use App\Models\ClipRecording;
use App\Models\Language;
use App\Services\Audio\AudioTranscoder;
use App\Services\Audio\AudioTranscodingFailed;
use Illuminate\Support\Facades\Storage;

/**
 * Saves a clip's recording in a language, in both playback formats: MP3
 * for the browser phone and 8 kHz WAV for the IVR. Files are named by
 * their content and never overwritten, so flow versions can share them.
 */
class ClipRecordingStore
{
    public const string Folder = 'flow-audio';

    public function __construct(private AudioTranscoder $audioTranscoder) {}

    /**
     * @throws AudioTranscodingFailed
     */
    public function store(AudioClip $clip, Language $language, string $sourceAudio, ?string $originalFilename = null): ClipRecording
    {
        return $this->save(
            $clip,
            $language,
            $this->audioTranscoder->forBrowserPlayback($sourceAudio),
            $this->audioTranscoder->forTelephony($sourceAudio),
            $originalFilename,
        );
    }

    /**
     * Save audio that is already in both playback formats.
     */
    public function save(AudioClip $clip, Language $language, string $mp3, ?string $telephonyWav, ?string $originalFilename = null): ClipRecording
    {
        $audioPath = $this->put($mp3, 'mp3');
        $telephonyAudioPath = $telephonyWav === null ? null : $this->put($telephonyWav, 'wav');

        return ClipRecording::query()->updateOrCreate(
            ['audio_clip_id' => $clip->id, 'language_id' => $language->id],
            [
                'audio_path' => $audioPath,
                'telephony_audio_path' => $telephonyAudioPath,
                'original_filename' => $originalFilename,
                'duration_ms' => $telephonyWav === null ? null : $this->durationOfTelephonyWav($telephonyWav),
            ],
        );
    }

    /**
     * Detach the audio but keep the wording. The files stay, since other
     * flow versions may still play them.
     */
    public function remove(ClipRecording $recording): void
    {
        $recording->update(['audio_path' => null, 'telephony_audio_path' => null, 'original_filename' => null, 'duration_ms' => null]);
    }

    private function put(string $contents, string $extension): string
    {
        $path = self::Folder.'/'.sha1($contents).'.'.$extension;

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $contents);
        }

        return $path;
    }

    /**
     * 8 kHz mono 16-bit PCM is 16 bytes per millisecond after the header.
     */
    private function durationOfTelephonyWav(string $wav): int
    {
        return intdiv(max(0, strlen($wav) - 44), 16);
    }
}
