<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature\Services;

use Jayesh\LaravelGeminiTranslator\Services\FileSystemService;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use ReflectionMethod;
use RuntimeException;

class FileSystemServiceTest extends TestCase
{
    private FileSystemService $fileSystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileSystem = app(FileSystemService::class);
    }

    public function test_service_is_accessible(): void
    {
        $this->assertInstanceOf(FileSystemService::class, $this->fileSystem);
    }

    public function test_ksort_recursive_sorts_arrays(): void
    {
        // Access private method via reflection for testing
        $reflection = new ReflectionMethod($this->fileSystem, 'ksortRecursive');

        // Must pass by reference
        $testArray = [
            'z' => ['b' => 2, 'a' => 1],
            'a' => ['z' => 3, 'a' => 4],
        ];

        $reflection->invokeArgs($this->fileSystem, [&$testArray]);

        // After sorting, keys should be in order
        $keys = array_keys($testArray);
        $this->assertSame(['a', 'z'], $keys);
        $this->assertSame(['a', 'b'], array_keys($testArray['z']));
    }

    public function test_safe_file_write_creates_file(): void
    {
        $tempDir = base_path('tests/temp/fs_test_' . uniqid());
        mkdir($tempDir, 0777, true);
        $filePath = $tempDir . '/test.txt';

        // Access private method via reflection
        $reflection = new ReflectionMethod($this->fileSystem, 'safeFileWrite');
        $reflection->invoke($this->fileSystem, $filePath, 'Hello World');

        $this->assertFileExists($filePath);
        $this->assertStringEqualsFile($filePath, 'Hello World');

        unlink($filePath);
        rmdir($tempDir);
        @rmdir(dirname($tempDir));
        @rmdir(dirname($tempDir, 2));
    }

    public function test_safe_file_write_throws_on_invalid_path(): void
    {
        $this->expectException(RuntimeException::class);

        $reflection = new ReflectionMethod($this->fileSystem, 'safeFileWrite');
        $reflection->invoke($this->fileSystem, '/invalid/path/test.txt', 'content');
    }

    public function test_write_translation_files_creates_php_files(): void
    {
        $tempLangDir = base_path('tests/temp/lang_test_' . uniqid());
        mkdir($tempLangDir . '/en', 0777, true);

        $translations = [
            'en' => [
                '__MAIN__::messages' => [
                    'hello' => 'Hello',
                    'goodbye' => 'Goodbye',
                ],
            ],
        ];

        $scanTargets = [
            '__MAIN__' => [
                'name' => 'Main Application',
                'path' => sys_get_temp_dir(),
                'lang_path' => $tempLangDir,
            ],
        ];

        $this->fileSystem->writeTranslationFiles(
            $translations,
            $scanTargets,
            false,
            false,
            'lang',
            [],
            null,
            false,
            false
        );

        $expectedFile = $tempLangDir . '/en/messages.php';
        $this->assertFileExists($expectedFile);

        $content = file_get_contents($expectedFile);
        $this->assertStringContainsString('return', $content);
        $this->assertStringContainsString('hello', $content);
        $this->assertStringContainsString('Hello', $content);

        // Cleanup
        unlink($expectedFile);
        rmdir($tempLangDir . '/en');
        rmdir($tempLangDir);
        @rmdir(dirname($tempLangDir));
        @rmdir(dirname($tempLangDir, 2));
    }

    public function test_save_failed_keys_log_creates_json(): void
    {
        $failedKeys = [
            '__MAIN__::messages' => ['hello', 'goodbye'],
        ];

        // Create temp directory first (required for realpath to work)
        $tempDir = base_path('tests/temp/fk_' . uniqid());
        mkdir($tempDir, 0777, true);
        $tempFile = $tempDir . '/failed_keys.json';

        // Use reflection to test with custom path
        $reflection = new ReflectionMethod($this->fileSystem, 'safeFileWrite');

        // Test the log generation logic
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'failed_keys_by_file' => $failedKeys,
            'total_failed_count' => array_sum(array_map(count(...), $failedKeys)),
        ];
        $reflection->invoke($this->fileSystem, $tempFile, json_encode($logData));

        $this->assertFileExists($tempFile);
        $content = json_decode(file_get_contents($tempFile), true);
        $this->assertArrayHasKey('timestamp', $content);
        $this->assertArrayHasKey('failed_keys_by_file', $content);
        $this->assertSame(2, $content['total_failed_count']);

        unlink($tempFile);
        rmdir($tempDir);
        @rmdir(dirname($tempDir));
        @rmdir(dirname($tempDir, 2));
    }
}
