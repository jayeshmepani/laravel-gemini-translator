<?php

namespace Jayesh\LaravelGeminiTranslator; // <-- CORRECTED

use Illuminate\Support\ServiceProvider;
use Jayesh\LaravelGeminiTranslator\Console\Commands\ExtractAndGenerateTranslationsCommand; // <-- CORRECTED
use Jayesh\LaravelGeminiTranslator\Console\Commands\InstallCommand;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExtractAndGenerateTranslationsCommand::class,
                InstallCommand::class,
            ]);
        }
    }
}