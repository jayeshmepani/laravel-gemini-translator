<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Utils;

class LocaleHelper
{
    /** @var array<string, string> ISO language → native writing system */
    private const array WRITING_SYSTEMS = [
        'hi' => 'devanagari',
        'mr' => 'devanagari',
        'ne' => 'devanagari',
        'sa' => 'devanagari',
        'gu' => 'gujarati',
        'bn' => 'bengali',
        'as' => 'bengali',
        'pa' => 'gurmukhi',
        'or' => 'odia',
        'ta' => 'tamil',
        'te' => 'telugu',
        'kn' => 'kannada',
        'ml' => 'malayalam',
        'si' => 'sinhala',
        'th' => 'thai',
        'lo' => 'lao',
        'km' => 'khmer',
        'my' => 'myanmar',
        'ar' => 'arabic',
        'fa' => 'arabic',
        'ur' => 'arabic',
        'ps' => 'arabic',
        'sd' => 'arabic',
        'he' => 'hebrew',
        'yi' => 'hebrew',
        'dv' => 'thaana',
        'ru' => 'cyrillic',
        'uk' => 'cyrillic',
        'be' => 'cyrillic',
        'bg' => 'cyrillic',
        'sr' => 'cyrillic',
        'mk' => 'cyrillic',
        'kk' => 'cyrillic',
        'ky' => 'cyrillic',
        'mn' => 'cyrillic',
        'tg' => 'cyrillic',
        'uz' => 'latin',
        'az' => 'latin',
        'zh' => 'han',
        'ja' => 'japanese',
        'ko' => 'hangul',
        'el' => 'greek',
        'hy' => 'armenian',
        'ka' => 'georgian',
        'am' => 'ethiopic',
        'ti' => 'ethiopic',
        'bo' => 'tibetan',
        'nqo' => 'nko',
        'mni' => 'meetei',
        'kok' => 'devanagari',
        'mai' => 'devanagari',
        'doi' => 'devanagari',
        'bho' => 'devanagari',
        'awa' => 'devanagari',
        'new' => 'devanagari',
        'mwr' => 'devanagari',
        'tcy' => 'kannada',
        'yue' => 'han',
        'ckb' => 'arabic',
        'ug' => 'arabic',
        'prs' => 'arabic',
        'shn' => 'myanmar',
        'sah' => 'cyrillic',
        'tt' => 'cyrillic',
        'ba' => 'cyrillic',
        'kv' => 'cyrillic',
        'ce' => 'cyrillic',
        'cv' => 'cyrillic',
        'os' => 'cyrillic',
        'tyv' => 'cyrillic',
        'udm' => 'cyrillic',
        'mhr' => 'cyrillic',
        'bua' => 'cyrillic',
    ];

    /** @var array<string, list<string>> Unicode script names allowed in native words */
    private const array ALLOWED_UNICODE_SCRIPTS = [
        'latin' => ['Latin'],
        'devanagari' => ['Devanagari', 'Latin'],
        'gujarati' => ['Gujarati', 'Latin'],
        'bengali' => ['Bengali', 'Latin'],
        'gurmukhi' => ['Gurmukhi', 'Latin'],
        'odia' => ['Oriya', 'Latin'],
        'tamil' => ['Tamil', 'Latin'],
        'telugu' => ['Telugu', 'Latin'],
        'kannada' => ['Kannada', 'Latin'],
        'malayalam' => ['Malayalam', 'Latin'],
        'sinhala' => ['Sinhala', 'Latin'],
        'thai' => ['Thai', 'Latin'],
        'lao' => ['Lao', 'Latin'],
        'khmer' => ['Khmer', 'Latin'],
        'myanmar' => ['Myanmar', 'Latin'],
        'arabic' => ['Arabic', 'Latin'],
        'hebrew' => ['Hebrew', 'Latin'],
        'thaana' => ['Thaana', 'Latin'],
        'cyrillic' => ['Cyrillic', 'Latin'],
        'han' => ['Han', 'Bopomofo', 'Latin'],
        'japanese' => ['Han', 'Hiragana', 'Katakana', 'Latin'],
        'hangul' => ['Hangul', 'Han', 'Latin'],
        'greek' => ['Greek', 'Latin'],
        'armenian' => ['Armenian', 'Latin'],
        'georgian' => ['Georgian', 'Latin'],
        'ethiopic' => ['Ethiopic', 'Latin'],
        'tibetan' => ['Tibetan', 'Latin'],
        'nko' => ['Nko', 'Latin'],
        'tifinagh' => ['Tifinagh', 'Latin'],
        'olchiki' => ['Ol_Chiki', 'Latin'],
        'meetei' => ['Meetei_Mayek', 'Latin'],
        'cans' => ['Canadian_Aboriginal', 'Latin'],
    ];

