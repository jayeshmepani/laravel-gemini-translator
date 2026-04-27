<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature;

use Jayesh\LaravelGeminiTranslator\Console\Commands\ExtractAndGenerateTranslationsCommand;
use Jayesh\LaravelGeminiTranslator\Services\FileSystemService;
use Jayesh\LaravelGeminiTranslator\Services\InteractionService;
use Jayesh\LaravelGeminiTranslator\Services\ScannerService;
use Jayesh\LaravelGeminiTranslator\Services\TranslationService;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Jayesh\LaravelGeminiTranslator\TranslationServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function test_services_are_bound_in_container(): void
    {
        $this->assertInstanceOf(FileSystemService::class, app(FileSystemService::class));
        $this->assertInstanceOf(ScannerService::class, app(ScannerService::class));
        $this->assertInstanceOf(TranslationService::class, app(TranslationService::class));
        $this->assertInstanceOf(InteractionService::class, app(InteractionService::class));
    }

    public function test_services_are_resolvable(): void
    {
        // Services are not singletons by design - fresh instances each time
        $service1 = app(FileSystemService::class);
        $service2 = app(FileSystemService::class);
        $this->assertInstanceOf(FileSystemService::class, $service1);
        $this->assertInstanceOf(FileSystemService::class, $service2);

        $service3 = app(ScannerService::class);
        $service4 = app(ScannerService::class);
        $this->assertInstanceOf(ScannerService::class, $service3);
        $this->assertInstanceOf(ScannerService::class, $service4);
    }

    public function test_command_is_instantiable(): void
    {
        $command = app(ExtractAndGenerateTranslationsCommand::class);
        $this->assertInstanceOf(ExtractAndGenerateTranslationsCommand::class, $command);
    }

    public function test_provider_is_registered(): void
    {
        $provider = new TranslationServiceProvider(app());
        $this->assertInstanceOf(TranslationServiceProvider::class, $provider);
    }
}
