<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Hidden worker used by WindowsProcessTaskRunner.
 *
 * Rehydrates a JSON payload and runs a package handler in a fresh PHP process.
 */
final class RunTranslationPayloadCommand extends Command
{
    private const string ALLOWED_HANDLER_PREFIX = 'Jayesh\\LaravelGeminiTranslator\\';

    protected $signature = 'translations:run-payload
                            {--in= : Absolute path to the JSON payload file}
                            {--out= : Absolute path for the JSON result file}
                            {--handler= : Fully-qualified handler class with a static handle() method}';

    protected $description = 'Internal worker: execute one translation payload in a child process.';

    protected $hidden = true;

    public function handle(): int
    {
        try {
            $inFile = (string) $this->option('in');
            $outFile = (string) $this->option('out');
            $handlerClass = (string) $this->option('handler');

            $this->assertSafePath($inFile, 'in');
            $this->assertSafePath($outFile, 'out');
            $this->assertHandler($handlerClass);

            $raw = file_get_contents($inFile);
            if ($raw === false || $raw === '') {
                throw new RuntimeException('Payload file is empty or unreadable.');
            }

            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException('Payload JSON must decode to an object/array.');
            }

            $result = $handlerClass::handle($payload);
            $written = file_put_contents(
                $outFile,
                json_encode($result, JSON_THROW_ON_ERROR),
            );

            if ($written === false) {
                throw new RuntimeException('Failed to write worker result file.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $outFile = (string) $this->option('out');
            if ($outFile !== '') {
                @file_put_contents($outFile, json_encode([
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                    'chunk_keys_count' => 0,
                    'failed_keys' => [],
                    'filename' => 'unknown',
                ]));
            }

            return self::FAILURE;
        }
    }

    private function assertHandler(string $handlerClass): void
    {
        if ($handlerClass === '' || ! str_starts_with($handlerClass, self::ALLOWED_HANDLER_PREFIX)) {
            throw new InvalidArgumentException('Refusing to execute a handler outside the package namespace.');
        }

        if (! class_exists($handlerClass) || ! method_exists($handlerClass, 'handle')) {
            throw new InvalidArgumentException("Handler {$handlerClass} is not executable.");
        }
    }

    private function assertSafePath(string $path, string $label): void
    {
        if ($path === '' || ! str_contains($path, DIRECTORY_SEPARATOR) && ! str_contains($path, '/')) {
            throw new InvalidArgumentException("Option --{$label} must be an absolute temp file path.");
        }

        $real = realpath($path);
        $temp = realpath(sys_get_temp_dir());

        if ($real === false || $temp === false) {
            throw new InvalidArgumentException("Option --{$label} must point at a file inside the system temp directory.");
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $real = strtolower($real);
            $temp = strtolower($temp);
        }

        if (! str_starts_with($real, $temp)) {
            throw new InvalidArgumentException("Option --{$label} must point at a file inside the system temp directory.");
        }
    }
}
