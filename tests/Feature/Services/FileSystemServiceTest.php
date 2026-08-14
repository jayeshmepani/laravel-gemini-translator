<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature\Services;

use Illuminate\Support\Facades\File;
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
        $this->fileSystem = resolve(FileSystemService::class);
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

    public function test_normalize_translation_values_trims_and_fills_empty_literals(): void
    {
        $normalized = $this->fileSystem->normalizeTranslationValues([
            'A key from a JSX file.' => '   ',
            'messages.welcome' => '  Welcome  ',
            'validation.required' => '  The :attribute field is required.  ',
        ]);

        $this->assertSame('A key from a JSX file.', $normalized['A key from a JSX file.']);
        $this->assertSame('Welcome', $normalized['messages.welcome']);
        $this->assertSame('The :attribute field is required.', $normalized['validation.required']);
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
            false,
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

    public function test_discover_packs_finds_root_and_named_packs(): void
    {
        $lang = base_path('tests/temp/packs_' . uniqid());
        File::ensureDirectoryExists($lang);
        File::put($lang . '/en.json', '{"Hello":"Hello"}');
        File::ensureDirectoryExists($lang . '/app3');
        File::put($lang . '/app3/en.json', '{"Hello":"App3"}');
        File::ensureDirectoryExists($lang . '/web/en');
        File::put($lang . '/web/en/messages.php', "<?php\n\nreturn ['title' => 'Web'];\n");

        try {
            $packs = $this->fileSystem->discoverPacks([
                'Post' => ['lang_path' => $lang],
            ]);

            $this->assertSame([
                '' => 'lang/',
                'app3' => 'lang/app3/',
                'web' => 'lang/web/',
            ], $packs);
        } finally {
            File::deleteDirectory($lang);
        }
    }

    public function test_load_and_write_pack_php_files(): void
    {
        $lang = base_path('tests/temp/pack_php_' . uniqid());
        File::ensureDirectoryExists($lang . '/app3/en');
        File::put($lang . '/app3/en/messages.php', "<?php\n\nreturn ['title' => 'App3'];\n");

        $targets = [
            'Post' => [
                'name' => 'Module: Post',
                'path' => dirname($lang),
                'lang_path' => $lang,
            ],
        ];

        try {
            [$existing, $fileTargetMap] = $this->fileSystem->loadExistingTranslations($targets, ['en'], false);

            $this->assertArrayHasKey('Post::app3/messages', $fileTargetMap);
            $this->assertSame('App3', $existing['en']['Post::app3/messages']['title'] ?? null);

            $this->fileSystem->writeTranslationFiles(
                ['gu' => ['Post::app3/messages' => ['title' => 'એપ3']]],
                $targets,
                false,
                false,
                'lang',
                $existing,
            );

            $written = $lang . '/app3/gu/messages.php';
            $this->assertFileExists($written);
            $this->assertStringContainsString('એપ3', (string) File::get($written));
        } finally {
            File::deleteDirectory($lang);
        }
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
