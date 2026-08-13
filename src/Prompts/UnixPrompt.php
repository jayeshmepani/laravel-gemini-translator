<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Prompts;

use Illuminate\Console\Command;
use Jayesh\LaravelGeminiTranslator\Contracts\PromptInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

final class UnixPrompt implements PromptInterface
{
    public function multiselect(
        string $label,
        array $options,
        string $hint = '',
        ?array $default = null,
        ?Command $command = null,
    ): array {
        return array_values(multiselect(
            label: $label,
            options: $options,
            hint: $hint,
            default: $default ?? [],
        ));
    }

    public function confirm(string $label, bool $default = false, string $hint = '', ?Command $command = null): bool
    {
        return confirm(
            label: $label,
            default: $default,
            hint: $hint,
        );
    }
}
