<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Utils;

use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;

class LocaleHelperTest extends TestCase
{
    public function test_canonicalize_converts_locales(): void
    {
        $this->assertSame('en', LocaleHelper::canonicalize('en'));
        $this->assertSame('en_US', LocaleHelper::canonicalize('en-US'));
        $this->assertSame('en_US', LocaleHelper::canonicalize('en-us'));
        $this->assertSame('zh', LocaleHelper::canonicalize('zh'));
        $this->assertSame('zh_CN', LocaleHelper::canonicalize('zh-cn'));
    }

    public function test_equals_compares_locales(): void
    {
        $this->assertTrue(LocaleHelper::equals('en-US', 'en-us'));
        $this->assertTrue(LocaleHelper::equals('en_US', 'en-US'));
        $this->assertFalse(LocaleHelper::equals('en', 'fr'));
    }

    public function test_get_script_type_detects_cjk(): void
    {
        $this->assertSame('cjk', LocaleHelper::getScriptType('zh'));
        $this->assertSame('cjk', LocaleHelper::getScriptType('ja'));
        $this->assertSame('cjk', LocaleHelper::getScriptType('ko'));
    }

    public function test_get_script_type_detects_rtl(): void
    {
        $this->assertSame('rtl', LocaleHelper::getScriptType('ar'));
        $this->assertSame('rtl', LocaleHelper::getScriptType('he'));
        $this->assertSame('rtl', LocaleHelper::getScriptType('fa'));
    }

    public function test_get_script_type_detects_cyrillic(): void
    {
        $this->assertSame('cyrillic', LocaleHelper::getScriptType('ru'));
        $this->assertSame('cyrillic', LocaleHelper::getScriptType('uk'));
        $this->assertSame('cyrillic', LocaleHelper::getScriptType('bg'));
    }

    public function test_get_script_type_defaults_to_latin(): void
    {
        $this->assertSame('latin', LocaleHelper::getScriptType('en'));
        $this->assertSame('latin', LocaleHelper::getScriptType('fr'));
        $this->assertSame('latin', LocaleHelper::getScriptType('de'));
    }

    public function test_find_missing_placeholders(): void
    {
        $source = 'Hello :name, you have :count messages';
        $translated = 'Bonjour :name, vous avez :count messages';
        $this->assertEmpty(LocaleHelper::findMissing($source, $translated));

        $translatedMissing = 'Bonjour, vous avez des messages';
        $missing = LocaleHelper::findMissing($source, $translatedMissing);
        $this->assertNotEmpty($missing);
        $this->assertContains(':name', $missing);
        $this->assertContains(':count', $missing);
    }

    public function test_find_missing_brace_placeholders(): void
    {
        $source = 'Hello {name}, you have {count} messages';
        $translated = 'Bonjour {name}';
        $missing = LocaleHelper::findMissing($source, $translated);
        $this->assertContains('{count}', $missing);
    }

    public function test_humanize_for_lang_english_title_case(): void
    {
        $result = LocaleHelper::humanizeForLang('user_name_field', 'en');
        $this->assertSame('User Name Field', $result);

        $result = LocaleHelper::humanizeForLang('productId', 'en');
        $this->assertSame('Product Id', $result);
    }

    public function test_humanize_for_lang_non_english_sentence_case(): void
    {
        $result = LocaleHelper::humanizeForLang('user_name', 'fr');
        $this->assertStringStartsWith('U', $result);
        $this->assertSame('User name', $result);
    }

    public function test_humanize_preserves_cjk(): void
    {
        // CJK scripts should not be modified
        $result = LocaleHelper::humanizeForLang('some_key', 'zh');
        $this->assertSame('some key', $result);
    }

    public function test_is_latin_script(): void
    {
        $this->assertTrue(LocaleHelper::isLatinScript('Hello World'));
        $this->assertFalse(LocaleHelper::isLatinScript('你好世界'));
        $this->assertFalse(LocaleHelper::isLatinScript('Привет мир'));
    }

