<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Unit\Windows;

use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Jayesh\LaravelGeminiTranslator\Windows\Kernel32Console;
use RuntimeException;

class Kernel32ConsoleTest extends TestCase
{
    public function test_is_not_supported_outside_windows(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('This assertion is for Unix CI only.');
        }

        $this->assertFalse(Kernel32Console::isSupported());
    }

    public function test_constructor_refuses_to_load_kernel32_on_unix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Constructor guard is for Unix CI only.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires Windows and the FFI extension');

        new Kernel32Console;
    }
}
