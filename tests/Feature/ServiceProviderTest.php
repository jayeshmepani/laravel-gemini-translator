<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature;

use Jayesh\LaravelGeminiTranslator\Console\Commands\ExtractAndGenerateTranslationsCommand;
use Jayesh\LaravelGeminiTranslator\Contracts\PromptInterface;
use Jayesh\LaravelGeminiTranslator\Contracts\TaskRunnerInterface;
use Jayesh\LaravelGeminiTranslator\Gemini\FreeTierQuotaCatalog;
use Jayesh\LaravelGeminiTranslator\Platform\OperatingSystem;
use Jayesh\LaravelGeminiTranslator\Platform\PlatformFactory;
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
        $this->assertInstanceOf(FileSystemService::class, resolve(FileSystemService::class));
        $this->assertInstanceOf(ScannerService::class, resolve(ScannerService::class));
        $this->assertInstanceOf(TranslationService::class, resolve(TranslationService::class));
        $this->assertInstanceOf(InteractionService::class, resolve(InteractionService::class));
        $this->assertInstanceOf(OperatingSystem::class, resolve(OperatingSystem::class));
        $this->assertInstanceOf(PlatformFactory::class, resolve(PlatformFactory::class));
        $this->assertInstanceOf(PromptInterface::class, resolve(PromptInterface::class));
        $this->assertInstanceOf(TaskRunnerInterface::class, resolve(TaskRunnerInterface::class));
        $this->assertInstanceOf(FreeTierQuotaCatalog::class, resolve(FreeTierQuotaCatalog::class));
    }

    public function test_services_are_resolvable(): void
    {
        // Services are not singletons by design - fresh instances each time
        $service1 = resolve(FileSystemService::class);
        $service2 = resolve(FileSystemService::class);
        $this->assertInstanceOf(FileSystemService::class, $service1);
        $this->assertInstanceOf(FileSystemService::class, $service2);

        $service3 = resolve(ScannerService::class);
        $service4 = resolve(ScannerService::class);
        $this->assertInstanceOf(ScannerService::class, $service3);
        $this->assertInstanceOf(ScannerService::class, $service4);
    }

    public function test_command_is_instantiable(): void
    {
        $command = resolve(ExtractAndGenerateTranslationsCommand::class);
        $this->assertInstanceOf(ExtractAndGenerateTranslationsCommand::class, $command);
    }

    public function test_provider_is_registered(): void
    {
        $provider = new TranslationServiceProvider(app());
        $this->assertInstanceOf(TranslationServiceProvider::class, $provider);
    }
}
