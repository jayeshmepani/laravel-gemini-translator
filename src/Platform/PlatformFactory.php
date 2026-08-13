<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Platform;

use Jayesh\LaravelGeminiTranslator\Contracts\PromptInterface;
use Jayesh\LaravelGeminiTranslator\Contracts\TaskRunnerInterface;
use Jayesh\LaravelGeminiTranslator\Prompts\UnixPrompt;
use Jayesh\LaravelGeminiTranslator\Prompts\WindowsPrompt;
use Jayesh\LaravelGeminiTranslator\Tasks\SyncTaskRunner;
use Jayesh\LaravelGeminiTranslator\Tasks\UnixForkTaskRunner;
use Jayesh\LaravelGeminiTranslator\Tasks\WindowsProcessTaskRunner;
use Jayesh\LaravelGeminiTranslator\Windows\Kernel32Console;

/**
 * Routes prompt and task-runner implementations from PHP_OS_FAMILY.
 *
 * Unix: Laravel Prompts + spatie/fork
 * Windows: isolated kernel32 FFI prompts + Symfony Process
 */
final readonly class PlatformFactory
{
    public function __construct(
        private OperatingSystem $os,
    ) {}

    public function prompt(): PromptInterface
    {
        if ($this->os->isWindows()) {
            $console = Kernel32Console::isSupported() ? new Kernel32Console : null;

            return new WindowsPrompt($console);
        }

        return new UnixPrompt;
    }

    public function taskRunner(string $driver = 'default'): TaskRunnerInterface
    {
        $resolved = $this->resolveDriver($driver);

        return match ($resolved) {
            'fork' => new UnixForkTaskRunner,
            'process' => new WindowsProcessTaskRunner(
                displayName: strtolower(trim($driver)) === 'fork' ? 'fork' : 'process',
            ),
            default => new SyncTaskRunner,
        };
    }

    /**
     * Map the public --driver option onto an available implementation.
     *
     * `default` stays sequential on every OS (backward compatible).
     * `fork` uses pcntl on Unix and Symfony Process on Windows.
     * `process` always uses Symfony Process.
     * `sync` always runs in-process.
     */
    public function resolveDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));

        return match ($driver) {
            'sync' => 'sync',
            'process' => 'process',
            'fork' => $this->os->isWindows()
                ? 'process'
                : ($this->os->supportsFork() ? 'fork' : 'sync'),
            default => 'sync',
        };
    }
}
