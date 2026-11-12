<?php

namespace App\Models;

use App\Enums\Language;
use App\Enums\RegisteredVia;
use App\Support\Cedis;
use Carbon\CarbonImmutable;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string|null $full_name
 * @property string $phone_number
 * @property Language|null $preferred_language
 * @property int $balance_pesewas
 * @property RegisteredVia $registered_via
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['full_name', 'phone_number', 'preferred_language', 'balance_pesewas', 'registered_via'])]
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
            'preferred_language' => Language::class,
            'balance_pesewas' => 'integer',
            'registered_via' => RegisteredVia::class,
        ];
    }

    /**
     * Normalise a Ghanaian phone number to international digits, e.g.
     * "024 412 3456" and "+233244123456" both become "233244123456".
     */
    public static function normalizePhoneNumber(string $phoneNumber): string
    {
        $digits = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '233'.substr($digits, 1);
        }

        return $digits;
    }

    public static function findByPhoneNumber(string $phoneNumber): ?self
    {
        return self::query()->where('phone_number', self::normalizePhoneNumber($phoneNumber))->first();
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
