<?php
declare(strict_types=1);

namespace MarketBot\Core;

/**
 * Normalizes product titles/descriptions and user search queries into a
 * single canonical form so that "Телефон", "telefon" and "TELEFON" all match.
 *
 * Steps: strip tags -> lowercase -> unify apostrophes -> cyrillic-to-latin
 * transliteration (Uzbek + Russian letters) -> collapse whitespace.
 */
final class TextNormalizer
{
    private const TRANSLIT = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'yo', 'ж' => 'j', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'x', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'i', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        // Uzbek cyrillic extras
        'қ' => 'q', 'ғ' => "g'", 'ў' => "o'", 'ҳ' => 'h',
    ];

    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $s = strip_tags($text);
        $s = mb_strtolower($s, 'UTF-8');
        // Unify the many apostrophe variants used in Uzbek latin (oʻ, o`, o’ ...)
        $s = str_replace(['ʻ', 'ʼ', '`', '’', '‘', '´'], "'", $s);
        $s = strtr($s, self::TRANSLIT);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }

    /**
     * Split a normalized query into search tokens (letters/digits/apostrophe),
     * dropping empty pieces.
     *
     * @return string[]
     */
    public static function tokens(string $normalized): array
    {
        $parts = preg_split("/[^\\p{L}\\p{N}']+/u", $normalized, -1, PREG_SPLIT_NO_EMPTY);
        return $parts === false ? [] : array_values($parts);
    }
}
