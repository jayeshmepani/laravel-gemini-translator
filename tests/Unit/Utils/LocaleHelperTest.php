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
}
