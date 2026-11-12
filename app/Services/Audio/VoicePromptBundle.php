<?php

namespace App\Services\Audio;

use App\Models\VoicePrompt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The committed copy of the voice prompts: a manifest.json with each
 * prompt's wording, plus its audio in a folder per function, step and
 * language under stable names, e.g. "call/greeting/twi/greeting.mp3" and
 * "call/greeting/twi/greeting-8k.wav". Exported from the database
 * after prompts are edited, and imported by the seeder on a fresh install.
 */
class VoicePromptBundle
{
    /**
     * Write every prompt to the bundle, replacing what was there.
     *
     * @param  Collection<int, VoicePrompt>  $prompts
     * @return array{prompts: int, with_audio: int}
     */
    public function export(Collection $prompts): array
    {
        $path = $this->path();
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);

        $manifest = [];
        $withAudio = 0;
        $publicDisk = Storage::disk('public');

        foreach ($prompts->sortBy(['language', 'key']) as $prompt) {
            $language = $prompt->language->value;
            $entry = ['text' => $prompt->text, 'audio' => null, 'telephony_audio' => null];

            if ($prompt->audio_path !== null && $publicDisk->exists($prompt->audio_path)) {
                $folder = $prompt->audioFolder();
                File::ensureDirectoryExists("{$path}/{$folder}");
                $entry['audio'] = "{$folder}/{$prompt->key}.mp3";
                File::put("{$path}/{$entry['audio']}", $publicDisk->get($prompt->audio_path));

                if ($prompt->telephony_audio_path !== null && $publicDisk->exists($prompt->telephony_audio_path)) {
                    $entry['telephony_audio'] = "{$folder}/{$prompt->key}-8k.wav";
                    File::put("{$path}/{$entry['telephony_audio']}", $publicDisk->get($prompt->telephony_audio_path));
                }

                $withAudio++;
            }

            $manifest[$language][$prompt->key] = $entry;
        }

        File::put("{$path}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        return ['prompts' => $prompts->count(), 'with_audio' => $withAudio];
    }

    /**
     * Create missing prompts from the bundle and attach bundled audio to
     * prompts that have none yet. Prompts already edited locally are kept.
     *
     * @return array{created: int, audio_attached: int}
     */
    public function import(): array
    {
        $created = 0;
        $audioAttached = 0;

        foreach ($this->manifest() as $language => $prompts) {
            foreach ($prompts as $key => $entry) {
                $prompt = VoicePrompt::query()->firstOrCreate(
                    ['key' => $key, 'language' => $language],
                    ['text' => $entry['text']],
                );

                $created += $prompt->wasRecentlyCreated ? 1 : 0;

                if ($prompt->audio_path === null && $entry['audio'] !== null) {
                    $prompt->update([
                        'audio_path' => $this->copyToPublicDisk($entry['audio']),
                        'telephony_audio_path' => $entry['telephony_audio'] === null ? null : $this->copyToPublicDisk($entry['telephony_audio']),
                    ]);
                    $audioAttached++;
                }
            }
        }

        return ['created' => $created, 'audio_attached' => $audioAttached];
    }

    /**
     * @return array<string, array<string, array{text: string, audio: string|null, telephony_audio: string|null}>>
     */
    public function manifest(): array
    {
        $manifestPath = $this->path().'/manifest.json';

        return File::exists($manifestPath) ? json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR) : [];
    }

    public function path(): string
    {
        return (string) config('conversation.voice_prompt_bundle_path');
    }

    private function copyToPublicDisk(string $bundledFile): string
    {
        $publicPath = 'voice-prompts/'.$bundledFile;
        Storage::disk('public')->put($publicPath, File::get($this->path().'/'.$bundledFile));

        return $publicPath;
    }
}
