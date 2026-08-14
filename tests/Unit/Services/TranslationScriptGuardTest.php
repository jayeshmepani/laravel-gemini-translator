<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Services;

use Jayesh\LaravelGeminiTranslator\Services\TranslationService;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class TranslationScriptGuardTest extends TestCase
{
    public function test_mixed_gujarati_scripts_are_rejected_and_fall_back(): void
    {
        $kannadaMatra = 'પ' . "\u{0CCB}" . 'સ્ટ ગણતરી';
        $mixedDevanagari = 'नवीनतम પોસ્ટ્સ';
        $leftoverEnglish = 'The :attribute field is required.';

        $structured = TranslationService::staticStructureTranslationsFromGemini(
            [
                'categories.posts_count' => ['gu' => $kannadaMatra],
                'categories.latest_posts' => ['gu' => $mixedDevanagari],
                'categories.required' => ['gu' => $leftoverEnglish],
                'categories.home' => ['gu' => 'ઘર'],
            ],
            ['posts_count', 'latest_posts', 'required', 'home'],
            'app::categories',
            ['gu'],
            [
                'categories.posts_count' => 'Posts count',
                'categories.latest_posts' => 'Latest Posts',
                'categories.required' => 'The :attribute field is required.',
                'categories.home' => 'Home',
            ],
        );

        $this->assertSame('Posts count', $structured['gu']['app::categories']['posts_count']);
        $this->assertSame('Latest Posts', $structured['gu']['app::categories']['latest_posts']);
        $this->assertSame('The :attribute field is required.', $structured['gu']['app::categories']['required']);
        $this->assertSame('ઘર', $structured['gu']['app::categories']['home']);
    }

    public function test_clean_gujarati_is_kept(): void
    {
        $structured = TranslationService::staticStructureTranslationsFromGemini(
            [
                'categories.posts_count' => ['gu' => 'પોસ્ટ ગણતરી'],
            ],
            ['posts_count'],
            'app::categories',
            ['gu'],
            ['categories.posts_count' => 'Posts count'],
        );

        $this->assertSame('પોસ્ટ ગણતરી', $structured['gu']['app::categories']['posts_count']);
    }
}
