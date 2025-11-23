<?php

namespace Jayesh\LaravelGeminiTranslator\Utils;

use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;

class TextHelper
{
    public static function getExtractionPatterns(): array
    {
        // Create separate patterns for different quote types to keep it simpler
        $functions = implode('|', ['__', 'trans', 'trans_choice', '@lang', '@choice', 'Lang::get', 'Lang::choice', 'Lang::has', '\$t', 'i18n\.t']);
        $attributes = implode('|', ['v-t', 'x-text']);
        // Include bound attribute variants like :v-t, :x-text, v-bind:v-t, etc.
        $boundAttributes = implode('|', [':v-t', ':x-text', 'v-bind:v-t', 'v-bind:x-text']);

        // Pattern for function calls with single quotes (with /s modifier for multi-line support)
        $patternSingle = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$functions})\s*\(\s*'((?:[^'\\\\]|\\\\.)*)'" . "/s";
        // Pattern for function calls with double quotes (with /s modifier for multi-line support)
        $patternDouble = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$functions})\s*\(\s*\"((?:[^\"\\\\]|\\\\.)*)\"" . "/s";
        // Pattern for function calls with backticks (with /s modifier for multi-line support)
        $patternBacktick = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$functions})\s*\(\s*`((?:[^`\\\\]|\\\\.)*)`" . "/s";
        // Pattern for regular attribute assignments with single quotes (with /s modifier)
        $patternAttrSingle = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$attributes})='((?:[^'\\\\]|\\\\.)*)'" . "/s";
        // Pattern for regular attribute assignments with double quotes (with /s modifier)
        $patternAttrDouble = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$attributes})=\"((?:[^\"\\\\]|\\\\.)*)\"" . "/s";
        // Pattern for regular attribute assignments with backticks (with /s modifier)
        $patternAttrBacktick = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$attributes})=`((?:[^`\\\\]|\\\\.)*)`" . "/s";
        // Pattern for bound attribute assignments with single quotes (e.g., :v-t="'messages.hello'") (with /s modifier)
        $patternBoundAttrSingle = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$boundAttributes})='((?:[^'\\\\]|\\\\.)*)'" . "/s";
        // Pattern for bound attribute assignments with double quotes (e.g., :v-t="'messages.hello'") (with /s modifier)
        $patternBoundAttrDouble = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$boundAttributes})=\"((?:[^\"\\\\]|\\\\.)*)\"" . "/s";
        // Pattern for bound attribute assignments with backticks (with /s modifier)
        $patternBoundAttrBacktick = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$boundAttributes})=`((?:[^`\\\\]|\\\\.)*)`" . "/s";
        $patterns = [$patternSingle, $patternDouble, $patternBacktick, $patternAttrSingle, $patternAttrDouble, $patternAttrBacktick, $patternBoundAttrSingle, $patternBoundAttrDouble, $patternBoundAttrBacktick];

        // Removed advanced patterns as they were extracting incorrect values
        // if (!$this->option('no-advanced')) {
        //     $commonPrefixes = implode('|', ['messages', 'validation', 'auth', 'pagination', 'passwords', 'general', 'models', 'enums', 'attributes']);
        //
        //     // Advanced patterns for single quotes
        //     $advancedSingle = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "'((?:[^'\\\\]|\\\\.)*)(?:{$commonPrefixes})[.\/][\w.-]+(?:[^'\\\\]|\\\\.)*')" . "/s";
        //     // Advanced patterns for double quotes
        //     $advancedDouble = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "\"((?:[^\"\\\\]|\\\\.)*)(?:{$commonPrefixes})[.\/][\w.-]+(?:[^\"\\\\]|\\\\.)*\")" . "/s";
        //     // Advanced patterns for backticks
        //     $advancedBacktick = "/" . "(?:route|config|asset|url|mix|old)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "`((?:[^`\\\\]|\\\\.)*)(?:{$commonPrefixes})[.\/][\w.-]+(?:[^`\\\\]|\\\\.)*`" . "/s";
        //
        //     $patterns[] = $advancedSingle;
        //     $patterns[] = $advancedDouble;
        //     $patterns[] = $advancedBacktick;
        // }
        return $patterns;
    }

    public static function extractKeyFromAttribute(string $attributeValue): string
    {
        // Pattern to match Laravel translation functions inside attribute values
        // Handle: __('messages.hello'), trans('messages.hello'), @lang('messages.hello'), etc.
        $functionPatterns = [
            '/__\s*\(\s*["\']([^"\']+)["\']\s*\)/',         // __()
            '/trans\s*\(\s*["\']([^"\']+)["\']\s*\)/',       // trans()
            '/trans_choice\s*\(\s*["\']([^"\']+)["\']\s*/',  // trans_choice()
            '/@lang\s*\(\s*["\']([^"\']+)["\']\s*\)/',       // @lang()
        ];

        foreach ($functionPatterns as $pattern) {
            if (preg_match($pattern, $attributeValue, $matches)) {
                return $matches[1];
            }
        }

        // If it's a quoted string like 'messages.hello', strip the quotes
        if (preg_match('/^["\'](.*)["\']$/', trim($attributeValue), $matches)) {
            return $matches[1];
        }

        // If it's already a plain key without quotes (for bare attribute values)
        return trim($attributeValue);
    }

    public static function hasPlaceholderMismatch(string $sourceText, string $translatedText): bool
    {
        $missingPlaceholders = LocaleHelper::findMissing($sourceText, $translatedText);
        return !empty($missingPlaceholders);
    }

    /**
     * Check if the key looks like a machine-generated key using common programming identifier patterns.
     * This method combines fast-path string checks with comprehensive regex patterns for optimal performance.
     * 
     * Detected patterns include:
     * - Laravel file structure (dot.notation): 'messages.hello', 'auth.failed'
     * - Snake case (underscore_separated): 'user_name', 'password_reset'
     * - Kebab case (hyphen-separated): 'product-id', 'api-key'
     * - camelCase: 'userName', 'productId', 'apiClientKey'
     * - PascalCase: 'UserName', 'ProductId', 'ApiClientKey'
     * - Hybrid forms: 'messages.user_profile', 'api.client-key'
     */
    public static function looksMachineKey(string $key): bool
    {

        // ===== FAST PATH: Common indicators (high performance) =====
        // Check for dots (Laravel file structure) or underscores (snake_case) first
        // These are the most common patterns in Laravel applications
        if (str_contains($key, '.') || str_contains($key, '_')) {
            return true;
        }

        // Quick check for any camelCase or PascalCase pattern (lowercase followed by uppercase)
        // This catches both 'userName' and 'UserName' patterns efficiently
        if (preg_match('/[a-z][A-Z]/', $key)) {
            return true;
        }

        // ===== COMPREHENSIVE PATTERN MATCHING =====
        // Match pure lowercase alphanumeric with allowed separators (dots, underscores, hyphens)
        // Examples: 'key123', 'my-key', 'some-value' (catches kebab-case and simple identifiers)
        if (preg_match('/^[a-z0-9._-]+$/', $key)) {
            return true;
        }

        // Match strict camelCase: starts lowercase, followed by one or more capitalized words
        // Examples: 'userName', 'productId', 'apiClientKey'
        if (preg_match('/^[a-z][a-z0-9]*([A-Z][a-z0-9]*)+$/', $key)) {
            return true;
        }

        // Match strict PascalCase: starts uppercase, followed by one or more capitalized words
        // Examples: 'UserName', 'ProductId', 'ApiClientKey'
        if (preg_match('/^[A-Z][a-z0-9]*([A-Z][a-z0-9]*)+$/', $key)) {
            return true;
        }

        // If none of the above patterns match, assume it's human-readable text
        // (e.g., "Welcome to our site", "Please enter your name")
        return false;
    }

    public static function isPluralizationString(string $text): bool
    {
        // Check if string looks like a Laravel pluralization string
        // Pattern: contains pipe character, and bracket numbers like {0}, {1}, [2,*]
        return preg_match('/\{[0-9]+\}.*\|.*\[\d+,.*\]/', $text) ||
            preg_match('/\{[0-9]+\}.*\|.*\{[0-9]+\}.*\|/', $text);
    }

    public static function translatePluralizationString(string $text, string $lang): string
    {
        // For now, just return the original string as a fallback.
        // In a more advanced implementation, we could try to extract and translate the individual text parts.
        // For example, in "{0} No posts|{1} :count post|[2,*] :count posts":
        // - Extract "No posts", ":count post", ":count posts"
        // - Translate them separately
        // - Reassemble with the same structure
        // But for now, we'll return the original as the best fallback for this complex format
        return $text;
    }

    public static function extractDisplayTextFromNamespacedKey(string $key): string
    {
        // For Laravel module namespaced keys like "Blog::messages.comments.status.approved"
        // Extract just the message part (after the ::) and convert to human-readable format
        if (str_contains($key, '::')) {
            // Split on :: and take the part after it, then process as normal
            $parts = explode('::', $key, 2);
            $messagePart = $parts[1];

            // If the message part still contains dots (like Laravel file structure), extract the final part
            if (str_contains($messagePart, '.')) {
                $finalPart = substr($messagePart, strrpos($messagePart, '.') + 1);
                return $finalPart;
            }
            return $messagePart;
        }

        // If it's a regular Laravel key like 'messages.comments.status.approved'
        // extract the final part after the last dot
        if (str_contains($key, '.')) {
            return substr($key, strrpos($key, '.') + 1);
        }

        // Otherwise return as is
        return $key;
    }
}
