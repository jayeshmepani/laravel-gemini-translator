<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Prompts;

use Jayesh\LaravelGeminiTranslator\Contracts\InteractiveConsole;

/** Laravel Prompts-equivalent key loop (arrows, space, enter) used on Windows. */
final readonly class InteractivePromptSession
{
    private const int SCROLL = 5;

    public function __construct(
        private InteractiveConsole $console,
        private PromptTheme $theme = new PromptTheme,
    ) {}

    /**
     * @param array<int|string, string> $options
     * @param list<int|string>|null $default
     *
     * @return list<int|string>
     */
    public function multiselect(string $label, array $options, string $hint = '', ?array $default = null): array
    {
        $keys = array_keys($options);
        if ($keys === []) {
            return [];
        }

        $selected = [];
        foreach ($default ?? [] as $value) {
            if (in_array($value, $keys, true)) {
                $selected[] = $value;
            }
        }

        $highlighted = 0;
        $firstVisible = 0;
        $lines = 0;

        $this->console->begin();
        try {
            while (true) {
                $frame = $this->renderMultiselect($label, $options, $keys, $selected, $highlighted, $firstVisible, $hint);
                $this->paint($frame, $lines);
                $key = $this->console->readKey();

                if ($key === 'enter') {
                    $done = $this->theme->submitted(
                        $label,
                        $selected === [] ? $this->theme->gray('None') : implode("\n", array_map(
                            static fn($item) => $options[$item],
                            $selected,
                        )),
                        $this->console->columns(),
                    );
                    $this->paint($done, $lines);

                    return $selected;
                }

                if ($key === 'escape' || $key === 'ctrl_c') {
                    return $default ?? [];
                }

                if ($key === 'up') {
                    $highlighted = ($highlighted - 1 + count($keys)) % count($keys);
                } elseif ($key === 'down') {
                    $highlighted = ($highlighted + 1) % count($keys);
                } elseif ($key === 'space') {
                    $current = $keys[$highlighted];
                    $index = array_search($current, $selected, true);
                    if ($index === false) {
                        $selected[] = $current;
                    } else {
                        unset($selected[$index]);
                        $selected = array_values($selected);
                    }
                }

                if ($highlighted < $firstVisible) {
                    $firstVisible = $highlighted;
                } elseif ($highlighted >= $firstVisible + self::SCROLL) {
                    $firstVisible = $highlighted - self::SCROLL + 1;
                }
            }
        } finally {
            $this->console->end();
        }
    }

    public function confirm(string $label, bool $default = false, string $hint = ''): bool
    {
        $confirmed = $default;
        $lines = 0;

        $this->console->begin();
        try {
            while (true) {
                $frame = $this->theme->box(
                    $this->theme->cyan($label),
                    $this->theme->confirmRow($confirmed),
                    $hint,
                    $this->console->columns(),
                );
                $this->paint($frame, $lines);
                $key = $this->console->readKey();

                if ($key === 'enter') {
                    $this->paint($this->theme->submitted($label, $confirmed ? 'Yes' : 'No', $this->console->columns()), $lines);

                    return $confirmed;
                }

                if ($key === 'escape' || $key === 'ctrl_c') {
                    return $default;
                }

                if (in_array($key, ['y', 'Y'], true)) {
                    return true;
                }

                if (in_array($key, ['n', 'N'], true)) {
                    return false;
                }

                if (in_array($key, ['left', 'right', 'up', 'down', 'space'], true)) {
                    $confirmed = ! $confirmed;
                }
            }
        } finally {
            $this->console->end();
        }
    }

    /**
     * @param array<int|string, string> $options
     * @param list<int|string> $keys
     * @param list<int|string> $selected
     */
    private function renderMultiselect(
        string $label,
        array $options,
        array $keys,
        array $selected,
        int $highlighted,
        int $firstVisible,
        string $hint,
    ): string {
        $visible = array_slice($keys, $firstVisible, self::SCROLL);
        $rows = [];
        foreach ($visible as $offset => $key) {
            $index = $firstVisible + $offset;
            $rows[] = $this->theme->checkboxRow(
                $options[$key],
                $index === $highlighted,
                in_array($key, $selected, true),
            );
        }

        return $this->theme->box(
            $this->theme->cyan($label),
            implode("\n", $rows),
            $hint !== '' ? $hint : 'Use the space bar to select options.',
            $this->console->columns(),
        );
    }

    private function paint(string $frame, int &$previousLines): void
    {
        if ($previousLines > 0) {
            $this->console->write("\033[{$previousLines}A\033[J");
        }

        $this->console->write($frame);
        $previousLines = substr_count($frame, "\n");
        if (! str_ends_with($frame, "\n")) {
            $previousLines++;
        }
    }
}
