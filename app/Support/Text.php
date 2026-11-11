<?php

namespace App\Support;

/** Arabic-aware search helpers (port of front mock_store norm/matches). */
class Text
{
    public static function norm(?string $value): string
    {
        $v = mb_strtolower((string) $value);
        $v = str_replace(['أ', 'إ', 'آ'], 'ا', $v);
        $v = str_replace('ة', 'ه', $v);
        $v = str_replace('ى', 'ي', $v);

        return trim($v);
    }

    public static function matches(?string $haystack, ?string $query): bool
    {
        $q = trim((string) $query);

        return $q === '' || str_contains(self::norm($haystack), self::norm($q));
    }

    /** Arabic-Indic digits → ASCII. */
    public static function asciiDigits(string $value): string
    {
        return strtr($value, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }

    public static function mask(?string $value): string
    {
        $v = (string) $value;
        if (mb_strlen($v) <= 5) {
            return str_repeat('•', mb_strlen($v));
        }

        return mb_substr($v, 0, 2).str_repeat('•', mb_strlen($v) - 5).mb_substr($v, -3);
    }
}