    /**
     * Scripts allowed in the manager malform highlighter.
     * Stricter than ALLOWED_UNICODE_SCRIPTS: Latin is not excused in native scripts.
     *
     * @var array<string, list<string>>
     */
    private const array MALFORM_ALLOWED_SCRIPTS = [
        'latin' => ['Latin'],
        'devanagari' => ['Devanagari'],
        'gujarati' => ['Gujarati'],
        'bengali' => ['Bengali'],
        'gurmukhi' => ['Gurmukhi'],
        'odia' => ['Oriya'],
        'tamil' => ['Tamil'],
        'telugu' => ['Telugu'],
        'kannada' => ['Kannada'],
        'malayalam' => ['Malayalam'],
        'sinhala' => ['Sinhala'],
        'thai' => ['Thai'],
        'lao' => ['Lao'],
        'khmer' => ['Khmer'],
        'myanmar' => ['Myanmar'],
        'arabic' => ['Arabic'],
        'hebrew' => ['Hebrew'],
        'thaana' => ['Thaana'],
        'cyrillic' => ['Cyrillic'],
        'han' => ['Han', 'Bopomofo'],
        'japanese' => ['Han', 'Hiragana', 'Katakana'],
        'hangul' => ['Hangul', 'Han'],
        'greek' => ['Greek'],
        'armenian' => ['Armenian'],
        'georgian' => ['Georgian'],
        'ethiopic' => ['Ethiopic'],
        'tibetan' => ['Tibetan'],
        'nko' => ['Nko'],
        'tifinagh' => ['Tifinagh'],
        'olchiki' => ['Ol_Chiki'],
        'meetei' => ['Meetei_Mayek'],
        'cans' => ['Canadian_Aboriginal'],
    ];

    /** @var array<string, string> Display script name => Unicode sc= property */
    private const array UNICODE_SCRIPT_PROPERTIES = [
        'Latin' => 'Latn',
        'Devanagari' => 'Deva',
        'Gujarati' => 'Gujr',
        'Bengali' => 'Beng',
        'Gurmukhi' => 'Guru',
        'Oriya' => 'Orya',
        'Tamil' => 'Taml',
        'Telugu' => 'Telu',
        'Kannada' => 'Knda',
        'Malayalam' => 'Mlym',
        'Sinhala' => 'Sinh',
        'Thai' => 'Thai',
        'Lao' => 'Laoo',
        'Khmer' => 'Khmr',
        'Myanmar' => 'Mymr',
        'Arabic' => 'Arab',
        'Hebrew' => 'Hebr',
        'Thaana' => 'Thaa',
        'Cyrillic' => 'Cyrl',
        'Han' => 'Hani',
        'Hiragana' => 'Hira',
        'Katakana' => 'Kana',
        'Hangul' => 'Hang',
        'Bopomofo' => 'Bopo',
        'Greek' => 'Grek',
        'Armenian' => 'Armn',
        'Georgian' => 'Geor',
        'Ethiopic' => 'Ethi',
        'Tibetan' => 'Tibt',
        'Nko' => 'Nkoo',
        'Tifinagh' => 'Tfng',
        'Ol_Chiki' => 'Olck',
        'Meetei_Mayek' => 'Mtei',
        'Canadian_Aboriginal' => 'Cans',
    ];

