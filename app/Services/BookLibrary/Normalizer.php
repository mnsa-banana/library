<?php

namespace App\Services\BookLibrary;

use Illuminate\Support\Str;

/**
 * Deterministic normalization for book identity matching (WorkResolver) and
 * the normalized_title / normalized_author columns on book_library_titles.
 */
final class Normalizer
{
    /**
     * Lowercase, strip a leading a/an/the article, transliterate diacritics,
     * drop punctuation, collapse whitespace.
     */
    public static function title(string $t): string
    {
        $t = self::basic($t);

        return trim(preg_replace('/^(a|an|the)\s+/', '', $t) ?? $t);
    }

    public static function author(?string $a): ?string
    {
        if ($a === null) {
            return null;
        }

        $a = self::basic($a);

        return $a === '' ? null : $a;
    }

    public static function authorLastName(?string $a): ?string
    {
        $normalized = self::author($a);
        if ($normalized === null) {
            return null;
        }

        $parts = explode(' ', $normalized);

        return end($parts) ?: null;
    }

    /**
     * Strip hyphens/spaces; convert ISBN-10 to ISBN-13 (978 prefix + EAN-13
     * checksum); return null for anything that is not 10/13 digits.
     */
    public static function isbn13(string $raw): ?string
    {
        $digits = preg_replace('/[\s\-]+/u', '', $raw) ?? '';

        if (preg_match('/^\d{9}[\dXx]$/', $digits)) {
            return self::isbn10To13($digits);
        }

        if (preg_match('/^\d{13}$/', $digits)) {
            return $digits;
        }

        return null;
    }

    private static function basic(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = Str::ascii($s);
        // Apostrophes vanish (L'Engle -> lengle); other punctuation becomes a
        // space so hyphenated/slashed words stay separate tokens.
        $s = str_replace(["'", '’'], '', $s);
        $s = preg_replace('/[^a-z0-9\s]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return trim($s);
    }

    private static function isbn10To13(string $isbn10): string
    {
        $core = '978'.substr($isbn10, 0, 9);

        $sum = 0;
        foreach (str_split($core) as $i => $digit) {
            $sum += ((int) $digit) * ($i % 2 === 0 ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;

        return $core.$check;
    }
}
