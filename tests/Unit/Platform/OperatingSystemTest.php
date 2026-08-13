<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Platform;

use Jayesh\LaravelGeminiTranslator\Platform\OperatingSystem;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;

class OperatingSystemTest extends TestCase
{
    public function test_defaults_match_runtime_family(): void
    {
        $os = new OperatingSystem;

        $this->assertSame(PHP_OS_FAMILY, $os->family());
        $this->assertSame(PHP_OS_FAMILY === 'Windows', $os->isWindows());
        $this->assertSame(PHP_OS_FAMILY !== 'Windows', $os->isUnix());
    }

    public function test_overrides_allow_simulating_windows(): void
    {
        $os = new OperatingSystem(family: 'Windows', pcntl: false, ffi: true);

        $this->assertTrue($os->isWindows());
        $this->assertFalse($os->isUnix());
        $this->assertFalse($os->supportsFork());
        $this->assertTrue($os->supportsFfi());
    }

    public function test_overrides_allow_simulating_unix_without_pcntl(): void
    {
        $os = new OperatingSystem(family: 'Linux', pcntl: false, ffi: false);

        $this->assertTrue($os->isUnix());
        $this->assertFalse($os->supportsFork());
        $this->assertFalse($os->supportsFfi());
    }
}
