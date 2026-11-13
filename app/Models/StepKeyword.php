<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\StepKeywordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Words that pick a branch when spoken in one language.
 *
 * @property int $id
 * @property int $flow_step_id
 * @property int $language_id
 * @property list<string> $words
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['flow_step_id', 'language_id', 'words'])]
class StepKeyword extends Model
{
    /** @use HasFactory<StepKeywordFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'words' => 'array',
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
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
