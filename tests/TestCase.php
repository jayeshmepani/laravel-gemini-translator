<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests;

use Jayesh\LaravelGeminiTranslator\TranslationServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            TranslationServiceProvider::class,
        ];
    }
}
