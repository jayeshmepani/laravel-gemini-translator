<?php

namespace Jayesh\LaravelGeminiTranslator\Utils;

class LocaleHelper
{
    /**
     * Canonicalize a locale code to Laravel's standard format.
     */
    public static function canonicalize(string $locale): string
    {
        $locale = str_replace('-', '_', $locale);
        $parts = explode('_', $locale, 2);
        $language = strtolower($parts[0]);

        if (count($parts) === 1) {
            return $language;
        }

        $region = strtoupper($parts[1]);
        return "{$language}_{$region}";
    }

    /**
     * Check if two locale codes represent the same locale.
     */
    public static function equals(string $locale1, string $locale2): bool
    {
        return self::canonicalize($locale1) === self::canonicalize($locale2);
    }

    /**
     * Get language family/script type for better humanization rules.
     */
    public static function getScriptType(string $lang): string
    {
        $lang = strtolower(self::canonicalize($lang));

        $cjkLangs = ['zh', 'ja', 'ko'];
        if (self::startsWithAny($lang, $cjkLangs))
            return 'cjk';

        $rtlLangs = ['ar', 'he', 'fa', 'ur', 'ps', 'dv'];
        if (self::startsWithAny($lang, $rtlLangs))
            return 'rtl';

        $brahmicLangs = ['hi', 'gu', 'bn', 'ta', 'te', 'ml', 'kn', 'mr', 'ne', 'si'];
        if (self::startsWithAny($lang, $brahmicLangs))
            return 'brahmic';

        $cyrillicLangs = ['ru', 'uk', 'be', 'bg', 'sr', 'mk', 'kk', 'ky', 'uz', 'az', 'mn'];
        if (self::startsWithAny($lang, $cyrillicLangs))
            return 'cyrillic';

        return 'latin';
    }

    private static function startsWithAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_starts_with($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find missing placeholders in the translated text compared to the source text.
     */
    public static function findMissing(string $source, string $translated): array
    {
        $allPlaceholders = [];

        if (preg_match_all('/:([a-zA-Z_]\w*)/', $source, $matches)) {
            $allPlaceholders = array_merge($allPlaceholders, $matches[0]);
        }
        if (preg_match_all('/\{[a-zA-Z_]\w*\}/', $source, $matches)) {
            $allPlaceholders = array_merge($allPlaceholders, $matches[0]);
        }
        if (preg_match_all('/%(?:\d+\$)?[sdxXoeEfFgGaAcpn%]/', $source, $matches)) {
            $allPlaceholders = array_merge($allPlaceholders, $matches[0]);
        }
        if (preg_match_all('/\{\d+\}/', $source, $matches)) {
            $allPlaceholders = array_merge($allPlaceholders, $matches[0]);
        }

        if (empty($allPlaceholders)) {
            return [];
        }

        $missing = [];
        foreach (array_count_values($allPlaceholders) as $placeholder => $count) {
            $translatedCount = substr_count($translated, $placeholder);
            if ($translatedCount < $count) {
                $missing = array_merge($missing, array_fill(0, $count - $translatedCount, $placeholder));
            }
        }
        return array_values($missing);
    }

    public static function isLatinScript(string $s): bool
    {
        return (bool) preg_match('/\p{Latin}/u', $s);
    }

    public static function humanizeForLang(string $s, string $lang): string
    {
        $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $s);
        $s = preg_replace('/[._-]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', trim($s));

        $scriptType = self::getScriptType($lang);

        switch ($scriptType) {
            case 'rtl':
            case 'cjk':
                break;
            case 'brahmic':
            case 'cyrillic':
            case 'latin':
            default:
                if (self::isLatinScript($s)) {
                    if (str_starts_with($lang, 'en')) {
                        // English uses title case
                        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
                    } else {
                        // Non-English Latin languages use sentence case
                        $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
                    }
                }
                break;
        }
        return $s;
    }
}
