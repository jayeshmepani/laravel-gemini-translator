<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Platform;

use Jayesh\LaravelGeminiTranslator\Platform\OperatingSystem;
use Jayesh\LaravelGeminiTranslator\Platform\PlatformFactory;
use Jayesh\LaravelGeminiTranslator\Prompts\UnixPrompt;
use Jayesh\LaravelGeminiTranslator\Prompts\WindowsPrompt;
use Jayesh\LaravelGeminiTranslator\Tasks\SyncTaskRunner;
use Jayesh\LaravelGeminiTranslator\Tasks\UnixForkTaskRunner;
use Jayesh\LaravelGeminiTranslator\Tasks\WindowsProcessTaskRunner;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class PlatformFactoryTest extends TestCase
{
    public function test_unix_prompt_is_selected_on_linux(): void
    {
        $factory = new PlatformFactory(new OperatingSystem(family: 'Linux'));

        $this->assertInstanceOf(UnixPrompt::class, $factory->prompt());
    }

    public function test_windows_prompt_is_selected_without_loading_ffi_on_linux(): void
    {
        $factory = new PlatformFactory(new OperatingSystem(family: 'Windows', ffi: true));

        $this->assertInstanceOf(WindowsPrompt::class, $factory->prompt());
    }

    public function test_default_driver_is_sync_on_every_os(): void
    {
        $unix = new PlatformFactory(new OperatingSystem(family: 'Linux', pcntl: true));
        $windows = new PlatformFactory(new OperatingSystem(family: 'Windows', pcntl: false));

        $this->assertSame('sync', $unix->resolveDriver('default'));
        $this->assertSame('sync', $windows->resolveDriver('default'));
        $this->assertInstanceOf(SyncTaskRunner::class, $unix->taskRunner('default'));
        $this->assertInstanceOf(SyncTaskRunner::class, $windows->taskRunner('default'));
    }

    public function test_fork_on_unix_with_pcntl_uses_spatie_fork(): void
    {
        $factory = new PlatformFactory(new OperatingSystem(family: 'Linux', pcntl: true));

        $this->assertSame('fork', $factory->resolveDriver('fork'));
        $this->assertInstanceOf(UnixForkTaskRunner::class, $factory->taskRunner('fork'));
    }

    public function test_fork_on_unix_without_pcntl_falls_back_to_sync(): void
    {
        $factory = new PlatformFactory(new OperatingSystem(family: 'Linux', pcntl: false));

        $this->assertSame('sync', $factory->resolveDriver('fork'));
        $this->assertInstanceOf(SyncTaskRunner::class, $factory->taskRunner('fork'));
    }

    public function test_fork_on_windows_maps_to_process(): void
    {
        $factory = new PlatformFactory(new OperatingSystem(family: 'Windows', pcntl: false));

        $this->assertSame('process', $factory->resolveDriver('fork'));
        $runner = $factory->taskRunner('fork');
        $this->assertInstanceOf(WindowsProcessTaskRunner::class, $runner);
        $this->assertSame('fork', $runner->name());
        $this->assertFalse($runner->supportsCooperativeStop());
    }

    public function test_process_driver_is_available_on_unix_and_windows(): void
    {
        $unix = new PlatformFactory(new OperatingSystem(family: 'Linux', pcntl: true));
        $windows = new PlatformFactory(new OperatingSystem(family: 'Windows'));

        $this->assertInstanceOf(WindowsProcessTaskRunner::class, $unix->taskRunner('process'));
        $this->assertInstanceOf(WindowsProcessTaskRunner::class, $windows->taskRunner('process'));
    }

    public function test_sync_driver_is_always_sequential(): void
    {
        $factory = new PlatformFactory(new OperatingSystem(family: 'Linux', pcntl: true));

        $this->assertInstanceOf(SyncTaskRunner::class, $factory->taskRunner('sync'));
    }
}
