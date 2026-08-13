<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Prompts;

use Illuminate\Console\Command;
use Jayesh\LaravelGeminiTranslator\Prompts\WindowsPrompt;
use Jayesh\LaravelGeminiTranslator\Tests\Fakes\FakeInteractiveConsole;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Mockery;

class WindowsPromptTest extends TestCase
{
    public function test_falls_back_to_symfony_choice_when_ffi_is_unavailable(): void
    {
        $command = Mockery::mock(Command::class);
        $command->shouldReceive('line')->once();
        $command->shouldReceive('comment')->once();
        $command->shouldReceive('choice')
            ->once()
            ->andReturn(['Main Application']);

        $prompt = new WindowsPrompt(null);

        $selected = $prompt->multiselect(
            label: 'Which targets?',
            options: ['__MAIN__' => 'Main Application', 'Blog' => 'Module: Blog'],
            hint: 'Pick one or more',
            default: ['__MAIN__'],
            command: $command,
        );

        $this->assertSame(['__MAIN__'], $selected);
    }

    public function test_returns_defaults_when_neither_ffi_nor_command_is_available(): void
    {
        $prompt = new WindowsPrompt(null);

        $selected = $prompt->multiselect(
            label: 'Which targets?',
            options: ['a' => 'A', 'b' => 'B'],
            default: ['a'],
        );

        $this->assertSame(['a'], $selected);
    }

    public function test_confirm_without_ffi_uses_symfony_confirm_when_command_is_present(): void
    {
        $command = Mockery::mock(Command::class);
        $command->shouldReceive('comment')->once();
        $command->shouldReceive('confirm')->once()->with('Continue?', false)->andReturn(true);

        $prompt = new WindowsPrompt(null);

        $this->assertTrue($prompt->confirm('Continue?', false, 'hint', $command));
    }

    public function test_confirm_without_ffi_returns_the_default(): void
    {
        $prompt = new WindowsPrompt(null);

        $this->assertFalse($prompt->confirm('Continue?', false));
        $this->assertTrue($prompt->confirm('Continue?', true));
    }

    public function test_interactive_console_uses_the_same_key_loop_as_unix(): void
    {
        $console = new FakeInteractiveConsole(['enter']);
        $prompt = new WindowsPrompt($console);

        $this->assertSame(['a'], $prompt->multiselect(
            'Pick',
            ['a' => 'A', 'b' => 'B'],
            default: ['a'],
        ));
    }
}
