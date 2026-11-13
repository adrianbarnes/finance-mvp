<?php

namespace App\Models;

use App\Enums\PromptSlot;
use Carbon\CarbonImmutable;
use Database\Factories\StepPromptItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of what a step says: either a clip or a value spoken at call
 * time (a placeholder such as the balance).
 *
 * @property int $id
 * @property int $flow_step_id
 * @property PromptSlot $slot
 * @property int $position
 * @property int|null $audio_clip_id
 * @property string|null $placeholder
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AudioClip|null $clip
 */
#[Fillable(['flow_step_id', 'slot', 'position', 'audio_clip_id', 'placeholder'])]
class StepPromptItem extends Model
{
    /** @use HasFactory<StepPromptItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot' => PromptSlot::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FlowStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'flow_step_id');
    }

    /**
     * @return BelongsTo<AudioClip, $this>
     */
    public function clip(): BelongsTo
    {
        return $this->belongsTo(AudioClip::class, 'audio_clip_id');
    }
}
