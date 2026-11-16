<?php

namespace App\Services\Conversation;

/**
 * Normalises ASR output so lexicon matching tolerates spelling variants.
 */
final class TranscriptNormalizer
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ɛ' => 'e', 'ɔ' => 'o', 'ŋ' => 'ng', 'ʋ' => 'v', 'ẽ' => 'e']);
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? '';
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * A rough key for how a word or phrase sounds, so a transcript that is
     * spelled differently from the expected word can still match it:
     * Khaya often writes "aane" (yes) as "anni" or "a ɛnne". Spaces are
     * dropped, vowels it tends to swap are merged (e/i, o/u), similar
     * consonants are merged and doubled letters collapse.
     */
    public static function soundKey(string $text): string
    {
        $key = str_replace(' ', '', self::normalize($text));
        $key = strtr($key, ['ph' => 'f', 'v' => 'f', 'y' => 'i', 'e' => 'i', 'o' => 'u', 'w' => 'u', 'c' => 'k', 'q' => 'k', 'z' => 's']);

        return preg_replace('/(.)\1+/u', '$1', $key) ?? $key;
    }

    /**
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}
