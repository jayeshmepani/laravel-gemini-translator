<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Services;

use Jayesh\LaravelGeminiTranslator\Console\Commands\ExtractAndGenerateTranslationsCommand;
use Jayesh\LaravelGeminiTranslator\Services\InteractionService;
use Jayesh\LaravelGeminiTranslator\Tests\Fakes\FakePrompt;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Mockery;

class InteractionServiceTest extends TestCase
{
    public function test_single_scan_target_skips_the_prompt(): void
    {
        $prompt = new FakePrompt;
        $service = new InteractionService($prompt);

        $selected = $service->promptForScanTargets([
            '__MAIN__' => ['name' => 'Main Application'],
        ]);

        $this->assertSame(['__MAIN__'], $selected);
        $this->assertSame([], $prompt->calls);
    }

    public function test_all_targets_sentinel_expands_to_every_key(): void
    {
        $prompt = new FakePrompt(multiselectResult: ['__ALL_TARGETS__']);
        $service = new InteractionService($prompt);

        $selected = $service->promptForScanTargets([
            '__MAIN__' => ['name' => 'Main Application'],
            'Blog' => ['name' => 'Module: Blog'],
        ]);

        $this->assertSame(['__MAIN__', 'Blog'], $selected);
        $this->assertSame('multiselect', $prompt->calls[0]['type']);
    }

    public function test_non_interactive_command_returns_defaults(): void
    {
        $prompt = new FakePrompt(multiselectResult: ['should-not-be-used']);
        $service = new InteractionService($prompt);

        $command = Mockery::mock(ExtractAndGenerateTranslationsCommand::class);
        $command->shouldReceive('isInteractive')->andReturn(false);

        $selected = $service->promptForMultiChoice(
            label: 'Pick',
            options: ['a' => 'A', 'b' => 'B'],
            default: ['a'],
            command: $command,
        );

        $this->assertSame(['a'], $selected);
        $this->assertSame([], $prompt->calls);
    }

    public function test_consolidation_prompt_uses_the_adapter(): void
    {
        $prompt = new FakePrompt(confirmResult: true);
        $service = new InteractionService($prompt);

        $this->assertTrue($service->promptForConsolidation(true, false, false));
        $this->assertSame('confirm', $prompt->calls[0]['type']);
        $this->assertTrue($service->promptForConsolidation(true, false, true));
        $this->assertFalse($service->promptForConsolidation(false, false, false));
    }
}
