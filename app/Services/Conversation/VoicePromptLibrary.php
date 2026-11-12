<?php

namespace App\Services\Conversation;

use App\Enums\Language;
use App\Models\VoicePrompt;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Looks up the fixed phrases the assistant speaks. Recorded audio is
 * preferred; the prompt text is returned so the client can fall back to
 * speech synthesis while audio is still being recorded.
 */
class VoicePromptLibrary
{
    /**
     * @var array<string, Collection<string, VoicePrompt>>
     */
    private array $loaded = [];

    /**
     * @return array{type: string, key: string, text: string, audio_url: string|null, language: string}
     */
    public function segment(string $key, Language $language): array
    {
        $prompt = $this->prompts($language)->get($key);

        return [
            'type' => 'prompt',
            'key' => $key,
            'text' => $prompt->text ?? Str::headline($key),
            'audio_url' => $prompt?->audioUrl(),
            'language' => $language->value,
        ];
    }

    /**
     * @return Collection<string, VoicePrompt>
     */
    private function prompts(Language $language): Collection
    {
        return $this->loaded[$language->value] ??= VoicePrompt::query()
            ->where('language', $language)
            ->get()
            ->keyBy('key');
    }
}
