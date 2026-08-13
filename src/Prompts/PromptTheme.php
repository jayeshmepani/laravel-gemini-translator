<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Prompts;

/**
 * Visual language copied from Laravel Prompts' default theme so the
 * Windows FFI session looks and reads the same as the Unix prompts.
 */
final class PromptTheme
{
    public function cyan(string $text): string
    {
        return "\033[36m{$text}\033[0m";
    }

    public function green(string $text): string
    {
        return "\033[32m{$text}\033[0m";
    }

    public function dim(string $text): string
    {
        return "\033[2m{$text}\033[0m";
    }

    public function gray(string $text): string
    {
        return "\033[90m{$text}\033[0m";
    }

    public function box(string $title, string $body, string $hint = '', int $columns = 80): string
    {
        $max = max(20, $columns - 6);
        $bodyLines = explode("\n", $body);
        $titlePlain = $this->strip($title);
        $width = max($this->longest($bodyLines), min($this->visibleWidth($titlePlain), $max), 24);
        $width = min($width, $max);

        $titleLabel = $titlePlain !== '' ? ' ' . $this->truncate($title, $width) . ' ' : '';
        $titlePad = max(0, $width - $this->visibleWidth($this->strip($titleLabel)) + 2);
        $lines = [];
        $lines[] = $this->dim(' ┌') . $titleLabel . $this->dim(str_repeat('─', $titlePad) . '┐');

        foreach ($bodyLines as $line) {
            $padded = $this->pad($this->truncate($line, $width), $width);
            $lines[] = $this->dim(' │ ') . $padded . $this->dim(' │');
        }

        $lines[] = $this->dim(' └' . str_repeat('─', $width + 2) . '┘');

        if ($hint !== '') {
            $lines[] = ' ' . $this->dim($this->truncate($hint, $columns - 2));
        }

        return implode("\n", $lines) . "\n";
    }

    public function checkboxRow(string $label, bool $active, bool $selected): string
    {
        $mark = $selected ? '◼' : '◻';

        return match (true) {
            $active && $selected => $this->cyan('› ' . $mark) . ' ' . $label,
            $active => $this->cyan('›') . ' ' . $mark . ' ' . $label,
            $selected => '  ' . $this->cyan('◼') . ' ' . $this->dim($label),
            default => '  ' . $this->dim('◻') . ' ' . $this->dim($label),
        };
    }

    public function confirmRow(bool $confirmed): string
    {
        if ($confirmed) {
            return $this->green('●') . ' Yes ' . $this->dim('/ ○ No');
        }

        return $this->dim('○ Yes /') . ' ' . $this->green('●') . ' No';
    }

    public function submitted(string $title, string $body, int $columns = 80): string
    {
        return $this->box($this->dim($title), $body, '', $columns);
    }

    private function longest(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $max = max($max, $this->visibleWidth($this->strip((string) $line)));
        }

        return $max;
    }

    private function pad(string $text, int $width): string
    {
        $pad = max(0, $width - $this->visibleWidth($this->strip($text)));

        return $text . str_repeat(' ', $pad);
    }

    private function truncate(string $text, int $width): string
    {
        $plain = $this->strip($text);
        if ($this->visibleWidth($plain) <= $width) {
            return $text;
        }

        return mb_strimwidth($plain, 0, max(1, $width - 1), '…');
    }

    private function strip(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    private function visibleWidth(string $text): int
    {
        return mb_strwidth($text);
    }
}
