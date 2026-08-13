<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Services;

use Jayesh\LaravelGeminiTranslator\Services\TranslationService;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class TranslationModelResolutionTest extends TestCase
{
    public function test_default_model_is_used_when_nothing_is_configured(): void
    {
        config(['gemini-translator.model' => null, 'gemini.model' => null]);

        $this->assertSame(TranslationService::DEFAULT_MODEL, TranslationService::resolveModel());
    }

    public function test_cli_override_wins_and_normalizes_models_prefix(): void
    {
        config([
            'gemini-translator.model' => 'gemini-3.5-flash-lite',
            'gemini.model' => 'gemini-2.5-flash',
        ]);

        $this->assertSame(
            'gemini-2.5-pro',
            TranslationService::resolveModel('models/Gemini-2.5-Pro'),
        );
    }

    public function test_package_config_beats_gemini_config(): void
    {
        config([
            'gemini-translator.model' => 'gemini-3.1-flash-lite',
            'gemini.model' => 'gemini-2.5-flash',
        ]);

        $this->assertSame('gemini-3.1-flash-lite', TranslationService::resolveModel());
    }

    public function test_paid_or_unknown_models_are_accepted(): void
    {
        $this->assertSame('gemini-2.5-pro', TranslationService::resolveModel('gemini-2.5-pro'));
        $this->assertSame('gemini-3.6-flash', TranslationService::resolveModel('gemini-3.6-flash'));
    }
}