    public function test_writing_system_is_per_locale_not_just_family(): void
    {
        $this->assertSame('gujarati', LocaleHelper::writingSystem('gu'));
        $this->assertSame('devanagari', LocaleHelper::writingSystem('hi'));
        $this->assertSame('kannada', LocaleHelper::writingSystem('kn'));
        $this->assertSame('telugu', LocaleHelper::writingSystem('te'));
        $this->assertSame('tamil', LocaleHelper::writingSystem('ta'));
        $this->assertSame('malayalam', LocaleHelper::writingSystem('ml'));
        $this->assertSame('bengali', LocaleHelper::writingSystem('bn'));
        $this->assertSame('gurmukhi', LocaleHelper::writingSystem('pa'));
        $this->assertSame('arabic', LocaleHelper::writingSystem('ur'));
        $this->assertSame('latin', LocaleHelper::writingSystem('uz'));
        $this->assertSame('cyrillic', LocaleHelper::writingSystem('ru'));
        $this->assertSame('latin', LocaleHelper::writingSystem('sr_Latn'));
        $this->assertSame('arabic', LocaleHelper::writingSystem('pa-Arab'));
    }

    public function test_get_script_type_detects_brahmic(): void
    {
        $this->assertSame('brahmic', LocaleHelper::getScriptType('gu'));
        $this->assertSame('brahmic', LocaleHelper::getScriptType('kn'));
        $this->assertSame('brahmic', LocaleHelper::getScriptType('hi'));
    }

    public function test_has_disallowed_script_rejects_indic_mixes(): void
    {
        $this->assertTrue(LocaleHelper::hasDisallowedScript('नवीनतम પોસ્ટ્સ', 'gu'));
        $this->assertTrue(LocaleHelper::hasDisallowedScript('પ' . "\u{0CCB}" . 'સ્ટ', 'gu'));
        $this->assertTrue(LocaleHelper::hasDisallowedScript(':attribute फ़ील्ड આવશ્યક છે.', 'gu'));
        $this->assertFalse(LocaleHelper::hasDisallowedScript('નવીનતમ પોસ્ટ્સ', 'gu'));
        $this->assertFalse(LocaleHelper::hasDisallowedScript(':attribute ફીલ્ડ આવશ્યક છે.', 'gu'));
        $this->assertFalse(LocaleHelper::hasDisallowedScript('આવશ્યક છે।', 'gu'));

        $this->assertTrue(LocaleHelper::hasDisallowedScript('स्वागत છે', 'hi'));
        $this->assertTrue(LocaleHelper::hasDisallowedScript('ये रिकॉर्ड سے मेल नहीं खाते', 'hi'));
        $this->assertFalse(LocaleHelper::hasDisallowedScript('बहुत अधिक लॉगिन प्रयास।', 'hi'));

        $this->assertTrue(LocaleHelper::hasDisallowedScript('Welcome फ़ील्ड', 'en'));
        $this->assertFalse(LocaleHelper::hasDisallowedScript('The :attribute field is required.', 'en'));
    }

    public function test_looks_untranslated_detects_leftover_english(): void
    {
        $this->assertTrue(LocaleHelper::looksUntranslated('The :attribute field must match the format :format.', 'gu'));
        $this->assertTrue(LocaleHelper::looksUntranslated('The :attribute field must match the format :format.', 'hi'));
        $this->assertFalse(LocaleHelper::looksUntranslated(':attribute ફીલ્ડ આવશ્યક છે.', 'gu'));
        $this->assertFalse(LocaleHelper::looksUntranslated('JSON અનુવાદો', 'gu'));
        $this->assertFalse(LocaleHelper::looksUntranslated('The :attribute field is required.', 'fr'));
    }

    public function test_script_requirements_for_prompt_lists_each_language(): void
    {
        $block = LocaleHelper::scriptRequirementsForPrompt(['gu', 'hi', 'kn']);
        $this->assertStringContainsString('`gu`:', $block);
        $this->assertStringContainsString('Gujarati', $block);
        $this->assertStringContainsString('`hi`:', $block);
        $this->assertStringContainsString('Devanagari', $block);
        $this->assertStringContainsString('`kn`:', $block);
        $this->assertStringContainsString('Kannada', $block);
    }
}
