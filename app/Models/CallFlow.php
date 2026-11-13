<?php

namespace App\Models;

use App\Enums\FlowStatus;
use Carbon\CarbonImmutable;
use Database\Factories\CallFlowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of the IVR flow: its steps and the audio clips they play.
 *
 * @property int $id
 * @property int $version
 * @property FlowStatus $status
 * @property array<string, mixed>|null $settings
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['version', 'status', 'settings', 'published_at'])]
class CallFlow extends Model
{
    /** @use HasFactory<CallFlowFactory> */
    use HasFactory;

    /**
     * Flow-wide rules and the clips played outside any one step.
     *
     * @var array<string, mixed>
     */
    public const array DefaultSettings = [
        'max_no_input_replays' => 2,
        'max_misunderstood_attempts' => 3,
        'not_understood_clip' => 'not_understood',
        'too_many_attempts_clip' => 'too_many_attempts',
        'speech_unavailable_clip' => 'speech_unavailable',
        'no_input_goodbye_clip' => 'goodbye',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FlowStatus::class,
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public static function draft(): ?self
    {
        return self::query()->where('status', FlowStatus::Draft)->latest('version')->first();
    }

    public function setting(string $key): mixed
    {
        return $this->settings[$key] ?? self::DefaultSettings[$key] ?? null;
    }

    /**
     * @return HasMany<FlowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(FlowStep::class);
    }

    /**
     * Calls that ran this version.
     *
     * @return HasMany<CallSession, $this>
     */
    public function callSessions(): HasMany
    {
        return $this->hasMany(CallSession::class);
    }

    /**
     * @return HasMany<AudioClip, $this>
     */
    public function clips(): HasMany
    {
        return $this->hasMany(AudioClip::class);
    }
}
