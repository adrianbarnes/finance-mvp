<?php

namespace App\Support;

/**
 * Formatting helpers for Ghana cedi amounts stored as integer pesewas.
 */
final class Cedis
{
    public static function format(int $pesewas): string
    {
        return 'GHS '.number_format($pesewas / 100, 2);
    }

    /**
     * Wording suited to speech synthesis, e.g. "1,250 cedis 50 pesewas".
     */
    public static function spoken(int $pesewas): string
    {
        $cedis = intdiv(abs($pesewas), 100);
        $remainder = abs($pesewas) % 100;

        $phrase = number_format($cedis).' '.($cedis === 1 ? 'cedi' : 'cedis');

        if ($remainder > 0) {
            $phrase .= ' '.$remainder.' '.($remainder === 1 ? 'pesewa' : 'pesewas');
        }

        return $pesewas < 0 ? 'minus '.$phrase : $phrase;
    }

    public static function toPesewas(float|int|string $cedis): int
    {
        return (int) round(((float) $cedis) * 100);
    }
}