    /**
     * Primary Unicode Script (sc=), not Script_Extensions.
     * Shared Indic punctuation such as danda (।) is Common and must not
     * count as a mix of Gujarati/Kannada/Devanagari.
     *
     * @var array<string, string>
     */
    private const array UNICODE_SCRIPT_REGEX = [
        'Latin' => '/\p{sc=Latn}/u',
        'Devanagari' => '/\p{sc=Deva}/u',
        'Gujarati' => '/\p{sc=Gujr}/u',
        'Bengali' => '/\p{sc=Beng}/u',
        'Gurmukhi' => '/\p{sc=Guru}/u',
        'Oriya' => '/\p{sc=Orya}/u',
        'Tamil' => '/\p{sc=Taml}/u',
        'Telugu' => '/\p{sc=Telu}/u',
        'Kannada' => '/\p{sc=Knda}/u',
        'Malayalam' => '/\p{sc=Mlym}/u',
        'Sinhala' => '/\p{sc=Sinh}/u',
        'Thai' => '/\p{sc=Thai}/u',
        'Lao' => '/\p{sc=Laoo}/u',
        'Khmer' => '/\p{sc=Khmr}/u',
        'Myanmar' => '/\p{sc=Mymr}/u',
        'Arabic' => '/\p{sc=Arab}/u',
        'Hebrew' => '/\p{sc=Hebr}/u',
        'Thaana' => '/\p{sc=Thaa}/u',
        'Cyrillic' => '/\p{sc=Cyrl}/u',
        'Han' => '/\p{sc=Hani}/u',
        'Hiragana' => '/\p{sc=Hira}/u',
        'Katakana' => '/\p{sc=Kana}/u',
        'Hangul' => '/\p{sc=Hang}/u',
        'Bopomofo' => '/\p{sc=Bopo}/u',
        'Greek' => '/\p{sc=Grek}/u',
        'Armenian' => '/\p{sc=Armn}/u',
        'Georgian' => '/\p{sc=Geor}/u',
        'Ethiopic' => '/\p{sc=Ethi}/u',
        'Tibetan' => '/\p{sc=Tibt}/u',
        'Nko' => '/\p{sc=Nkoo}/u',
        'Tifinagh' => '/\p{sc=Tfng}/u',
        'Ol_Chiki' => '/\p{sc=Olck}/u',
        'Meetei_Mayek' => '/\p{sc=Mtei}/u',
        'Canadian_Aboriginal' => '/\p{sc=Cans}/u',
    ];

    /** Canonicalize a locale code to Laravel's standard format. */
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

    /** Check if two locale codes represent the same locale. */
    public static function equals(string $locale1, string $locale2): bool
    {
        return self::canonicalize($locale1) === self::canonicalize($locale2);
    }

    /** Get language family/script type for better humanization rules. */
    public static function getScriptType(string $lang): string
    {
        return match (self::writingSystem($lang)) {
            'han', 'japanese', 'hangul' => 'cjk',
            'arabic', 'hebrew', 'thaana' => 'rtl',
            'devanagari', 'gujarati', 'bengali', 'gurmukhi', 'odia',
            'tamil', 'telugu', 'kannada', 'malayalam', 'sinhala',
            'thai', 'lao', 'khmer', 'myanmar' => 'brahmic',
            'cyrillic' => 'cyrillic',
            default => 'latin',
        };
    }

    /** Native writing system for this locale (more specific than getScriptType). */
    public static function writingSystem(string $lang): string
    {
        $canonical = strtolower(self::canonicalize($lang));

        if (str_contains($canonical, '_latn')) {
            return 'latin';
        }

        if (str_contains($canonical, '_cyrl')) {
            return 'cyrillic';
        }

        if (str_contains($canonical, '_arab')) {
            return 'arabic';
        }

        if (str_contains($canonical, '_deva')) {
            return 'devanagari';
        }

        if (str_contains($canonical, '_guru')) {
            return 'gurmukhi';
        }

        if (str_contains($canonical, '_hans') || str_contains($canonical, '_hant')) {
            return 'han';
        }

        if (str_contains($canonical, '_tfng')) {
            return 'tifinagh';
        }

        if (str_contains($canonical, '_olck')) {
            return 'olchiki';
        }

        if (str_contains($canonical, '_syll')) {
            return 'cans';
        }

        $base = explode('_', $canonical)[0];

        return self::WRITING_SYSTEMS[$base] ?? 'latin';
    }

