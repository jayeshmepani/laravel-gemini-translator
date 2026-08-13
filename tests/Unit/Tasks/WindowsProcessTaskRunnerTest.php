<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Tasks;

use InvalidArgumentException;
use Jayesh\LaravelGeminiTranslator\Tasks\TranslationChunkHandler;
use Jayesh\LaravelGeminiTranslator\Tasks\WindowsProcessTaskRunner;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use stdClass;
use Symfony\Component\Process\Process;

class WindowsProcessTaskRunnerTest extends TestCase
{
    public function test_spawns_workers_and_reads_json_results_in_order(): void
    {
        $runner = new WindowsProcessTaskRunner(
            processFactory: function (array $command): Process {
                $outFile = $this->optionValue($command, '--out=');
                $inFile = $this->optionValue($command, '--in=');
                $payload = json_decode((string) file_get_contents($inFile), true, 512, JSON_THROW_ON_ERROR);

                file_put_contents($outFile, json_encode([
                    'status' => 'success',
                    'id' => $payload['id'],
                    'chunk_keys_count' => 1,
                ], JSON_THROW_ON_ERROR));

                return new Process([PHP_BINARY, '-r', 'exit(0);']);
            },
            allowedHandlerPrefix: 'Jayesh\\LaravelGeminiTranslator\\',
        );

        $results = $runner->run(
            [['id' => 'one'], ['id' => 'two']],
            TranslationChunkHandler::class,
            2,
        );

        $this->assertSame(['one', 'two'], array_column($results, 'id'));
        $this->assertTrue($runner->isParallel());
        $this->assertFalse($runner->supportsCooperativeStop());
        $this->assertSame('process', $runner->name());
    }

    public function test_returns_failure_result_when_worker_writes_nothing(): void
    {
        $runner = new WindowsProcessTaskRunner(
            processFactory: static fn(array $command): Process => new Process([PHP_BINARY, '-r', 'exit(1);']),
        );

        $results = $runner->run(
            [[
                'id' => 'broken',
                'keys' => ['a'],
                'original_keys' => ['a'],
                'contextual_file_key' => 'app::messages',
            ]],
            TranslationChunkHandler::class,
            1,
        );

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(['a'], $results[0]['failed_keys']);
        $this->assertSame('app::messages', $results[0]['filename']);
    }

    public function test_rejects_handlers_outside_the_package_namespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new WindowsProcessTaskRunner)->run(
            [['id' => 1]],
            stdClass::class,
            1,
        );
    }

    /** @param  list<string>  $command */
    private function optionValue(array $command, string $prefix): string
    {
        foreach ($command as $part) {
            if (str_starts_with($part, $prefix)) {
                return substr($part, strlen($prefix));
            }
        }

        $this->fail('Missing command option ' . $prefix);
    }
}
