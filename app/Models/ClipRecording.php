<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClipRecordingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A clip's audio in one language. Audio files are written once and never
 * overwritten, so flow versions can share them.
 *
 * @property int $id
 * @property int $audio_clip_id
 * @property int $language_id
 * @property string|null $text What the recording says
 * @property string|null $audio_path MP3 for the browser phone
 * @property string|null $telephony_audio_path 8 kHz WAV for the IVR
 * @property string|null $original_filename
 * @property int|null $duration_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['audio_clip_id', 'language_id', 'text', 'audio_path', 'telephony_audio_path', 'original_filename', 'duration_ms'])]
class ClipRecording extends Model
{
    /** @use HasFactory<ClipRecordingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AudioClip, $this>
     */
    public function clip(): BelongsTo
    {
        return $this->belongsTo(AudioClip::class, 'audio_clip_id');
    }

    /**
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class)->withTrashed();
    }

    public function audioUrl(): ?string
    {
        return $this->audio_path === null ? null : Storage::disk('public')->url($this->audio_path);
    }
}
