<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tasks;

use InvalidArgumentException;
use Jayesh\LaravelGeminiTranslator\Contracts\TaskRunnerInterface;

final class SyncTaskRunner implements TaskRunnerInterface
{
    public function run(
        array $payloads,
        string $handlerClass,
        int $concurrency,
        ?callable $shouldStop = null,
    ): array {
        $this->assertHandler($handlerClass);

        $results = [];
        foreach ($payloads as $payload) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            $results[] = $handlerClass::handle($payload);
        }

        return $results;
    }

    public function isParallel(): bool
    {
        return false;
    }

    public function supportsCooperativeStop(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'sync';
    }

    private function assertHandler(string $handlerClass): void
    {
        throw_unless(method_exists($handlerClass, 'handle'), InvalidArgumentException::class, "Handler {$handlerClass} must define public static handle().");
    }
}
