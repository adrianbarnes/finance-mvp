<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AudioClipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable piece of audio, with one recording per language. Clips
 * belong to a flow version, so editing a draft never changes live calls.
 *
 * @property int $id
 * @property int $call_flow_id
 * @property string $key
 * @property string $group
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, ClipRecording> $recordings
 */
#[Fillable(['call_flow_id', 'key', 'group'])]
class AudioClip extends Model
{
    /** @use HasFactory<AudioClipFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<CallFlow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(CallFlow::class, 'call_flow_id');
    }

    /**
     * @return HasMany<ClipRecording, $this>
     */
    public function recordings(): HasMany
    {
        return $this->hasMany(ClipRecording::class);
    }

    /**
     * @return HasMany<StepPromptItem, $this>
     */
    public function promptItems(): HasMany
    {
        return $this->hasMany(StepPromptItem::class);
    }

    public function recordingFor(Language $language): ?ClipRecording
    {
        return $this->recordings->firstWhere('language_id', $language->id);
    }

    public function hasAudioIn(Language $language): bool
    {
        return $this->recordingFor($language)?->audio_path !== null;
    }
}
