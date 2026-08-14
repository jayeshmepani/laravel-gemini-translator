<?php

declare(strict_types=1);

/** @phpstan-ignore-file Dynamic kernel32 / msvcrt FFI bindings are not statically typed. */

namespace Jayesh\LaravelGeminiTranslator\Windows;

use FFI;
use Jayesh\LaravelGeminiTranslator\Contracts\InteractiveConsole;
use RuntimeException;
use Throwable;

/**
 * Isolated Windows console binder (kernel32.dll + msvcrt.dll).
 *
 * Instantiated only on a real Windows runtime with FFI enabled. Provides the
 * same interactive key set Laravel Prompts uses on Unix (arrows, space, enter).
 */
final class Kernel32Console implements InteractiveConsole
{
    private const int STD_INPUT_HANDLE = -10;

    private const int STD_OUTPUT_HANDLE = -11;

    private const int ENABLE_PROCESSED_OUTPUT = 0x0001;

    private const int ENABLE_WRAP_AT_EOL_OUTPUT = 0x0002;

    private const int ENABLE_VIRTUAL_TERMINAL_PROCESSING = 0x0004;

    private const int UTF8_CODEPAGE = 65001;

    private const string KERNEL32 = <<<'C_WRAP'
    typedef void *HANDLE;
    typedef unsigned long DWORD;
    typedef int BOOL;
    typedef unsigned int UINT;

    typedef struct _COORD { short X; short Y; } COORD;
    typedef struct _SMALL_RECT { short Left; short Top; short Right; short Bottom; } SMALL_RECT;
    typedef struct _CONSOLE_SCREEN_BUFFER_INFO {
        COORD dwSize;
        COORD dwCursorPosition;
        unsigned short wAttributes;
        SMALL_RECT srWindow;
        COORD dwMaximumWindowSize;
    } CONSOLE_SCREEN_BUFFER_INFO;
    typedef struct _CONSOLE_CURSOR_INFO { DWORD dwSize; BOOL bVisible; } CONSOLE_CURSOR_INFO;

    HANDLE GetStdHandle(DWORD nStdHandle);
    BOOL GetConsoleMode(HANDLE hConsoleHandle, DWORD *lpMode);
    BOOL SetConsoleMode(HANDLE hConsoleHandle, DWORD dwMode);
    BOOL WriteConsoleW(HANDLE hConsoleOutput, const char *lpBuffer, DWORD nNumberOfCharsToWrite, DWORD *lpNumberOfCharsWritten, void *lpReserved);
    BOOL ReadConsoleW(HANDLE hConsoleInput, char *lpBuffer, DWORD nNumberOfCharsToRead, DWORD *lpNumberOfCharsRead, void *pInputControl);
    BOOL GetConsoleScreenBufferInfo(HANDLE hConsoleOutput, CONSOLE_SCREEN_BUFFER_INFO *lpConsoleScreenBufferInfo);
    BOOL SetConsoleCursorInfo(HANDLE hConsoleOutput, const CONSOLE_CURSOR_INFO *lpConsoleCursorInfo);
    BOOL SetConsoleOutputCP(UINT wCodePageID);
    BOOL SetConsoleCP(UINT wCodePageID);
    C_WRAP;

    private readonly FFI $kernel32;

    private readonly FFI $msvcrt;

    private mixed $savedOutputMode = null;

    public function __construct()
    {
        if (! self::isSupported()) {
            throw new RuntimeException('Kernel32Console requires Windows and the FFI extension.');
        }

        $this->kernel32 = FFI::cdef(self::KERNEL32, 'kernel32.dll');
        $this->msvcrt = FFI::cdef('int _getch(void);', 'msvcrt.dll');
    }

