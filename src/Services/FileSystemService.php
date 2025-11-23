<?php

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
     * Load existing translations from all specified targets
     *
     * @param array $targets Array of scan targets
     * @param array $langs Target languages
     * @param bool $consolidateModules Whether to consolidate modules
     * @param mixed $output Output object for displaying messages
     * @return array Array containing [existingTranslations, fileTargetMap, sourceTextMap, keyOriginMap]
     */
    public function loadExistingTranslations(array $targets, array $langs, bool $consolidateModules, $output = null): array
    {
        $existingTranslations = [];
        $fileTargetMap = [];
        $sourceTextMap = [];
        $keyOriginMap = [];

        if ($output) {
            $output->writeln("Reading existing language files from selected targets...");
        }

        // Only load the specified target languages plus 'en' as source
        // Do NOT load all available language directories
        $languagesToLoad = array_unique(array_merge(['en'], $langs));

        foreach ($targets as $targetKey => $target) {
            $baseLangPath = $target['lang_path'];
            if (!File::isDirectory($baseLangPath)) {
                continue;
            }

            $origin = $consolidateModules ? '__MAIN__' : $targetKey;

            foreach (File::directories($baseLangPath) as $langDirPath) {
                $dirName = basename($langDirPath);
                $canonicalLang = LocaleHelper::canonicalize($dirName);

                // FIXED: Only load directories for languages we're actually targeting
                if (!in_array($canonicalLang, $languagesToLoad)) {
                    continue;
                }
                foreach (File::allFiles($langDirPath) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $relativePath = $file->getRelativePathname();
                    $fileKey = str_replace(['.php', DIRECTORY_SEPARATOR], ['', '/'], $relativePath);
                    $contextualFileKey = $origin . '::' . $fileKey;

                    $includedData = @include $file->getPathname();
                    if (is_array($includedData)) {
                        $flatData = Arr::dot($includedData);
                        $existingTranslations[$canonicalLang][$contextualFileKey] = $flatData;
                        $fileTargetMap[$contextualFileKey] = $origin;

                        foreach ($flatData as $keySuffix => $text) {
                            if (is_string($text)) {
                                $fullKey = "{$fileKey}.{$keySuffix}";
                                if ($canonicalLang === 'en' || !isset($sourceTextMap[$fullKey])) {
                                    $sourceTextMap[$fullKey] = $text;
                                }
                                // NEW: record origin for this key
                                if (!isset($keyOriginMap[$fullKey])) {
                                    $keyOriginMap[$fullKey] = $origin;
                                }
                            }
                        }
                    }
                }
            }
            $jsonFinder = new Finder();
            $jsonFinder->files()->in($baseLangPath)->name('*.json');

            foreach ($jsonFinder as $jsonFile) {
                $dirName = $jsonFile->getFilenameWithoutExtension();
                $canonicalLang = LocaleHelper::canonicalize($dirName);

                // FIXED: Only load JSON files for languages we're actually targeting
                if (!in_array($canonicalLang, $languagesToLoad)) {
                    continue;
                }
                $relativePath = $jsonFile->getRelativePath();
                $fileKey = !empty($relativePath) ? rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/') . '/' . '__JSON__' : '__JSON__';
                $contextualFileKey = $origin . '::' . $fileKey;

                $jsonContent = json_decode($jsonFile->getContents(), true);
                if (is_array($jsonContent)) {
                    $existingTranslations[$canonicalLang][$contextualFileKey] = $jsonContent;
                    $fileTargetMap[$contextualFileKey] = $origin;
                    foreach ($jsonContent as $key => $text) {
                        if (is_string($text) && ($canonicalLang === 'en' || !isset($sourceTextMap[$key]))) {
                            $sourceTextMap[$key] = $text;
                        }
                        // NEW: record origin for this JSON key
                        if (!isset($keyOriginMap[$key])) {
                            $keyOriginMap[$key] = $origin;
                        }
                    }
                }
            }
        }

        return [$existingTranslations, $fileTargetMap, $sourceTextMap, $keyOriginMap];
    }

    /**
     * Load Laravel framework translations
     */
    public function loadFrameworkTranslations(array $currentData, string $targetDir, array $targetLanguages, bool $dryRun = false, $output = null): array
    {
        $existingTranslations = $currentData[0] ?? [];
        $fileTargetMap = $currentData[1] ?? [];
        $sourceTextMap = $currentData[2] ?? [];
        $keyOriginMap = $currentData[3] ?? [];

        if ($output) {
            $output->writeln("Reading Laravel framework default language files...");
        }

        $frameworkLangPath = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        if (!File::isDirectory($frameworkLangPath)) {
            if ($output) {
                $output->writeln("<fg=yellow>Could not find Laravel framework language directory. Skipping.</>");
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
            if (!is_array($frameworkData)) {
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

            // Only write if file is missing OR keys changed (new vendor keys)
            $shouldWrite = false;

            if (!File::exists($appFilePath)) {
                $shouldWrite = true;
            } else {
                // Compare flattened versions so order doesn’t matter
                $existingFlat = Arr::dot($appData);
                $mergedFlat = Arr::dot($mergedNested);
                if ($mergedFlat !== $existingFlat) {
                    $shouldWrite = true;
                }
            }

            if ($shouldWrite && !$dryRun) {
                File::ensureDirectoryExists(dirname($appFilePath));
                // Convert array() to [] syntax using a robust approach
                $export = var_export($mergedNested, true);

                // Replace all 'array(' with '[' - handling spacing properly
                $array = preg_replace('/(\s)array\s*\(/', '$1[', $export);
                $array = preg_replace('/^array\s*\(/m', '[', $array);

                // Handle closing brackets - this is complex, need to match opening and closing properly
                // First handle the commas followed by closing parenthesis on new lines
                $array = preg_replace('/,\s*\n(\s*)\)/', ",\n$1]", $array);
                // Handle closing parenthesis at the end of lines that aren't followed by commas
                $array = preg_replace('/\s*\)\s*$/m', ']', $array);
                // Handle inline closing like '),'
                $array = preg_replace('/\)\s*,/', '],', $array);
                // Handle the very last closing parenthesis
                $array = preg_replace('/\s*\)$/', ']', $array);

                $content = "<?php\n\nreturn " . $array . ";\n";
                $this->safeFileWrite($appFilePath, $content);

                if ($output) {
                    $output->writeln("  <fg=bright-green;options=bold> ✅ Bootstrapped framework lang:</> <fg=bright-cyan>{$appFilePath}</> <fg=bright-white>(" . count(Arr::dot($mergedNested)) . " total keys)</>");
                }
            } else if ($shouldWrite && $dryRun) {
                if ($output) {
                    $output->writeln("  <fg=bright-yellow;options=bold> 📋 Would bootstrap framework lang:</> <fg=bright-cyan>{$appFilePath}</> <fg=bright-white>(" . count(Arr::dot($mergedNested)) . " total keys)</>");
                }
            }

            // 4) Update in-memory existingTranslations using MERGED data
            $flatMerged = Arr::dot($mergedNested);
            $canonicalEn = LocaleHelper::canonicalize('en');

            $existingTranslations[$canonicalEn][$contextualFileKey] = $flatMerged;

            // Maintain origin + sourceTextMap for translation
            foreach ($flatMerged as $keySuffix => $text) {
                if (!is_string($text)) {
                    continue;
                }

                $fullKey = "{$filename}.{$keySuffix}";
                $keyOriginMap[$fullKey] = '__MAIN__';

                if (!isset($sourceTextMap[$fullKey])) {
                    $sourceTextMap[$fullKey] = $text;
                }
            }

            // Make sure fileTargetMap knows this belongs to MAIN_APP for selection UI
            $fileTargetMap[$contextualFileKey] = '__MAIN__';
        }

        return [$existingTranslations, $fileTargetMap, $sourceTextMap, $keyOriginMap];
    }

    /**
     * Write translation files to disk
     */
    public function writeTranslationFiles(array $translations, array $scanTargets, bool $consolidateModules, bool $dryRun = false, string $targetDir = 'lang', array $existingTranslations = [], $output = null, bool $isOffline = false, bool $skipExisting = false): void
    {
        $actionVerb = $isOffline ? 'Generated placeholder' : ($skipExisting ? 'Updated' : 'Wrote');
        if ($dryRun) {
            $actionVerb = 'Would write';
        }

        if (empty($translations)) {
            if ($output) {
                $output->writeln("No new translations were generated, so no files were written.");
            }
            return;
        }

        if ($dryRun) {
            if ($output) {
                $output->writeln(" 📋 DRY RUN MODE: Would write the following translation files:");
            }
        } else {
            if ($output) {
                $output->writeln(" 💾 Writing translation files to disk:");
            }
        }

        foreach ($translations as $lang => $processedFiles) {
            foreach ($processedFiles as $contextualFileKey => $newData) {
                // If fileKey contains a separator, it's in format "target::file"
                if (strpos($contextualFileKey, '::') !== false) {
                    [$targetKey, $fileKey] = explode('::', $contextualFileKey, 2);
                } else {
                    // Fallback if something is wrong with the key format
                    $targetKey = '__MAIN__';
                    $fileKey = $contextualFileKey;
                }

                // If consolidating, all writes go to the main app's lang path
                $writeTargetKey = $consolidateModules ? '__MAIN__' : $targetKey;

                // Find the correct target to get the path
                $target = $scanTargets[$writeTargetKey] ?? null;

                if (!$target) {
                    // Fallback to main app if target not found
                    $targetBaseDir = base_path($targetDir);
                } else {
                    $targetBaseDir = $consolidateModules ? base_path($targetDir) : $target['lang_path'];
                }

                $langDir = $targetBaseDir . DIRECTORY_SEPARATOR . $lang;

                if (!$dryRun && !File::isDirectory($langDir)) {
                    File::makeDirectory($langDir, 0755, true);
                }

                // Merge with existing data
                $existingData = $existingTranslations[$lang][$contextualFileKey] ?? [];
                $finalFlatData = array_merge($existingData, $newData);

                if (empty($finalFlatData)) {
                    continue;
                }

                ksort($finalFlatData);

                // Check if fileKey is for JSON files
                if ($fileKey === '__JSON__' || str_ends_with($fileKey, '/__JSON__')) {
                    $relativePath = str_replace('__JSON__', '', $fileKey);
                    $jsonPath = rtrim($targetBaseDir, '/') . '/' . $relativePath . $lang . '.json';

                    if ($dryRun) {
                        if ($output) {
                            $output->writeln("   <fg=yellow>-> {$jsonPath}</>");
                        }
                        continue;
                    }

                    $this->safeFileWrite($jsonPath, json_encode($finalFlatData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if ($output) {
                        $output->writeln("   <fg=green>-> {$jsonPath}</>");
                    }
                } else {
                    // Handle PHP language files
                    // $fileKey is like "auth" or "subdir/messages"

                    $filePath = $langDir . DIRECTORY_SEPARATOR . $fileKey . '.php';

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
                    $array = preg_replace('/^array\s*\(/m', '[', $array);

                    // Handle closing brackets
                    $array = preg_replace('/,\s*\n(\s*)\)/', ",\n$1]", $array);
                    $array = preg_replace('/\s*\)\s*$/m', ']', $array);
                    $array = preg_replace('/\)\s*,/', '],', $array);
                    $array = preg_replace('/\s*\)$/', ']', $array);

                    $fileContent = "<?php\n\nreturn {$array};\n";
                    $this->safeFileWrite($filePath, $fileContent);

                    if ($output) {
                        $output->writeln("   <fg=green>-> {$filePath}</>");
                    }
                }
            }
        }
    }

    /**
     * Save failed keys log
     */
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
            'total_failed_count' => array_sum(array_map('count', $failedKeys))
        ];

        $logContent = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->safeFileWrite(base_path('failed_translation_keys.json'), $logContent);
    }

    /**
     * Save extraction log
     */
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
            'keys' => $keysWithSources
        ];

        $logContent = json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->safeFileWrite(base_path('translation_extraction_log.json'), $logContent);
    }

    /**
     * Safely write content to file using atomic write
     */
    private function safeFileWrite(string $filePath, string $content): void
    {
        // Validate file path to prevent directory traversal
        $realPath = realpath(dirname($filePath));
        $baseDir = realpath(base_path());

        if ($realPath === false || $baseDir === false) {
            throw new RuntimeException("Invalid base or target path.");
        }

        // Handle case sensitivity on Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            $realPath = strtolower($realPath);
            $baseDir = strtolower($baseDir);
        }

        if (strpos($realPath, $baseDir) !== 0) {
            throw new RuntimeException("Invalid file path: {$filePath}");
        }

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
            if (!rename($tempPath, $filePath)) {
                throw new RuntimeException("Failed to rename temp file to {$filePath}");
            }
        } catch (Exception $e) {
            // Clean up temp file if something goes wrong
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw $e;
        }
    }

    /**
     * Recursively sort an array by keys
     */
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
