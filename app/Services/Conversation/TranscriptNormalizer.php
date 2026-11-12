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
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }
}