    public static function isSupported(): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            && extension_loaded('ffi')
            && class_exists(FFI::class);
    }

    public function begin(): void
    {
        $out = $this->stdHandle(self::STD_OUTPUT_HANDLE);
        if ($out !== null) {
            $mode = $this->kernel32->new('DWORD');
            if ($this->kernel32->GetConsoleMode($out, FFI::addr($mode))) {
                $this->savedOutputMode = $mode->cdata;
                $this->kernel32->SetConsoleMode(
                    $out,
                    self::ENABLE_PROCESSED_OUTPUT
                    | self::ENABLE_WRAP_AT_EOL_OUTPUT
                    | self::ENABLE_VIRTUAL_TERMINAL_PROCESSING,
                );
            }

            $this->kernel32->SetConsoleOutputCP(self::UTF8_CODEPAGE);
            $this->kernel32->SetConsoleCP(self::UTF8_CODEPAGE);
            $this->setCursorVisible($out, false);
        }

    }

    public function end(): void
    {
        $out = $this->stdHandle(self::STD_OUTPUT_HANDLE);
        if ($out !== null) {
            if ($this->savedOutputMode !== null) {
                $this->kernel32->SetConsoleMode($out, $this->savedOutputMode);
            }

            $this->setCursorVisible($out, true);
        }

        $this->write("\033[?25h");
        $this->savedOutputMode = null;
    }

    public function write(string $text): void
    {
        $handle = $this->stdHandle(self::STD_OUTPUT_HANDLE);
        if ($handle === null || ! $this->isConsole($handle)) {
            fwrite(STDOUT, $text);

            return;
        }

        $utf16 = (string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $charCount = intdiv(strlen($utf16), 2);
        if ($charCount < 1) {
            return;
        }

        $buffer = $this->kernel32->new('char[' . strlen($utf16) . ']');
        FFI::memcpy($buffer, $utf16, strlen($utf16));
        $written = $this->kernel32->new('DWORD');
        $ok = $this->kernel32->WriteConsoleW($handle, $buffer, $charCount, FFI::addr($written), null);

        if (! $ok) {
            fwrite(STDOUT, $text);
        }
    }

    public function readKey(): string
    {
        try {
            $code = (int) $this->msvcrt->_getch();
        } catch (Throwable) {
            $line = $this->readLineFallback();

            return $line === '' ? 'enter' : $line;
        }

        if ($code === 0 || $code === 224) {
            $extended = (int) $this->msvcrt->_getch();

            return match ($extended) {
                72 => 'up',
                80 => 'down',
                75 => 'left',
                77 => 'right',
                default => 'unknown',
            };
        }

        return match ($code) {
            3 => 'ctrl_c',
            13, 10 => 'enter',
            27 => 'escape',
            32 => 'space',
            default => $code > 0 && $code < 256 ? chr($code) : 'unknown',
        };
    }

    public function columns(): int
    {
        $handle = $this->stdHandle(self::STD_OUTPUT_HANDLE);
        if ($handle === null) {
            return 80;
        }

        $info = $this->kernel32->new('CONSOLE_SCREEN_BUFFER_INFO');
        if (! $this->kernel32->GetConsoleScreenBufferInfo($handle, FFI::addr($info))) {
            return 80;
        }

        $width = ((int) $info->srWindow->Right) - ((int) $info->srWindow->Left) + 1;

        return $width > 10 ? $width : 80;
    }

    public function readLine(int $maxChars = 1024): string
    {
        $maxChars = max(1, $maxChars);
        $handle = $this->stdHandle(self::STD_INPUT_HANDLE);
        if ($handle === null || ! $this->isConsole($handle)) {
            return $this->readLineFallback();
        }

        $buffer = $this->kernel32->new('char[' . ($maxChars * 2) . ']');
        $read = $this->kernel32->new('DWORD');
        $ok = $this->kernel32->ReadConsoleW($handle, $buffer, $maxChars, FFI::addr($read), null);

        if (! $ok || $read->cdata < 1) {
            return $this->readLineFallback();
        }

        $bytes = FFI::string($buffer, $read->cdata * 2);

        return rtrim((string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE'), "\r\n\0");
    }

    private function setCursorVisible(mixed $handle, bool $visible): void
    {
        $info = $this->kernel32->new('CONSOLE_CURSOR_INFO');
        $info->dwSize = 25;
        $info->bVisible = $visible ? 1 : 0;
        $this->kernel32->SetConsoleCursorInfo($handle, FFI::addr($info));
        $this->write($visible ? "\033[?25h" : "\033[?25l");
    }

    private function stdHandle(int $id): mixed
    {
        return $this->kernel32->GetStdHandle($id) ?? null;
    }

    private function isConsole(mixed $handle): bool
    {
        $mode = $this->kernel32->new('DWORD');

        return (bool) $this->kernel32->GetConsoleMode($handle, FFI::addr($mode));
    }

    private function readLineFallback(): string
    {
        $line = fgets(STDIN);

        return is_string($line) ? rtrim($line, "\r\n") : '';
    }
}
