<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Prompts;

use Jayesh\LaravelGeminiTranslator\Prompts\InteractivePromptSession;
use Jayesh\LaravelGeminiTranslator\Tests\Fakes\FakeInteractiveConsole;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class InteractivePromptSessionTest extends TestCase
{
    public function test_multiselect_matches_laravel_prompts_space_and_enter(): void
    {
        $console = new FakeInteractiveConsole(['down', 'space', 'enter']);
        $session = new InteractivePromptSession($console);

        $selected = $session->multiselect(
            'Which targets?',
            ['__ALL__' => '-- ALL TARGETS --', 'app' => 'Main Application', 'blog' => 'Module: Blog'],
            'Use the space bar to select options.',
            ['__ALL__'],
        );

        $this->assertSame(['__ALL__', 'app'], $selected);
        $this->assertStringContainsString('›', implode('', $console->writes));
        $this->assertStringContainsString('◼', implode('', $console->writes));
        $this->assertStringContainsString('Which targets?', implode('', $console->writes));
    }

    public function test_multiselect_space_toggles_default_off(): void
    {
        $console = new FakeInteractiveConsole(['space', 'down', 'space', 'enter']);
        $session = new InteractivePromptSession($console);

        $selected = $session->multiselect(
            'Files',
            ['a' => 'Auth', 'b' => 'Blog'],
            default: ['a'],
        );

        $this->assertSame(['b'], $selected);
    }

    public function test_confirm_yes_no_matches_laravel_prompts(): void
    {
        $yes = new InteractivePromptSession(new FakeInteractiveConsole(['y']));
        $this->assertTrue($yes->confirm('Continue?', false));

        $toggle = new InteractivePromptSession(new FakeInteractiveConsole(['right', 'enter']));
        $this->assertTrue($toggle->confirm('Continue?', false));

        $keep = new InteractivePromptSession(new FakeInteractiveConsole(['enter']));
        $this->assertFalse($keep->confirm('Continue?', false));
    }

    public function test_escape_returns_defaults(): void
    {
        $console = new FakeInteractiveConsole(['escape']);
        $session = new InteractivePromptSession($console);

        $this->assertSame(['a'], $session->multiselect('X', ['a' => 'A', 'b' => 'B'], default: ['a']));
        $this->assertFalse((new InteractivePromptSession(new FakeInteractiveConsole(['escape'])))->confirm('Y', false));
    }
}
