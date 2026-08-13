<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Contracts;

use Illuminate\Console\Command;

interface PromptInterface
{
    /**
     * Ask the user to pick one or more options.
     *
     * @param array<int|string, string> $options
     * @param list<int|string>|null $default
     *
     * @return list<int|string>
     */
    public function multiselect(
        string $label,
        array $options,
        string $hint = '',
        ?array $default = null,
        ?Command $command = null,
    ): array;

    public function confirm(string $label, bool $default = false, string $hint = '', ?Command $command = null): bool;
}
