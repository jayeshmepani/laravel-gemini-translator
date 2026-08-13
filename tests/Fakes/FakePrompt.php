<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Fakes;

use Illuminate\Console\Command;
use Jayesh\LaravelGeminiTranslator\Contracts\PromptInterface;

final class FakePrompt implements PromptInterface
{
    /** @var list<array{type: string, label: string}> */
    public array $calls = [];

    /** @param  list<int|string>  $multiselectResult */
    public function __construct(
        private readonly array $multiselectResult = [],
        private readonly bool $confirmResult = false,
    ) {}

    public function multiselect(
        string $label,
        array $options,
        string $hint = '',
        ?array $default = null,
        ?Command $command = null,
    ): array {
        $this->calls[] = ['type' => 'multiselect', 'label' => $label];

        return $this->multiselectResult;
    }

    public function confirm(string $label, bool $default = false, string $hint = '', ?Command $command = null): bool
    {
        $this->calls[] = ['type' => 'confirm', 'label' => $label];

        return $this->confirmResult;
    }
}
