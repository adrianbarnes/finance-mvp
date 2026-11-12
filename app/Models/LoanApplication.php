<?php

namespace App\Models;

use App\Enums\LoanApplicationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\LoanApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $reference
 * @property int $customer_id
 * @property int|null $call_session_id
 * @property int $amount_pesewas
 * @property int $term_months
 * @property LoanApplicationStatus $status
 * @property int|null $next_repayment_pesewas
 * @property CarbonImmutable|null $next_repayment_due_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'reference', 'customer_id', 'call_session_id', 'amount_pesewas', 'term_months',
    'status', 'next_repayment_pesewas', 'next_repayment_due_on',
])]
class LoanApplication extends Model
{
    /** @use HasFactory<LoanApplicationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (LoanApplication $application): void {
            $application->reference ??= self::generateReference();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LoanApplicationStatus::class,
            'amount_pesewas' => 'integer',
            'term_months' => 'integer',
            'next_repayment_pesewas' => 'integer',
            'next_repayment_due_on' => 'date',
        ];
    }

    /**
     * Short reference that is easy to read out over the phone, e.g. "LN4827".
     */
    public static function generateReference(): string
    {
        do {
            $reference = 'LN'.Str::padLeft((string) random_int(0, 9999), 4, '0');
        } while (self::query()->where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<CallSession, $this>
     */
    public function callSession(): BelongsTo
    {
        return $this->belongsTo(CallSession::class);
    }

    /**
     * Applications that have an upcoming repayment the customer can ask about.
     *
     * @param  Builder<LoanApplication>  $query
     */
    public function scopeWithRepaymentDue(Builder $query): void
    {
        $query->where('status', LoanApplicationStatus::Disbursed)
            ->whereNotNull('next_repayment_pesewas')
            ->whereNotNull('next_repayment_due_on');
    }
}
