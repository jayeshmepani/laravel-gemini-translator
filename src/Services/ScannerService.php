<?php

namespace Jayesh\LaravelGeminiTranslator\Services;

use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;
use Jayesh\LaravelGeminiTranslator\Utils\TextHelper;
use Nwidart\Modules\Facades\Module;
use Symfony\Component\Finder\Finder;

class ScannerService
{
    /**
     * Extract raw keys from all specified targets
     * 
     * @param array $targets Array of scan targets
     * @param array $options Command options
     * @return array Array containing [scannedKeys, keysWithSources, filesScannedCount, keyOriginMapUpdate]
     */
    public function extractRawKeys(array $targets, array $options, $output): array
    {
        $keysWithSources = [];
        $filesScanned = 0;
        $keyOriginMap = [];

        // **BUG FIX**: Get the name of the modules directory to exclude from the main app scan.
        $moduleDirectoryToExclude = [];
        if (class_exists(Module::class)) {
            $modulesPath = config('modules.paths.modules');
            if ($modulesPath && is_string($modulesPath) && file_exists($modulesPath)) {
                $moduleDirectoryToExclude = [basename($modulesPath)];
            } else {
                $moduleDirectoryToExclude = [];
            }
        }

        $totalFiles = 0;
        foreach ($targets as $targetKey => $target) {
            // Use same logic for counting total files as actual scanning
            $extraExcludes = ($targetKey === '__MAIN__' && !empty($moduleDirectoryToExclude)) ? $moduleDirectoryToExclude : [];
            $totalFiles += $this->configureFinder([$target['path']], $options['exclude'], $options['extensions'], $extraExcludes)->count();
        }

        $extractionBar = $output->createProgressBar($totalFiles);
        $extractionBar->setFormat("🔎 %message%\n   %current%/%max% [%bar%] %percent:3s%%");
        $extractionBar->setMessage('Starting code scan...');
        $extractionBar->start();

        foreach ($targets as $targetKey => $target) {
            // If scanning the main app, add the modules directory name to the exclusion list.
            $extraExcludes = ($targetKey === '__MAIN__' && !empty($moduleDirectoryToExclude)) ? $moduleDirectoryToExclude : [];
            $finder = $this->configureFinder([$target['path']], $options['exclude'], $options['extensions'], $extraExcludes);
            $allPatterns = TextHelper::getExtractionPatterns();

            foreach ($finder as $file) {
                $filesScanned++;
                $extractionBar->setMessage('Scanning: ' . $file->getRelativePathname());
                $relativePath = $file->getRelativePathname();
                $content = $file->getContents();
                $origin = $options['consolidate-modules'] ? '__MAIN__' : $targetKey;

                foreach ($allPatterns as $pattern) {
                    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            // For our pattern, captured groups are at indexes 1, 2, 3 (for single, double, backtick)
                            // and 4, 5, 6 (for attribute patterns)
                            $foundKey = '';
                            for ($i = 1; $i < count($match); $i++) {
                                if (isset($match[$i]) && !empty($match[$i])) {
                                    $foundKey = $match[$i];
                                    break;
                                }
                            }

                            if (empty($foundKey))
                                continue;

                            // Unescape escaped quotes if necessary
                            $foundKey = stripcslashes($foundKey);

                            // Extract actual key from function calls inside attribute values, e.g., x-text="__('messages.hello')"
                            $foundKey = TextHelper::extractKeyFromAttribute($foundKey);

                            if (empty($foundKey))
                                continue;

                            $foundKey = str_replace('/', '.', $foundKey);
                            if (!isset($keysWithSources[$foundKey])) {
                                $keysWithSources[$foundKey] = [];
                            }
                            // Calculate the full relative path for the log (include target directory structure)
                            $targetBasePath = $targets[$targetKey]['path'];

                            // For modules, we want the path to include the module directory
                            if ($targetKey !== '__MAIN__') {
                                // Calculate relative path from base_path() to target base path
                                $relativeFromBase = $this->getRelativePath(base_path(), $targetBasePath);
                                $fullRelativePath = $relativeFromBase . '/' . $relativePath;
                            } else {
                                // For main app, just use the original relative path
                                $fullRelativePath = $relativePath;
                            }

                            if (!in_array($fullRelativePath, $keysWithSources[$foundKey])) {
                                $keysWithSources[$foundKey][] = $fullRelativePath;
                            }
                            if (!isset($keyOriginMap[$foundKey])) {
                                $keyOriginMap[$foundKey] = $origin;
                            }
                        }
                    }
                }
                $extractionBar->advance();
            }
        }

        $extractionBar->finish();
        $output->newLine();

        return [array_keys($keysWithSources), $keysWithSources, $filesScanned, $keyOriginMap];
    }

    /**
     * Configure Finder with options
     */
    public function configureFinder(array $scanPaths, string $excludeOption, string $extensionsOption, array $extraExcludes = []): Finder
    {
        $finder = new Finder();
        $defaultExcludes = explode(',', $excludeOption);
        $filesToExclude = ['artisan', 'composer.json', 'composer.lock', 'failed_translation_keys.json', 'translation_extraction_log.json', 'laravel-translation-extractor.sh', 'package.json', 'package-lock.json', 'phpunit.xml', 'README.md', 'vite.config.js', '.env*', '.phpactor.json', '.phpunit.result.cache', 'Homestead.*', 'auth.json',];

        $finder->files()
            ->in($scanPaths)
            ->exclude(array_merge($defaultExcludes, $extraExcludes)) // Use merged excludes
            ->notName($filesToExclude)
            ->notName('*.log')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);

        $extensions = explode(',', $extensionsOption);
        foreach ($extensions as $ext) {
            $finder->name('*.' . trim($ext));
        }
        return $finder;
    }

    /**
     * Get relative path between two paths
     */
    public function getRelativePath(string $from, string $to): string
    {
        $from = realpath($from) ?: $from;
        $to = realpath($to) ?: $to;

        // Normalize paths
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);

        $fromParts = explode('/', trim($from, '/'));
        $toParts = explode('/', trim($to, '/'));

        // Remove common path elements from the beginning
        while (count($fromParts) > 0 && count($toParts) > 0 && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        // Add '../' for each remaining element in $from
        $relativePath = str_repeat('../', count($fromParts));

        // Add remaining elements from $to
        $relativePath .= implode('/', $toParts);

        // Clean up the relative path - if $to is a subdirectory of $from
        if (empty($fromParts) && strpos($to, $from . '/') === 0) {
            $relativePath = substr($to, strlen($from) + 1);
        }

        return $relativePath;
    }


    /**
     * Get all key sources
     */
    public function getAllKeySources(array $scannedKeys, array $existingTranslations, array $sourceTextMap): array
    {
        $allKeys = $scannedKeys;
        foreach ($existingTranslations as $lang => $files) {
            foreach ($files as $contextualFileKey => $data) {
                [, $fileKey] = explode('::', $contextualFileKey, 2);
                if (str_ends_with($fileKey, '__JSON__')) {
                    $allKeys = array_merge($allKeys, array_keys($data));
                } else {
                    $prefix = str_replace('/', '.', $fileKey);
                    foreach (array_keys($data) as $keySuffix) {
                        $allKeys[] = "{$prefix}.{$keySuffix}";
                    }
                }
            }
        }
        $allKeys = array_merge($allKeys, array_keys($sourceTextMap));
        return array_values(array_unique($allKeys));
    }

    /**
     * Determine available files
     */
    public function determineAvailableFiles(array $allPossibleKeys, array $fileTargetMap, array $scanTargets, array $keyOriginMap): array
    {
        $fileGroups = [];

        // Only add files from targets that are in the currently selected scan targets
        foreach ($fileTargetMap as $contextualFileKey => $targetKey) {
            if (isset($scanTargets[$targetKey])) {
                $fileGroups[$contextualFileKey] = true;
            }
        }

        foreach ($allPossibleKeys as $key) {
            $origin = $keyOriginMap[$key] ?? '__MAIN__';

            // Only add keys that belong to currently selected targets
            if (!isset($scanTargets[$origin])) {
                continue;
            }

            if (str_contains($key, '.')) {
                $prefix = explode('.', $key, 2)[0];
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $prefix)) {
                    $contextualFileKey = $origin . '::' . $prefix;
                    $fileGroups[$contextualFileKey] = true;
                } else {
                    // It's a sentence-like key, so it belongs in a root JSON file
                    $contextualFileKey = $origin . '::' . '__JSON__';
                    $fileGroups[$contextualFileKey] = true;
                }
            } else {
                // No dot, so it belongs in a root JSON file
                $contextualFileKey = $origin . '::' . '__JSON__';
                $fileGroups[$contextualFileKey] = true;
            }
        }

        $uniqueFiles = array_keys($fileGroups);
        sort($uniqueFiles);
        return $uniqueFiles;
    }

    /**
     * Map keys to selected files
     */
    public function mapKeysToSelectedFiles(array $allPossibleKeys, array $selectedFiles, array $keyOriginMap): array
    {
        $structured = [];

        // Invert the selected files for quick lookups
        $selectedFileMap = array_flip($selectedFiles);

        foreach ($allPossibleKeys as $rawKey) {
            $origin = $keyOriginMap[$rawKey] ?? '__MAIN__';
            $isPhpKey = false;

            // Determine if the key is a PHP-style key (`file.key`)
            if (str_contains($rawKey, '.')) {
                $prefix = explode('.', $rawKey, 2)[0];
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $prefix)) {
                    $contextualFileKey = $origin . '::' . $prefix;

                    // If this file group was selected, map the key
                    if (isset($selectedFileMap[$contextualFileKey])) {
                        $keySuffix = substr($rawKey, strlen($prefix) + 1);
                        $structured[$contextualFileKey][] = $keySuffix;
                        $isPhpKey = true;
                    }
                }
            }

            // If it wasn't mapped as a PHP key, treat it as a JSON key
            if (!$isPhpKey) {
                // Find all possible JSON files for the key's origin that were selected
                $possibleJsonFiles = array_filter(
                    $selectedFiles,
                    fn($file) => str_starts_with($file, $origin . '::') && str_ends_with($file, '__JSON__')
                );

                // Add the key to all matching selected JSON files
                foreach ($possibleJsonFiles as $contextualJsonFileKey) {
                    $structured[$contextualJsonFileKey][] = $rawKey;
                }
            }
        }

        foreach ($structured as &$keys) {
            $keys = array_values(array_unique($keys));
        }

        return $structured;
    }

    /**
     * Populate source text for new keys
     */
    public function populateSourceTextForNewKeys(array $allPossibleKeys, array $sourceTextMap, bool $isOffline): array
    {
        $newSourceTextMap = $sourceTextMap;

        foreach ($allPossibleKeys as $key) {
            if (!isset($newSourceTextMap[$key])) {
                if ($isOffline) {
                    // If it looks like a machine key, humanize it; otherwise use as is
                    if (TextHelper::looksMachineKey($key)) {
                        $newSourceTextMap[$key] = LocaleHelper::humanizeForLang($key, 'en');
                    } else {
                        $newSourceTextMap[$key] = $key;
                    }
                }
            }
        }

        return $newSourceTextMap;
    }
}
