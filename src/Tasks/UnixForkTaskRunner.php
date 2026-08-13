<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tasks;

use InvalidArgumentException;
use Jayesh\LaravelGeminiTranslator\Contracts\TaskRunnerInterface;
use RuntimeException;
use Spatie\Fork\Fork;

final class UnixForkTaskRunner implements TaskRunnerInterface
{
    public function run(
        array $payloads,
        string $handlerClass,
        int $concurrency,
        ?callable $shouldStop = null,
    ): array {
        $this->assertHandler($handlerClass);

        if ($payloads === []) {
            return [];
        }

        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException('UnixForkTaskRunner requires the pcntl extension.');
        }

        $tasks = [];
        foreach ($payloads as $payload) {
            $tasks[] = static fn(): mixed => $handlerClass::handle($payload);
        }

        return Fork::new()
            ->concurrent(max(1, $concurrency))
            ->run(...$tasks);
    }

    public function isParallel(): bool
    {
        return true;
    }

    public function supportsCooperativeStop(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'fork';
    }

    private function assertHandler(string $handlerClass): void
    {
        if (!method_exists($handlerClass, 'handle')) {
            throw new InvalidArgumentException("Handler {$handlerClass} must define public static handle().");
        }
    }
}
