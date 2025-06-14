<?php

namespace Jayesh\LaravelGeminiTranslator;

use Illuminate\Support\ServiceProvider;
use Jayesh\LaravelGeminiTranslator\Console\Commands\ExtractAndGenerateTranslationsCommand;

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
            ]);
        }
    }
}