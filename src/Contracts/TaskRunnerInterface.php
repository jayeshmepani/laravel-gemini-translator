<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Contracts;

interface TaskRunnerInterface
{
    /**
     * Execute independent payloads via a static handler and return results in input order.
     *
     * @param list<array<string, mixed>> $payloads
     * @param class-string $handlerClass Must expose public static handle(array $payload): mixed
     * @param (callable(): bool)|null $shouldStop Honored only when supportsCooperativeStop() is true
     *
     * @return list<mixed>
     */
    public function run(
        array $payloads,
        string $handlerClass,
        int $concurrency,
        ?callable $shouldStop = null,
    ): array;

    public function isParallel(): bool;

    public function supportsCooperativeStop(): bool;

    public function name(): string;
}
