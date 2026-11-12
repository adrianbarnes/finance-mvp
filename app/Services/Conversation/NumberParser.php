<?php

namespace App\Services\Conversation;

use App\Enums\Language;

/**
 * Extracts a number from a transcript: digits ("1,500"), English number
 * words ("one thousand five hundred") or configured local number words.
 */
class NumberParser
{
    /**
     * @var array<string, int>
     */
    private const array EnglishWords = [
        'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
        'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19,
        'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
        'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
    ];

    /**
     * @var array<string, int>
     */
    private const array EnglishScales = ['thousand' => 1_000, 'million' => 1_000_000];

    public function parse(string $transcript, Language $language): ?float
    {
        if (preg_match('/\d[\d,]*(?:\.\d+)?/', $transcript, $matches) === 1) {
            return (float) str_replace(',', '', $matches[0]);
        }

        return $this->parseWords(TranscriptNormalizer::words($transcript), $language);
    }

    /**
     * @param  list<string>  $words
     */
    private function parseWords(array $words, Language $language): ?float
    {
        $localWords = config("conversation.number_words.{$language->value}", []);
        $total = 0;
        $current = 0;
        $found = false;

        foreach ($words as $word) {
            if (isset(self::EnglishWords[$word]) || isset($localWords[$word])) {
                $current += self::EnglishWords[$word] ?? $localWords[$word];
                $found = true;
            } elseif ($word === 'hundred') {
                $current = ($current ?: 1) * 100;
                $found = true;
            } elseif (isset(self::EnglishScales[$word])) {
                $total += ($current ?: 1) * self::EnglishScales[$word];
                $current = 0;
                $found = true;
            } elseif (in_array($word, ['and', 'a'], true)) {
                continue;
            } elseif ($found) {
                break;
            }
        }

        return $found ? (float) ($total + $current) : null;
    }
}
