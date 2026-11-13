<?php

namespace App\Enums;

use App\Support\Cedis;
use Illuminate\Support\Str;

/**
 * What a number answer counts, which sets how it is read back.
 */
enum NumberUnit: string
{
    case Plain = 'plain';
    case Cedis = 'cedis';
    case Months = 'months';

    public function label(): string
    {
        return match ($this) {
            self::Plain => 'A plain number',
            self::Cedis => 'An amount in cedis',
            self::Months => 'A number of months',
        };
    }

    /**
     * Whether answers must be whole numbers.
     */
    public function isWhole(): bool
    {
        return $this !== self::Cedis;
    }

    /**
     * Wording for speech, e.g. "1,250 cedis" or "6 months".
     */
    public function spoken(float|int $number): string
    {
        return match ($this) {
            self::Cedis => Cedis::spoken(Cedis::toPesewas($number)),
            self::Months => (int) $number.' '.Str::plural('month', (int) $number),
            self::Plain => number_format($number, floor($number) === (float) $number ? 0 : 2),
        };
    }
}
