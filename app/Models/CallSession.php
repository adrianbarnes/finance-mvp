<?php

namespace App\Models;

use App\Enums\CallChannel;
use App\Enums\CallDirection;
use App\Enums\CallState;
use App\Enums\Language;
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
 * @property CallDirection $direction
 * @property Language|null $language
 * @property CallState $state
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Customer|null $customer
 */
#[Fillable(['uuid', 'customer_id', 'caller_number', 'channel', 'direction', 'language', 'state', 'context', 'started_at', 'ended_at'])]
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
            'language' => Language::class,
            'state' => CallState::class,
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
