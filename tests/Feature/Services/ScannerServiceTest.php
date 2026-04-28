<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Feature\Services;

use Jayesh\LaravelGeminiTranslator\Services\ScannerService;
use Jayesh\LaravelGeminiTranslator\Tests\TestCase;
use Symfony\Component\Finder\Finder;

class ScannerServiceTest extends TestCase
{
    private ScannerService $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = resolve(ScannerService::class);
    }

    public function test_service_is_accessible(): void
    {
        $this->assertInstanceOf(ScannerService::class, $this->scanner);
    }

    public function test_configure_finder_returns_finder_instance(): void
    {
        // Create a temp directory with test files
        $tempDir = sys_get_temp_dir() . '/scanner_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/test.php', '<?php echo __("messages.hello");');
        file_put_contents($tempDir . '/test.blade.php', '{{ __("messages.welcome") }}');
        file_put_contents($tempDir . '/readme.md', '# Not a code file');

        $finder = $this->scanner->configureFinder(
            [$tempDir],
            'vendor,node_modules',
            'php,blade.php',
            []
        );

        $this->assertInstanceOf(Finder::class, $finder);
        $this->assertGreaterThanOrEqual(2, iterator_count($finder));

        // Cleanup
        unlink($tempDir . '/test.php');
        unlink($tempDir . '/test.blade.php');
        unlink($tempDir . '/readme.md');
        rmdir($tempDir);
    }

    public function test_get_all_key_sources_merges_arrays(): void
    {
        $scannedKeys = ['messages.hello', 'auth.failed'];
        $existingTranslations = [
            'en' => [
                '__MAIN__::messages' => ['welcome' => 'Welcome', 'goodbye' => 'Goodbye'],
            ],
        ];
        $sourceTextMap = ['messages.hello' => 'Hello'];

        $result = $this->scanner->getAllKeySources($scannedKeys, $existingTranslations, $sourceTextMap);

        // Should contain all keys from all sources without duplicates
        $this->assertContains('messages.hello', $result);
        $this->assertContains('auth.failed', $result);
        $this->assertContains('messages.welcome', $result);
        $this->assertContains('messages.goodbye', $result);
    }

    public function test_get_relative_path_calculates_correctly(): void
    {
        $from = '/home/user/projects/app';
        $to = '/home/user/projects/app/src/Controller';
        $result = $this->scanner->getRelativePath($from, $to);
        $this->assertSame('src/Controller', $result);

        $from = '/home/user/projects/app';
        $to = '/home/user/projects/modules/test';
        $result = $this->scanner->getRelativePath($from, $to);
        $this->assertStringContainsString('../modules/test', $result);
    }

    public function test_populate_source_text_for_new_keys_offline_mode(): void
    {
        $allPossibleKeys = ['messages.hello', 'auth.failed', 'Welcome to the app'];
        $sourceTextMap = ['messages.hello' => 'Hello there'];
        $isOffline = true;

        $result = $this->scanner->populateSourceTextForNewKeys($allPossibleKeys, $sourceTextMap, $isOffline);

        // Should preserve existing source text
        $this->assertSame('Hello there', $result['messages.hello']);

        // Should generate placeholder for missing keys in offline mode
        $this->assertArrayHasKey('auth.failed', $result);
        $this->assertArrayHasKey('Welcome to the app', $result);
    }

    public function test_populate_source_text_for_new_keys_online_mode(): void
    {
        $allPossibleKeys = ['messages.hello', 'auth.failed'];
        $sourceTextMap = ['messages.hello' => 'Hello'];
        $isOffline = false;

        $result = $this->scanner->populateSourceTextForNewKeys($allPossibleKeys, $sourceTextMap, $isOffline);

        // In online mode, should only keep existing source text
        $this->assertSame('Hello', $result['messages.hello']);
        // Should not populate new keys
        $this->assertArrayNotHasKey('auth.failed', $result);
    }
}
