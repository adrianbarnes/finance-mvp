<?php

namespace App\Models;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use Carbon\CarbonImmutable;
use Database\Factories\CallSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $customer_id
 * @property string $caller_number
 * @property CallChannel $channel
 * @property string|null $telephony_provider The phone provider that carried the call, e.g. "twilio"
 * @property string|null $external_call_id The provider's id for the call, e.g. Twilio's CallSid
 * @property CallDirection $direction
 * @property int|null $language_id
 * @property int|null $call_flow_id
 * @property int|null $current_step_id
 * @property string $state The type of step the call waits on, or "ended"
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Customer|null $customer
 * @property-read Language|null $language
 * @property-read CallFlow|null $callFlow
 * @property-read FlowStep|null $currentStep
 */
#[Fillable(['uuid', 'customer_id', 'caller_number', 'channel', 'telephony_provider', 'external_call_id', 'direction', 'language_id', 'call_flow_id', 'current_step_id', 'state', 'context', 'started_at', 'ended_at'])]
class CallSession extends Model
{
    /** @use HasFactory<CallSessionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (CallSession $session): void {
            $session->uuid ??= (string) Str::uuid();
            $session->started_at ??= now();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => CallChannel::class,
            'direction' => CallDirection::class,
            'context' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The call's language, kept for history even if it is later deleted.
     *
     * @return BelongsTo<Language, $this>
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class)->withTrashed();
    }

    /**
     * The flow version the call runs, fixed when it starts.
     *
     * @return BelongsTo<CallFlow, $this>
     */
    public function callFlow(): BelongsTo
    {
        return $this->belongsTo(CallFlow::class);
    }

    /**
     * @return BelongsTo<FlowStep, $this>
     */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(FlowStep::class, 'current_step_id');
    }

    /**
     * @return HasMany<CallTurn, $this>
     */
    public function turns(): HasMany
    {
        return $this->hasMany(CallTurn::class);
    }

    /**
     * @return HasMany<KhayaApiCall, $this>
     */
    public function khayaApiCalls(): HasMany
    {
        return $this->hasMany(KhayaApiCall::class);
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    public function contextValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->context, $key, $default);
    }
}
