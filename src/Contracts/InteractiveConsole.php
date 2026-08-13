<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Contracts;

/**
 * Lowest-level console IO used by the Windows interactive prompts.
 *
 * Unix uses Laravel Prompts instead. This seam exists so the Windows
 * session can be tested without loading kernel32.dll.
 */
interface InteractiveConsole
{
    public function write(string $text): void;

    /** One logical key: up, down, left, right, space, enter, escape, ctrl_c, y, n, or a character. */
    public function readKey(): string;

    public function begin(): void;

    public function end(): void;

    public function columns(): int;
}