    /** One-line instruction for the Gemini prompt for this locale. */
    public static function writingSystemInstruction(string $lang): string
    {
        return match (self::writingSystem($lang)) {
            'gujarati' => 'Gujarati script only (અ આ ઇ પો ફીલ્ડ આવશ્યક). Never Devanagari (न फ़ील्ड नवीनतम), never Kannada/Telugu lookalike matras (ೋ ో), never Urdu/Arabic.',
            'devanagari' => 'Devanagari only (अ आ फ़ील्ड से). Never Gujarati (ફીલ્ડ), never Arabic/Urdu (سے), never Kannada/Telugu matras (ೋ ో).',
            'kannada' => 'Kannada script only (ಅ ಆ ಪೋಸ್ಟ್). Never Devanagari, Gujarati, or Telugu lookalike letters.',
            'telugu' => 'Telugu script only (అ ఆ). Never Kannada, Devanagari, or Gujarati lookalike letters.',
            'tamil' => 'Tamil script only (அ ஆ). Never other Indic scripts.',
            'malayalam' => 'Malayalam script only (അ ആ). Never other Indic scripts.',
            'bengali' => 'Bengali/Assamese script only (অ আ). Never Devanagari or other Indic scripts.',
            'gurmukhi' => 'Gurmukhi script only (ਅ ਆ). Never Devanagari or Arabic (unless the locale is pa_Arab).',
            'odia' => 'Odia script only (ଅ ଆ). Never other Indic scripts.',
            'sinhala' => 'Sinhala script only (අ ආ). Never other Indic scripts.',
            'thai' => 'Thai script only. Never other Southeast Asian or Indic scripts.',
            'lao' => 'Lao script only.',
            'khmer' => 'Khmer script only.',
            'myanmar' => 'Myanmar script only.',
            'arabic' => 'Arabic script only (including Urdu/Persian letters when that is the locale). Never Devanagari, Gujarati, or Latin sentences.',
            'hebrew' => 'Hebrew script only. Never Arabic or Latin sentences.',
            'thaana' => 'Thaana script only.',
            'cyrillic' => 'Cyrillic script only. Never Latin sentences (except placeholders and proper nouns).',
            'han' => 'Han characters. Never Hiragana, Katakana, Hangul, or Indic scripts.',
            'japanese' => 'Japanese (Hiragana, Katakana, Kanji). Never Hangul, Arabic, or Indic scripts.',
            'hangul' => 'Hangul (Han allowed for Sino-Korean). Never Hiragana, Katakana, or Indic scripts.',
            'greek' => 'Greek script. Never Cyrillic lookalikes for native words.',
            'armenian' => 'Armenian script only.',
            'georgian' => 'Georgian script only.',
            'ethiopic' => 'Ethiopic script only.',
            default => 'Latin script. Never Devanagari, Gujarati, Kannada, Arabic, Cyrillic, or CJK letters.',
        };
    }

    /** Bullet list of writing-system rules for the languages in this request. */
    public static function scriptRequirementsForPrompt(array $languages): string
    {
        $lines = [];
        foreach ($languages as $language) {
            if (! is_string($language) || $language === '') {
                continue;
            }

            $lines[] = '- `' . $language . '`: ' . self::writingSystemInstruction($language);
        }

        return implode("\n", $lines);
    }

