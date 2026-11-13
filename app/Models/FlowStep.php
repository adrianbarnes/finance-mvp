<?php

namespace App\Models;

use App\Enums\PromptSlot;
use App\Enums\StepType;
use Carbon\CarbonImmutable;
use Database\Factories\FlowStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A step in an IVR flow. Steps are nested: a choice or check holds
 * branches, and each branch holds the steps on that path. When a branch's
 * steps run out, the call continues after the step that branched.
 *
 * @property int $id
 * @property int $call_flow_id
 * @property int|null $parent_id
 * @property int $position
 * @property StepType $type
 * @property string $name
 * @property string|null $keypad_digit
 * @property array<string, mixed>|null $config
 * @property bool $is_otherwise
 * @property int|null $go_to_step_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, StepKeyword> $keywords
 * @property-read Collection<int, StepPromptItem> $promptItems
 * @property-read FlowStep|null $parent
 * @property-read FlowStep|null $goToStep
 */
#[Fillable(['call_flow_id', 'parent_id', 'position', 'type', 'name', 'keypad_digit', 'config', 'is_otherwise', 'go_to_step_id'])]
class FlowStep extends Model
{
    /** @use HasFactory<FlowStepFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'type' => StepType::class,
            'config' => 'array',
            'is_otherwise' => 'boolean',
        ];
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return BelongsTo<CallFlow, $this>
     */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(CallFlow::class, 'call_flow_id');
    }

    /**
     * @return BelongsTo<FlowStep, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'parent_id');
    }

    /**
     * @return HasMany<FlowStep, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(FlowStep::class, 'parent_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<FlowStep, $this>
     */
    public function goToStep(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'go_to_step_id');
    }

    /**
     * @return HasMany<StepKeyword, $this>
     */
    public function keywords(): HasMany
    {
        return $this->hasMany(StepKeyword::class);
    }

    /**
     * @return HasMany<StepPromptItem, $this>
     */
    public function promptItems(): HasMany
    {
        return $this->hasMany(StepPromptItem::class)->orderBy('position');
    }

    /**
     * @return Collection<int, StepPromptItem>
     */
    public function promptItemsFor(PromptSlot $slot): Collection
    {
        return $this->promptItems->where('slot', $slot)->values();
    }

    /**
     * @return list<string>
     */
    public function keywordsFor(Language $language): array
    {
        return $this->keywords->firstWhere('language_id', $language->id)->words ?? [];
    }
}
