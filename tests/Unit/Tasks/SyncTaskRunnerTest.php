<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Tasks;

use InvalidArgumentException;
use Jayesh\LaravelGeminiTranslator\Tasks\SyncTaskRunner;
use Jayesh\LaravelGeminiTranslator\Tests\Fakes\RecordingHandler;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class SyncTaskRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingHandler::reset();
    }

    public function test_runs_payloads_in_order(): void
    {
        $runner = new SyncTaskRunner;

        $results = $runner->run(
            [['id' => 'a'], ['id' => 'b']],
            RecordingHandler::class,
            1,
        );

        $this->assertSame(['a', 'b'], array_column($results, 'id'));
        $this->assertSame(['a', 'b'], array_column(RecordingHandler::$seen, 'id'));
        $this->assertFalse($runner->isParallel());
        $this->assertTrue($runner->supportsCooperativeStop());
        $this->assertSame('sync', $runner->name());
    }

    public function test_stops_before_starting_remaining_payloads(): void
    {
        $runner = new SyncTaskRunner;
        $calls = 0;

        $results = $runner->run(
            [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            RecordingHandler::class,
            1,
            function () use (&$calls): bool {
                $calls++;

                return $calls > 1;
            },
        );

        $this->assertCount(1, $results);
        $this->assertSame(['a'], array_column(RecordingHandler::$seen, 'id'));
    }

    public function test_rejects_handler_without_handle_method(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SyncTaskRunner)->run([[]], self::class, 1);
    }
}
