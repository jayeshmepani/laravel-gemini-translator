<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Support;

use Jayesh\LaravelGeminiTranslator\Support\LanguageCatalog;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class LanguageCatalogTest extends TestCase
{
    public function test_catalog_includes_the_published_language_set(): void
    {
        $codes = LanguageCatalog::namesToCodes();

        $this->assertSame(249, count($codes));
        $this->assertSame('en', $codes['English']);
        $this->assertSame('gu', $codes['Gujarati']);
        $this->assertSame('zh_CN', $codes['Chinese (Simplified)']);
        $this->assertSame('zh_TW', $codes['Chinese (Traditional)']);
        $this->assertSame('pt_BR', $codes['Portuguese (Brazil)']);
        $this->assertSame('pt_PT', $codes['Portuguese (Portugal)']);
        $this->assertSame('pa_GURU', $codes['Punjabi (Gurmukhi)']);
        $this->assertSame('pa_ARAB', $codes['Punjabi (Shahmukhi)']);
        $this->assertSame('fr_CA', $codes['French (Canada)']);
        $this->assertSame('quc', $codes['Qʼeqchiʼ']);
    }

    public function test_display_name_matches_exact_and_short_codes(): void
    {
        $this->assertSame('Chinese (Simplified)', LanguageCatalog::displayName('zh_CN'));
        $this->assertSame('Chinese (Simplified)', LanguageCatalog::displayName('zh-CN'));
        $this->assertSame('Chinese (Simplified)', LanguageCatalog::displayName('zh'));
        $this->assertSame('Portuguese (Brazil)', LanguageCatalog::displayName('pt'));
        $this->assertSame('Punjabi (Gurmukhi)', LanguageCatalog::displayName('pa'));
        $this->assertSame('French', LanguageCatalog::displayName('fr'));
        $this->assertSame('French (Canada)', LanguageCatalog::displayName('fr-CA'));
    }
}
