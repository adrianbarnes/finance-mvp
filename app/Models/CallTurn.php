<?php

namespace App\Models;

use App\Enums\CallState;
use App\Enums\InputMode;
use App\Enums\Intent;
use App\Enums\Speaker;
use Carbon\CarbonImmutable;
use Database\Factories\CallTurnFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $call_session_id
 * @property Speaker $speaker
 * @property InputMode|null $input_mode
 * @property string|null $text
 * @property Intent|null $intent
 * @property CallState|null $state_after
 * @property string|null $audio_path
 * @property int|null $recognition_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['call_session_id', 'speaker', 'input_mode', 'text', 'intent', 'state_after', 'audio_path', 'recognition_ms'])]
class CallTurn extends Model
{
    /** @use HasFactory<CallTurnFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'speaker' => Speaker::class,
            'input_mode' => InputMode::class,
            'intent' => Intent::class,
            'state_after' => CallState::class,
        ];
    }

    /**
     * @return BelongsTo<CallSession, $this>
     */
    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }
}
