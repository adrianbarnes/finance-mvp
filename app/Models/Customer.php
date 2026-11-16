<?php

namespace App\Models;

use App\Enums\RegisteredVia;
use App\Support\Cedis;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * @property int $id
 * @property string|null $full_name
 * @property string $phone_number In E.164, e.g. "+233244123456"
 * @property int|null $preferred_language_id
 * @property int $balance_pesewas
 * @property RegisteredVia $registered_via
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Language|null $preferredLanguage
 */
#[Fillable(['full_name', 'phone_number', 'preferred_language_id', 'balance_pesewas', 'registered_via'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_pesewas' => 'integer',
            'registered_via' => RegisteredVia::class,
        ];
    }

    /**
     * A phone number in E.164. Numbers without a country code are read as
     * numbers in the app's phone country, so "024 412 3456" and
     * "+233244123456" both become "+233244123456". Null when the number
     * is not valid.
     */
    public static function normalizePhoneNumber(string $phoneNumber): ?string
    {
        $number = new PhoneNumber($phoneNumber, (string) config('app.phone_country'));

        return $number->isValid() ? $number->formatE164() : null;
    }

    public static function findByPhoneNumber(string $phoneNumber): ?self
    {
        $normalized = self::normalizePhoneNumber($phoneNumber);

        return $normalized === null ? null : self::query()->where('phone_number', $normalized)->first();
    }

    /**
     * Phone numbers are always saved in E.164.
     *
     * @return Attribute<string, string>
     */
    protected function phoneNumber(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => self::normalizePhoneNumber($value) ?? $value);
    }

    /**
     * The saved language. A deleted language counts as none saved.
     *
     * @return BelongsTo<Language, $this>
     */
    public function preferredLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'preferred_language_id');
    }

    /**
     * The saved language when calls can still use it (not disabled or
     * deleted); otherwise the caller is asked again.
     */
    public function usableLanguage(): ?Language
    {
        $language = $this->preferredLanguage;

        return $language?->enabled ? $language : null;
    }

    /**
     * @return HasMany<LoanApplication, $this>
     */
    public function loanApplications(): HasMany
    {
        return $this->hasMany(LoanApplication::class);
    }

    /**
     * @return HasOne<LoanApplication, $this>
     */
    public function latestLoanApplication(): HasOne
    {
        return $this->hasOne(LoanApplication::class)->latestOfMany();
    }

    /**
     * @return HasMany<CallSession, $this>
     */
    public function callSessions(): HasMany
    {
        return $this->hasMany(CallSession::class);
    }

    public function formattedBalance(): string
    {
        return Cedis::format($this->balance_pesewas);
    }
}
