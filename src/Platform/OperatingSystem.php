<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Platform;

use FFI;
use Spatie\Fork\Fork;

/**
 * Runtime platform facts used by the factory to pick adapters.
 *
 * Constructor overrides exist so tests can simulate another OS without
 * loading Windows-only FFI code on Linux CI.
 */
final readonly class OperatingSystem
{
    public function __construct(
        private ?string $family = null,
        private ?bool $pcntl = null,
        private ?bool $ffi = null,
    ) {}

    public function family(): string
    {
        return $this->family ?? PHP_OS_FAMILY;
    }

    public function isWindows(): bool
    {
        return $this->family() === 'Windows';
    }

    public function isUnix(): bool
    {
        return !$this->isWindows();
    }

    public function supportsFork(): bool
    {
        if ($this->pcntl !== null) {
            return $this->pcntl && class_exists(Fork::class);
        }

        return function_exists('pcntl_fork') && class_exists(Fork::class);
    }

    public function supportsFfi(): bool
    {
        if ($this->ffi !== null) {
            return $this->ffi;
        }

        return extension_loaded('ffi') && class_exists(FFI::class);
    }
}
