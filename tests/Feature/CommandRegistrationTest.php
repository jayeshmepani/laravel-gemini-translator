<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Jayesh\LaravelGeminiTranslator\Console\Commands\ExtractAndGenerateTranslationsCommand;
use Jayesh\LaravelGeminiTranslator\Services\FileSystemService;
use Jayesh\LaravelGeminiTranslator\Services\InteractionService;
use Jayesh\LaravelGeminiTranslator\Services\ScannerService;
use Jayesh\LaravelGeminiTranslator\Services\TranslationService;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class CommandRegistrationTest extends TestCase
{
    public function test_command_is_registered(): void
    {
        $this->artisan('translations:extract-and-generate', ['--no-interaction' => true])
            ->assertSuccessful();
    }

    public function test_command_signature_is_correct(): void
    {
        $command = new ExtractAndGenerateTranslationsCommand(
            app()->make(FileSystemService::class),
            app()->make(ScannerService::class),
            app()->make(TranslationService::class),
            app()->make(InteractionService::class)
        );

        $this->assertSame('translations:extract-and-generate', $command->getName());
        $this->assertStringContainsString('Extracts, cross-checks, translates', $command->getDescription());
    }

    public function test_command_has_expected_options(): void
    {
        $definition = Artisan::all()['translations:extract-and-generate']->getDefinition();

        $this->assertTrue($definition->hasOption('target-dir'));
        $this->assertTrue($definition->hasOption('langs'));
        $this->assertTrue($definition->hasOption('exclude'));
        $this->assertTrue($definition->hasOption('extensions'));
        $this->assertTrue($definition->hasOption('chunk-size'));
        $this->assertTrue($definition->hasOption('driver'));
        $this->assertTrue($definition->hasOption('concurrency'));
        $this->assertTrue($definition->hasOption('skip-existing'));
        $this->assertTrue($definition->hasOption('refresh'));
        $this->assertTrue($definition->hasOption('refresh-clean'));
        $this->assertTrue($definition->hasOption('consolidate-modules'));
        $this->assertTrue($definition->hasOption('max-retries'));
        $this->assertTrue($definition->hasOption('retry-delay'));
        $this->assertTrue($definition->hasOption('stop-key'));
        $this->assertTrue($definition->hasOption('context'));
        $this->assertTrue($definition->hasOption('dry-run'));
        $this->assertTrue($definition->hasOption('model'));
        $this->assertArrayHasKey('translations:run-payload', Artisan::all());
        $this->assertTrue(Artisan::all()['translations:run-payload']->isHidden());
    }

    public function test_command_default_values(): void
    {
        $definition = Artisan::all()['translations:extract-and-generate']->getDefinition();

        $this->assertSame('lang', $definition->getOption('target-dir')->getDefault());
        $this->assertSame('en', $definition->getOption('langs')->getDefault());
        $this->assertSame('25', $definition->getOption('chunk-size')->getDefault());
        $this->assertSame('default', $definition->getOption('driver')->getDefault());
        $this->assertSame('15', $definition->getOption('concurrency')->getDefault());
        $this->assertSame('5', $definition->getOption('max-retries')->getDefault());
        $this->assertSame('3', $definition->getOption('retry-delay')->getDefault());
        $this->assertSame('q', $definition->getOption('stop-key')->getDefault());
    }
}
