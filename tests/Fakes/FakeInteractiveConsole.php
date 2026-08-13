<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Fakes;

use Jayesh\LaravelGeminiTranslator\Contracts\InteractiveConsole;

final class FakeInteractiveConsole implements InteractiveConsole
{
    /** @var list<string> */
    public array $writes = [];

    /** @param list<string> $keys */
    public function __construct(
        private array $keys = [],
        private readonly int $width = 80,
    ) {}

    public function write(string $text): void
    {
        $this->writes[] = $text;
    }

    public function readKey(): string
    {
        if ($this->keys === []) {
            return 'enter';
        }

        return array_shift($this->keys);
    }

    public function begin(): void {}

    public function end(): void {}

    public function columns(): int
    {
        return $this->width;
    }
}
