<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Jayesh\LaravelGeminiTranslator\Console\Commands\ExtractAndGenerateTranslationsCommand;
use Jayesh\LaravelGeminiTranslator\Console\Commands\RunTranslationPayloadCommand;
use Jayesh\LaravelGeminiTranslator\Contracts\PromptInterface;
use Jayesh\LaravelGeminiTranslator\Contracts\TaskRunnerInterface;
use Jayesh\LaravelGeminiTranslator\Gemini\FreeTierQuotaCatalog;
use Jayesh\LaravelGeminiTranslator\Platform\OperatingSystem;
use Jayesh\LaravelGeminiTranslator\Platform\PlatformFactory;
use Jayesh\LaravelGeminiTranslator\View\Composers\ManagerViewComposer;

class TranslationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/gemini-translator.php', 'gemini-translator');

        $this->app->singleton(OperatingSystem::class);
        $this->app->singleton(PlatformFactory::class);
        $this->app->singleton(static function ($app): FreeTierQuotaCatalog {
            $quotas = $app['config']->get('gemini-translator.quotas', []);

            return FreeTierQuotaCatalog::fromConfig(is_array($quotas) ? $quotas : []);
        });

        $this->app->bind(
            PromptInterface::class,
            static fn($app): PromptInterface => $app->make(PlatformFactory::class)->prompt(),
        );

        $this->app->bind(
            TaskRunnerInterface::class,
            static fn($app): TaskRunnerInterface => $app->make(PlatformFactory::class)->taskRunner(),
        );
    }

    /** Bootstrap any application services. */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'gemini-translator');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        View::composer([
            'gemini-translator::manager',
            'gemini-translator::partials.workspace',
            'gemini-translator::errors.unauthenticated',
        ], ManagerViewComposer::class);

        $this->publishes([
            __DIR__ . '/../config/gemini-translator.php' => config_path('gemini-translator.php'),
        ], 'gemini-translator-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/gemini-translator'),
        ], 'gemini-translator-views');

        $this->publishes([
            __DIR__ . '/../resources/assets' => public_path('vendor/gemini-translator'),
        ], 'gemini-translator-assets');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/gemini-translator'),
            __DIR__ . '/../resources/assets' => public_path('vendor/gemini-translator'),
        ], 'gemini-translator-manager');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExtractAndGenerateTranslationsCommand::class,
                RunTranslationPayloadCommand::class,
            ]);
        }
    }
}