    /**
     * True when the string contains letters from a writing system that does not
     * belong to this locale (e.g. Devanagari or Kannada ೋ inside Gujarati).
     * Placeholders and HTML entities are ignored. Latin is allowed for tokens.
     */
    public static function hasDisallowedScript(string $text, string $lang): bool
    {
        $stripped = self::stripPlaceholdersForScriptCheck($text);
        $allowed = self::ALLOWED_UNICODE_SCRIPTS[self::writingSystem($lang)] ?? ['Latin'];

        foreach (self::UNICODE_SCRIPT_REGEX as $script => $pattern) {
            if (in_array($script, $allowed, true)) {
                continue;
            }

            if (preg_match($pattern, $stripped) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a non-Latin locale has no native letters and still contains a
     * leftover English sentence (after placeholders and tech acronyms).
     */
    public static function looksUntranslated(string $text, string $lang): bool
    {
        if (self::writingSystem($lang) === 'latin') {
            return false;
        }

        $stripped = self::stripPlaceholdersForScriptCheck($text);
        $stripped = preg_replace(
            '/\b(?:URL|UUID|ULID|JSON|AJAX|HTML|CSS|JSX|TSX|PHP|API|IPv4|IPv6|MAC|ASCII|HTTP|HTTPS|XML|SQL|Vue|TypeScript|ID|OK|IP)\b/',
            '',
            $stripped,
        ) ?? $stripped;

        $allowed = self::ALLOWED_UNICODE_SCRIPTS[self::writingSystem($lang)] ?? ['Latin'];
        foreach (self::UNICODE_SCRIPT_REGEX as $script => $pattern) {
            if ($script === 'Latin' || ! in_array($script, $allowed, true)) {
                continue;
            }

            if (preg_match($pattern, $stripped) === 1) {
                return false;
            }
        }

        return preg_match_all('/[A-Za-z]{3,}/', $stripped) >= 3;
    }

    /**
     * Scripts found in $text that do not belong to $lang (manager highlighter).
     * Placeholders, HTML entities, and Laravel plural tokens are ignored.
     * Latin letters in a non-Latin locale are treated as a fault.
     *
     * @return list<string>
     */
    public static function malformReasons(string $text, string $lang): array
    {
        $stripped = trim(self::stripPlaceholdersForScriptCheck($text));
        if ($stripped === '') {
            return [];
        }

        $allowed = self::MALFORM_ALLOWED_SCRIPTS[self::writingSystem($lang)] ?? ['Latin'];
        $found = [];

        foreach (self::UNICODE_SCRIPT_REGEX as $script => $pattern) {
            if (in_array($script, $allowed, true)) {
                continue;
            }

            if (preg_match($pattern, $stripped) === 1) {
                $found[] = $script;
            }
        }

        return $found;
    }

    /**
     * Catalog consumed by the manager JS detector so rules stay in one place.
     *
     * @return array{systems: array<string, string>, allowed: array<string, list<string>>, properties: array<string, string>}
     */
    public static function detectorCatalog(): array
    {
        return [
            'systems' => self::WRITING_SYSTEMS,
            'allowed' => self::MALFORM_ALLOWED_SCRIPTS,
            'properties' => self::UNICODE_SCRIPT_PROPERTIES,
        ];
    }

    /** Find missing placeholders in the translated text compared to the source text. */
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

        if ($allPlaceholders === []) {
            return [];
        }

        $missing = [];
        foreach (array_count_values($allPlaceholders) as $placeholder => $count) {
            $translatedCount = substr_count($translated, $placeholder);
            if ($translatedCount < $count) {
                $missing = array_merge($missing, array_fill(0, $count - $translatedCount, $placeholder));
            }
        }

        return $missing;
    }

    public static function isLatinScript(string $s): bool
    {
        return (bool) preg_match('/\p{Latin}/u', $s);
    }

    public static function humanizeForLang(string $s, string $lang): string
    {
        $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $s);
        $s = preg_replace('/[._-]+/u', ' ', (string) $s);
        $s = preg_replace('/\s+/u', ' ', trim((string) $s));

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
                        $s = mb_convert_case((string) $s, MB_CASE_TITLE, 'UTF-8');
                    } else {
                        // Non-English Latin languages use sentence case
                        $s = mb_strtoupper(mb_substr((string) $s, 0, 1)) . mb_substr((string) $s, 1);
                    }
                }

                break;
        }

        return $s;
    }

    private static function stripPlaceholdersForScriptCheck(string $text): string
    {
        $text = preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '', $text) ?? $text;
        $text = preg_replace('/\{[A-Za-z0-9_]+\}/', '', $text) ?? $text;
        $text = preg_replace('/\[[^\]]+\]/', '', $text) ?? $text;
        $text = preg_replace('/&[a-zA-Z]+;/', '', $text) ?? $text;
        $text = preg_replace('/%(?:\d+\$)?[sdxXoeEfFgGaAcpn%]/', '', $text) ?? $text;

        return $text;
    }
}
