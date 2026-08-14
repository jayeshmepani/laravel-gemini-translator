<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tasks;

use Closure;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Jayesh\LaravelGeminiTranslator\Contracts\TaskRunnerInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Spawns concurrent PHP artisan workers via Symfony Process.
 *
 * Closures cannot cross a Windows process boundary (no pcntl), so each
 * payload is written to a temp file and rehydrated in a hidden command.
 */
final readonly class WindowsProcessTaskRunner implements TaskRunnerInterface
{
    private const string ALLOWED_HANDLER_PREFIX = 'Jayesh\\LaravelGeminiTranslator\\';

    /** @var Closure(list<string>): Process */
    private Closure $processFactory;

    /** @param  (callable(list<string>): Process)|null  $processFactory */
    public function __construct(
        ?callable $processFactory = null,
        private ?string $phpBinary = null,
        private ?string $artisanPath = null,
        private string $allowedHandlerPrefix = self::ALLOWED_HANDLER_PREFIX,
        private string $displayName = 'process',
    ) {
        $this->processFactory = $processFactory !== null
            ? $processFactory(...)
            : static fn(array $command): Process => new Process($command);
    }

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

        $concurrency = max(1, $concurrency);
        $results = array_fill(0, count($payloads), null);
        $pending = array_keys($payloads);
        $running = [];

        // Match UnixForkTaskRunner: start the full pool and wait. Cooperative
        // stop is not offered mid-flight (Ctrl+C terminates the parent).
        unset($shouldStop);

        while ($pending !== [] || $running !== []) {
            while (count($running) < $concurrency && $pending !== []) {
                $index = array_shift($pending);
                $running[$index] = $this->startWorker($payloads[$index], $handlerClass);
            }

            foreach ($running as $index => $worker) {
                if ($worker['process']->isRunning()) {
                    continue;
                }

                $results[$index] = $this->collect($worker, $payloads[$index]);
                unset($running[$index]);
            }

            if ($running !== []) {
                Sleep::usleep(50_000);
            }
        }

        return array_values($results);
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
        return $this->displayName;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{process: Process, in: string, out: string}
     */
    private function startWorker(array $payload, string $handlerClass): array
    {
        $inFile = $this->tempFile('lgt-in-');
        $outFile = $this->tempFile('lgt-out-');

        file_put_contents(
            $inFile,
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $command = [
            $this->phpBinary ?? PHP_BINARY,
            $this->artisanPath ?? base_path('artisan'),
            'translations:run-payload',
            '--in=' . $inFile,
            '--out=' . $outFile,
            '--handler=' . $handlerClass,
            '--no-ansi',
            '--no-interaction',
        ];

        $process = ($this->processFactory)($command);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);
        $process->setWorkingDirectory(base_path());
        $process->setInput('');
        $env = array_merge(getenv(), $_ENV);
        $process->start(null, $env);

        return [
            'process' => $process,
            'in' => $inFile,
            'out' => $outFile,
        ];
    }

    /**
     * @param array{process: Process, in: string, out: string} $worker
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function collect(array $worker, array $payload): array
    {
        try {
            if (is_file($worker['out'])) {
                $raw = file_get_contents($worker['out']);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }

            $error = trim($worker['process']->getErrorOutput() . "\n" . $worker['process']->getOutput());

            return $this->failureResult(
                $payload,
                $error !== '' ? $error : 'Worker produced no JSON result (exit ' . $worker['process']->getExitCode() . ').',
            );
        } catch (Throwable $exception) {
            return $this->failureResult($payload, $exception->getMessage());
        } finally {
            $this->deleteQuietly($worker['in']);
            $this->deleteQuietly($worker['out']);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function failureResult(array $payload, string $message): array
    {
        $originalKeys = $payload['original_keys'] ?? [];

        return [
            'status' => 'error',
            'message' => $message,
            'chunk_keys_count' => count($payload['keys'] ?? $originalKeys),
            'failed_keys' => is_array($originalKeys) ? $originalKeys : [],
            'filename' => $payload['contextual_file_key'] ?? 'unknown',
        ];
    }

    private function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary payload file.');
        }

        return $path;
    }

    private function deleteQuietly(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function assertHandler(string $handlerClass): void
    {
        if (! str_starts_with($handlerClass, $this->allowedHandlerPrefix)) {
            throw new InvalidArgumentException('Refusing to execute a handler outside the package namespace.');
        }

        if (! method_exists($handlerClass, 'handle')) {
            throw new InvalidArgumentException("Handler {$handlerClass} must define public static handle().");
        }
    }
}
