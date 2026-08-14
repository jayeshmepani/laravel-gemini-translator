<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Services;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;
use RuntimeException;
use Symfony\Component\Finder\Finder;

class FileSystemService
{
    /**
     * Load existing translations from all specified targets.
     *
     * @param array $targets Array of scan targets
     * @param array $langs Target languages
     * @param bool $consolidateModules Whether to consolidate modules
     * @param mixed $output Output object for displaying messages
     *
     * @return array Array containing [existingTranslations, fileTargetMap, sourceTextMap, keyOriginMap]
     */
    public function loadExistingTranslations(array $targets, array $langs, bool $consolidateModules, $output = null, ?array $selectedPacks = null): array
    {
        $existingTranslations = [];
        $fileTargetMap = [];
        $sourceTextMap = [];
        $keyOriginMap = [];

        if ($output !== null) {
            $output->writeln('Reading existing language files from selected targets...');
        }

        // Only load the specified target languages plus 'en' as source
        // Do NOT load all available language directories
        $languagesToLoad = array_unique(array_merge(['en'], $langs));

        foreach ($targets as $targetKey => $target) {
            $baseLangPath = $target['lang_path'];
            if (! File::isDirectory($baseLangPath)) {
                continue;
            }

            $origin = $consolidateModules ? '__MAIN__' : $targetKey;
            $phpTrees = $this->phpTreesFor($baseLangPath, $selectedPacks);

            foreach ($phpTrees as $pack => $treeBase) {
                foreach (File::directories($treeBase) as $langDirPath) {
                    if (! $this->isLocaleDirectory($langDirPath)) {
                        continue;
                    }
                    $canonicalLang = LocaleHelper::canonicalize(basename((string) $langDirPath));

                    if (! in_array($canonicalLang, $languagesToLoad, true)) {
                        continue;
                    }

                    foreach (File::allFiles($langDirPath) as $file) {
                        if ($file->getExtension() !== 'php') {
                            continue;
                        }

                        $relativePath = str_replace(['.php', DIRECTORY_SEPARATOR], ['', '/'], $file->getRelativePathname());
                        $fileKey = $pack === '' ? $relativePath : $pack . '/' . $relativePath;
                        $contextualFileKey = $origin . '::' . $fileKey;
                        $group = basename($relativePath);

                        $includedData = @include $file->getPathname();
                        if (is_array($includedData)) {
                            $flatData = Arr::dot($includedData);
                            $existingTranslations[$canonicalLang][$contextualFileKey] = $flatData;
                            $fileTargetMap[$contextualFileKey] = $origin;

                            foreach ($flatData as $keySuffix => $text) {
                                if (is_string($text)) {
                                    $fullKey = "{$group}.{$keySuffix}";
                                    if ($canonicalLang === 'en' || ! isset($sourceTextMap[$fullKey])) {
                                        $sourceTextMap[$fullKey] = $text;
                                    }

                                    if (! isset($keyOriginMap[$fullKey])) {
                                        $keyOriginMap[$fullKey] = $origin;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $jsonFinder = new Finder;
            $jsonFinder->files()->in($baseLangPath)->name('*.json');

            foreach ($jsonFinder as $jsonFile) {
                $dirName = $jsonFile->getFilenameWithoutExtension();
                $canonicalLang = LocaleHelper::canonicalize($dirName);

                if (! in_array($canonicalLang, $languagesToLoad, true)) {
                    continue;
                }

                $relativePath = $jsonFile->getRelativePath();
                $pack = $relativePath === '' ? '' : explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $relativePath))[0];
                if ($selectedPacks !== null && ! in_array($pack, $selectedPacks, true)) {
                    continue;
                }
                $fileKey = $relativePath !== '' ? rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/') . '/' . '__JSON__' : '__JSON__';
                $contextualFileKey = $origin . '::' . $fileKey;

                $jsonContent = json_decode($jsonFile->getContents(), true);
                if (is_array($jsonContent)) {
                    $existingTranslations[$canonicalLang][$contextualFileKey] = $jsonContent;
                    $fileTargetMap[$contextualFileKey] = $origin;
                    foreach ($jsonContent as $key => $text) {
                        if (is_string($text) && ($canonicalLang === 'en' || ! isset($sourceTextMap[$key]))) {
                            $sourceTextMap[$key] = $text;
                        }

                        if (! isset($keyOriginMap[$key])) {
                            $keyOriginMap[$key] = $origin;
                        }
                    }
                }
            }
        }

        return [$existingTranslations, $fileTargetMap, $sourceTextMap, $keyOriginMap];
    }

    /**
     * Packs found under the selected lang trees ('' is lang/).
     *
     * @param array<string, array{lang_path?: string}> $targets
     *
     * @return array<string, string> pack id => label
     */
    public function discoverPacks(array $targets): array
    {
        $packs = [];
        foreach ($targets as $target) {
            $base = $target['lang_path'] ?? '';
            if ($base === '' || ! File::isDirectory($base)) {
                continue;
            }
            if ($this->hasRootLangContent($base)) {
                $packs[''] = 'lang/';
            }
            foreach (File::directories($base) as $dir) {
                if (! $this->isPackDirectory($dir)) {
                    continue;
                }
                $name = basename($dir);
                $packs[$name] = 'lang/' . $name . '/';
            }
        }

        uksort($packs, static function (string $left, string $right): int {
            if ($left === '') {
                return -1;
            }
            if ($right === '') {
                return 1;
            }

            return strcasecmp($left, $right);
        });

        return $packs;
    }

    /** Load Laravel framework translations */
    public function loadFrameworkTranslations(array $currentData, string $targetDir, array $targetLanguages, bool $dryRun = false, $output = null): array
    {
        $existingTranslations = $currentData[0] ?? [];
        $fileTargetMap = $currentData[1] ?? [];
        $sourceTextMap = $currentData[2] ?? [];
        $keyOriginMap = $currentData[3] ?? [];

        if ($output) {
            $output->writeln('Reading Laravel framework default language files...');
        }

        $frameworkLangPath = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        if (! File::isDirectory($frameworkLangPath)) {
            if ($output) {
                $output->writeln('<fg=yellow>Could not find Laravel framework language directory. Skipping.</>');
            }

            return [$existingTranslations, $fileTargetMap, $sourceTextMap, $keyOriginMap];
        }

        // Root app lang dir (main application only – modules can use their own stuff)
        $rootLangBase = base_path($targetDir);
        $rootEnDir = $rootLangBase . DIRECTORY_SEPARATOR . LocaleHelper::canonicalize('en');

        foreach (File::files($frameworkLangPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getFilenameWithoutExtension();   // e.g. auth
            $contextualFileKey = '__MAIN__' . '::' . $filename;

            // 1) Load framework (vendor) nested array
            $frameworkData = @include $file->getPathname();
            if (! is_array($frameworkData)) {
                continue;
            }

            // 2) Load existing app file (lang/en/<filename>.php) if present
            $appFilePath = $rootEnDir . DIRECTORY_SEPARATOR . $filename . '.php';
            $appData = [];

            if (File::exists($appFilePath)) {
                $included = @include $appFilePath;
                if (is_array($included)) {
                    $appData = $included;
                }
            }

            // 3) Merge: framework base, then app overrides
            // framework provides all keys, app can override specific ones
            $mergedNested = array_replace_recursive($frameworkData, $appData);

            // Keep framework translations in memory so they can still be routed into
            // JSON output when the user selects only JSON files. Actual PHP files are
            // created later only if the matching PHP file group is selected.
            $flatMerged = Arr::dot($mergedNested);
            $canonicalEn = LocaleHelper::canonicalize('en');

            $existingTranslations[$canonicalEn][$contextualFileKey] = $flatMerged;

            // Maintain origin + sourceTextMap for translation
            foreach ($flatMerged as $keySuffix => $text) {
                if (! is_string($text)) {
                    continue;
                }

                $fullKey = "{$filename}.{$keySuffix}";
                $keyOriginMap[$fullKey] = '__MAIN__';

                if (! isset($sourceTextMap[$fullKey])) {
                    $sourceTextMap[$fullKey] = $text;
                }
            }

            // Make sure fileTargetMap knows this belongs to MAIN_APP for selection UI
            $fileTargetMap[$contextualFileKey] = '__MAIN__';
        }

        return [$existingTranslations, $fileTargetMap, $sourceTextMap, $keyOriginMap];
    }

    /** Write translation files to disk */
    public function writeTranslationFiles(array $translations, array $scanTargets, bool $consolidateModules, bool $dryRun = false, string $targetDir = 'lang', array $existingTranslations = [], $output = null, bool $isOffline = false, bool $skipExisting = false): void
    {
        $actionVerb = $isOffline ? 'Generated placeholder' : ($skipExisting ? 'Updated' : 'Wrote');
        if ($dryRun) {
            $actionVerb = 'Would write';
        }

        if ($translations === []) {
            if ($output !== null) {
                $output->writeln('No new translations were generated, so no files were written.');
            }

            return;
        }

        if ($dryRun) {
            if ($output) {
                $output->writeln(' 📋 DRY RUN MODE: Would write the following translation files:');
            }
        } else {
            if ($output !== null) {
                $output->writeln(' 💾 Writing translation files to disk:');
            }
        }

        foreach ($translations as $lang => $processedFiles) {
            foreach ($processedFiles as $contextualFileKey => $newData) {
                // If fileKey contains a separator, it's in format "target::file"
                if (str_contains((string) $contextualFileKey, '::')) {
                    [$targetKey, $fileKey] = explode('::', (string) $contextualFileKey, 2);
                } else {
                    // Fallback if something is wrong with the key format
                    $targetKey = '__MAIN__';
                    $fileKey = $contextualFileKey;
                }

                // If consolidating, all writes go to the main app's lang path
                $writeTargetKey = $consolidateModules ? '__MAIN__' : $targetKey;

                // Find the correct target to get the path
                $target = $scanTargets[$writeTargetKey] ?? null;

                if (! $target) {
                    // Fallback to main app if target not found
                    $targetBaseDir = base_path($targetDir);
                } else {
                    $targetBaseDir = $consolidateModules ? base_path($targetDir) : $target['lang_path'];
                }

                // Merge with existing data
                $existingData = $existingTranslations[$lang][$contextualFileKey] ?? [];
                $finalFlatData = array_merge($existingData, $newData);
                $finalFlatData = $this->normalizeTranslationValues($finalFlatData);

                if ($finalFlatData === []) {
                    continue;
                }

                ksort($finalFlatData);

                // Check if fileKey is for JSON files
                if ($fileKey === '__JSON__' || str_ends_with((string) $fileKey, '/__JSON__')) {
                    $relativePath = str_replace('__JSON__', '', $fileKey);
                    $jsonPath = rtrim((string) $targetBaseDir, '/') . '/' . $relativePath . $lang . '.json';

                    if ($dryRun) {
                        if ($output) {
                            $output->writeln("   <fg=yellow>-> {$jsonPath}</>");
                        }

                        continue;
                    }

                    File::ensureDirectoryExists(dirname($jsonPath));
                    $this->safeFileWrite($jsonPath, json_encode($finalFlatData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if ($output) {
                        $output->writeln("   <fg=green>-> {$jsonPath}</>");
                    }
                } else {
                    $filePath = $this->resolvePhpFilePath((string) $targetBaseDir, (string) $fileKey, (string) $lang);

                    if ($dryRun) {
                        if ($output) {
                            $output->writeln("   <fg=yellow>-> {$filePath}</>");
                        }

                        continue;
                    }

                    // Undot the array for PHP files
                    $finalNestedData = Arr::undot($finalFlatData);
                    $this->ksortRecursive($finalNestedData);

                    // Convert array() to [] syntax using a robust approach
                    $export = var_export($finalNestedData, true);

                    // Replace all 'array(' with '[' - handling spacing properly
                    $array = preg_replace('/(\s)array\s*\(/', '$1[', $export);
                    $array = preg_replace('/^array\s*\(/m', '[', (string) $array);

                    // Handle closing brackets
                    $array = preg_replace('/,\s*\n(\s*)\)/', ",\n$1]", (string) $array);
                    $array = preg_replace('/\s*\)\s*$/m', ']', (string) $array);
                    $array = preg_replace('/\)\s*,/', '],', (string) $array);
                    $array = preg_replace('/\s*\)$/', ']', (string) $array);

                    $fileContent = "<?php\n\nreturn {$array};\n";
                    File::ensureDirectoryExists(dirname($filePath));
                    $this->safeFileWrite($filePath, $fileContent);

                    if ($output) {
                        $output->writeln("   <fg=green>-> {$filePath}</>");
                    }
                }
            }
        }
    }

    /**
     * @param array<int|string, mixed> $flat
     *
     * @return array<int|string, mixed>
     */
    public function normalizeTranslationValues(array $flat): array
    {
        foreach ($flat as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '' && is_string($key) && $this->isLiteralJsonKey($key)) {
                $flat[$key] = $key;

                continue;
            }

            $flat[$key] = $trimmed;
        }

        return $flat;
    }

    /** Save failed keys log */
    public function saveFailedKeysLog(array $failedKeys, bool $dryRun = false, $output = null): void
    {
        if ($dryRun) {
            if ($output) {
                $output->writeln(' 📋 DRY RUN: Would save failed keys log to failed_translation_keys.json');
            }

            return;
        }

        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'failed_keys_by_file' => $failedKeys,
            'total_failed_count' => array_sum(array_map(count(...), $failedKeys)),
        ];

        $logContent = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->safeFileWrite(base_path('failed_translation_keys.json'), $logContent);
    }

    /** Save extraction log */
    public function saveExtractionLog(array $keysWithSources, bool $dryRun = false, $output = null): void
    {
        if ($dryRun) {
            if ($output) {
                $output->writeln(' 📋 DRY RUN: Would save extraction log to translation_extraction_log.json');
            }

            return;
        }

        ksort($keysWithSources);

        $logData = [
            'scan_timestamp' => date('Y-m-d H:i:s'),
            'total_unique_keys_found_in_code' => count($keysWithSources),
            'keys' => $keysWithSources,
        ];

        $logContent = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->safeFileWrite(base_path('translation_extraction_log.json'), $logContent);
    }

    private function isLiteralJsonKey(string $key): bool
    {
        return str_contains($key, ' ') || str_starts_with($key, '{') || str_contains($key, '|');
    }

    /** Safely write content to file using atomic write */
    private function safeFileWrite(string $filePath, string $content): void
    {
        // Validate file path to prevent directory traversal
        $realPath = realpath(dirname($filePath));
        $baseDir = realpath(base_path());

        throw_if($realPath === false || $baseDir === false, RuntimeException::class, 'Invalid base or target path.');

        // Handle case sensitivity on Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            $realPath = strtolower($realPath);
            $baseDir = strtolower($baseDir);
        }

        throw_unless(str_starts_with($realPath, $baseDir), RuntimeException::class, 'Invalid file path: ' . $filePath);

        // Use LOCK_EX to prevent concurrent writes
        $tempPath = $filePath . '.tmp';

        try {
            // Write to temporary file first
            file_put_contents($tempPath, $content, LOCK_EX);

            // On Windows, rename() is not atomic and may fail if target exists
            if (PHP_OS_FAMILY === 'Windows' && file_exists($filePath)) {
                unlink($filePath);
            }

            // Atomic rename to prevent corruption if process is interrupted
            throw_unless(rename($tempPath, $filePath), RuntimeException::class, 'Failed to rename temp file to ' . $filePath);
        } catch (Exception $exception) {
            // Clean up temp file if something goes wrong
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            throw $exception;
        }
    }

    /**
     * @param list<string>|null $selectedPacks
     *
     * @return array<string, string> pack => path
     */
    private function phpTreesFor(string $baseLangPath, ?array $selectedPacks): array
    {
        $trees = [];
        if ($selectedPacks === null || in_array('', $selectedPacks, true)) {
            $trees[''] = $baseLangPath;
        }
        foreach (File::directories($baseLangPath) as $dir) {
            if (! $this->isPackDirectory($dir)) {
                continue;
            }
            $pack = basename($dir);
            if ($selectedPacks !== null && ! in_array($pack, $selectedPacks, true)) {
                continue;
            }
            $trees[$pack] = $dir;
        }

        return $trees;
    }

    private function resolvePhpFilePath(string $targetBaseDir, string $fileKey, string $lang): string
    {
        $normalized = str_replace('\\', '/', $fileKey);
        $parts = explode('/', $normalized);
        $pack = '';
        if (count($parts) > 1 && $this->isPackDirectory($targetBaseDir . DIRECTORY_SEPARATOR . $parts[0])) {
            $pack = array_shift($parts);
        }
        $groupPath = implode(DIRECTORY_SEPARATOR, $parts);
        $base = $pack === ''
            ? $targetBaseDir
            : $targetBaseDir . DIRECTORY_SEPARATOR . $pack;

        return $base . DIRECTORY_SEPARATOR . $lang . DIRECTORY_SEPARATOR . $groupPath . '.php';
    }

    private function hasRootLangContent(string $path): bool
    {
        if (! File::isDirectory($path)) {
            return false;
        }
        foreach (File::files($path) as $file) {
            if ($file->getExtension() === 'json') {
                return true;
            }
        }
        foreach (File::directories($path) as $dir) {
            if ($this->isLocaleDirectory($dir)) {
                return true;
            }
        }

        return false;
    }

    private function isPackDirectory(string $path): bool
    {
        $name = basename($path);
        if (in_array($name, ['vendor', 'node_modules', 'storage', '.git'], true) || $this->isLocaleDirectory($path)) {
            return false;
        }

        return $this->hasLangContent($path);
    }

    private function isLocaleDirectory(string $path): bool
    {
        $name = basename($path);
        if (! $this->looksLikeLocale($name) || ! File::isDirectory($path)) {
            return false;
        }

        return ! $this->hasLangContent($path);
    }

    private function hasLangContent(string $path): bool
    {
        if (! File::isDirectory($path)) {
            return false;
        }
        foreach (File::files($path) as $file) {
            if ($file->getExtension() === 'json') {
                return true;
            }
        }
        foreach (File::directories($path) as $dir) {
            if ($this->looksLikeLocale(basename($dir))) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeLocale(string $name): bool
    {
        return (bool) preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]+)?$/', $name);
    }

    /** Recursively sort an array by keys */
    private function ksortRecursive(array &$a): void
    {
        ksort($a);
        foreach ($a as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }
}
