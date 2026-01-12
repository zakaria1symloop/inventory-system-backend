<?php

namespace App\Helpers;

use ArPHP\I18N\Arabic;

class ArabicHelper
{
    protected static ?Arabic $arabic = null;

    // Arabic to Western numeral mapping
    protected static array $arabicNumerals = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Get Arabic instance (singleton)
     */
    protected static function getArabic(): Arabic
    {
        if (self::$arabic === null) {
            self::$arabic = new Arabic();
        }
        return self::$arabic;
    }

    /**
     * Convert Arabic numerals to Western numerals
     */
    public static function toWesternNumerals(string $text): string
    {
        return strtr($text, self::$arabicNumerals);
    }

    /**
     * Reshape Arabic text for PDF rendering
     * This fixes the disconnected letters issue in PDFs
     * Keeps Western numerals (does not convert to Arabic numerals)
     */
    public static function reshape(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $arabic = self::getArabic();

        // Glyphs - reshape Arabic text for PDF
        $reshaped = $arabic->utf8Glyphs($text);

        // Convert any Arabic numerals back to Western numerals
        $reshaped = self::toWesternNumerals($reshaped);

        return $reshaped;
    }

    /**
     * Check if text contains Arabic characters
     */
    public static function hasArabic(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
    }

    /**
     * Reshape text only if it contains Arabic
     * Safe version that handles null values
     */
    public static function reshapeIfArabic(?string $text, string $default = ''): string
    {
        if ($text === null || $text === '') {
            return $default;
        }

        if (self::hasArabic($text)) {
            return self::reshape($text);
        }
        return $text;
    }

    /**
     * Safe reshape - handles null and returns default if empty
     */
    public static function safe(?string $text, string $default = '-'): string
    {
        if ($text === null || trim($text) === '') {
            return $default;
        }
        return self::reshapeIfArabic($text);
    }
}
