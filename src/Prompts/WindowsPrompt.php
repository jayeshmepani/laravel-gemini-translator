<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Prompts;

use Illuminate\Console\Command;
use Jayesh\LaravelGeminiTranslator\Contracts\InteractiveConsole;
use Jayesh\LaravelGeminiTranslator\Contracts\PromptInterface;

/**
 * Windows prompts with the same keybindings and boxed UI as Laravel Prompts.
 *
 * FFI console when available; Symfony Console choice/confirm as fallback.
 */
final readonly class WindowsPrompt implements PromptInterface
{
    public function __construct(
        private ?InteractiveConsole $console = null,
    ) {}

    public function multiselect(
        string $label,
        array $options,
        string $hint = '',
        ?array $default = null,
        ?Command $command = null,
    ): array {
        if ($this->console instanceof InteractiveConsole) {
            return (new InteractivePromptSession($this->console))->multiselect(
                $label,
                $options,
                $hint !== '' ? $hint : 'Use the space bar to select options.',
                $default,
            );
        }

        if ($command instanceof Command) {
            return $this->multiselectViaChoice($label, $options, $hint, $command);
        }

        return $default ?? array_keys($options);
    }

    public function confirm(string $label, bool $default = false, string $hint = '', ?Command $command = null): bool
    {
        if ($this->console instanceof InteractiveConsole) {
            return (new InteractivePromptSession($this->console))->confirm($label, $default, $hint);
        }

        if ($command instanceof Command) {
            if ($hint !== '') {
                $command->comment($hint);
            }

            return $command->confirm($label, $default);
        }

        return $default;
    }

    /**
     * @param array<int|string, string> $options
     *
     * @return list<int|string>
     */
    private function multiselectViaChoice(string $label, array $options, string $hint, Command $command): array
    {
        $command->line("<fg=yellow;options=bold>{$label}</>");
        if ($hint !== '') {
            $command->comment($hint);
        }

        $selection = $command->choice(
            question: $label,
            choices: array_values($options),
            default: null,
            attempts: null,
            multiple: true,
        );

        $selected = is_array($selection) ? $selection : [$selection];
        $flipped = array_flip($options);

        return array_values(array_filter(
            array_map(static fn($display) => $flipped[$display] ?? null, $selected),
            static fn($value) => $value !== null,
        ));
    }
}
