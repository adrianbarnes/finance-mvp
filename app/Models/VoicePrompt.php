<?php

namespace App\Models;

use App\Enums\Language;
use Carbon\CarbonImmutable;
use Database\Factories\VoicePromptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A fixed phrase the assistant speaks, with optional recorded audio.
 *
 * @property int $id
 * @property string $key
 * @property Language $language
 * @property string $text
 * @property string|null $audio_path
 * @property string|null $telephony_audio_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['key', 'language', 'text', 'audio_path', 'telephony_audio_path'])]
class VoicePrompt extends Model
{
    /** @use HasFactory<VoicePromptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'language' => Language::class,
        ];
    }

    /**
     * The function this prompt serves (call, loan_application, balance...),
     * from config('conversation.prompt_groups').
     */
    public function group(): string
    {
        foreach (config('conversation.prompt_groups') as $group => $keys) {
            if (in_array($this->key, $keys, true)) {
                return $group;
            }
        }

        return 'other';
    }

    /**
     * Folder holding this prompt's audio: {group}/{step}/{language}.
     */
    public function audioFolder(): string
    {
        return $this->group().'/'.$this->key.'/'.$this->language->value;
    }

    public function audioUrl(): ?string
    {
        return $this->audio_path === null ? null : Storage::disk('public')->url($this->audio_path);
    }
}
