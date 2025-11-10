<?php

namespace Jayesh\LaravelGeminiTranslator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Gemini\Laravel\Facades\Gemini;
use Spatie\Fork\Fork;
use Symfony\Component\Finder\Finder;
use Throwable;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class ExtractAndGenerateTranslationsCommand extends Command
{
    private const JSON_FILE_KEY = '__JSON__';
    private const ALL_FILES_KEY = '__ALL_FILES__';
    private const MAIN_APP_KEY = '__MAIN__';
    private const ALL_TARGETS_KEY = '__ALL_TARGETS__';
    private const FILE_KEY_SEPARATOR = '::';


    protected $signature = 'translations:extract-and-generate
                            {--source=. : The root directory of the application to scan for keys (deprecated; use interactive prompt)}
                            {--target-dir=lang : Root directory for final Laravel translation files}
                            {--langs=en : Comma-separated language codes to translate to}
                            {--exclude=vendor,node_modules,storage,public,bootstrap,tests,lang,config,database,routes,app/Console,.phpunit.cache,lang-output,.fleet,.idea,.nova,.vscode,.zed : Comma-separated directories to exclude from scanning}
                            {--extensions=php,blade.php,vue,js,jsx,ts,tsx : Comma-separated file extensions to search}
                            {--no-advanced : Disable advanced, context-based pattern detection}
                            {--chunk-size=25 : Number of keys to send to Gemini in a single request}
                            {--driver=default : Concurrency driver (default, fork, sync)}
                            {--skip-existing : Only translate keys that are missing from one or more language files, then append them.}
                            {--consolidate-modules : Consolidate all module translations into the main application\'s lang directory.}
                            {--max-retries=5 : Maximum number of retries for failed API calls}
                            {--retry-delay=3 : Base delay in seconds between retries (exponential backoff)}
                            {--stop-key=q : The key to press to gracefully stop the translation process}
                            {--context= : Provide project-specific context to Gemini for better translations}';

    protected $description = ' 🌐 Extracts, cross-checks, translates, and synchronizes language files via Gemini AI, with full module support.';

    // --- State Properties ---
    private array $translations = [];
    private array $existingTranslations = [];
    private array $sourceTextMap = [];
    private array $failedKeys = [];
    private float $startTime;
    private bool $shouldExit = false;
    private bool $isOffline = false;
    private bool $consolidateModules = false;

    /** @var array<string, array{name: string, path: string, lang_path: string}> */
    private array $scanTargets = [];
    private array $availableScanTargets = [];
    private array $fileTargetMap = [];
    private array $keyOriginMap = [];

    // --- Statistics ---
    private int $filesScanned = 0;
    private int $uniqueKeysForProcessing = 0;
    private int $totalKeysToTranslate = 0;
    private int $totalKeysSuccessfullyProcessed = 0;
    private int $totalKeysFailed = 0;
    private int $totalChunks = 0;
    private int $processedChunks = 0;

    public function handle()
    {
        $this->startTime = microtime(true);
        $this->showWelcome();

        if (!config('gemini.api_key') || config('gemini.api_key') === 'YOUR_API_KEY') {
            $this->isOffline = true;
            $this->warn(' ⚠️  Gemini API key is not configured. Running in OFFLINE mode.');
            $this->comment('   New translation files will be generated with keys as placeholder values.');
        }

        $this->phaseTitle(' 🔍 Phase 1: Gathering Keys from All Sources', 'cyan');

        $this->availableScanTargets = $this->getScanTargets();
        $selectedTargets = $this->promptForScanTargets($this->availableScanTargets);
        if (empty($selectedTargets)) {
            $this->warn('No application or module targets were selected for scanning. Exiting.');
            return Command::SUCCESS;
        }
        $this->scanTargets = array_intersect_key($this->availableScanTargets, array_flip($selectedTargets));
        $this->info("Scanning " . count($this->scanTargets) . " target(s): " . implode(', ', array_column($this->scanTargets, 'name')));

        $this->promptForConsolidation();

        $this->loadExistingTranslations();
        $this->loadFrameworkTranslations();

        [$scannedKeys, $keysWithSources] = $this->extractRawKeys();
        $this->saveExtractionLog($keysWithSources);
        $this->info("Detailed code extraction log saved to <fg=bright-cyan>translation_extraction_log.json</>");

        $allPossibleKeys = $this->getAllKeySources($scannedKeys);
        if (empty($allPossibleKeys)) {
            $this->alert('No translation keys were found from any source. Exiting.');
            return Command::SUCCESS;
        }
        $this->populateSourceTextForNewKeys($allPossibleKeys);

        $this->success("Key discovery complete! Found " . count($allPossibleKeys) . " unique keys from all sources combined.");
        $this->line('');

        $availableFiles = $this->determineAvailableFiles($allPossibleKeys);
        $selectedFiles = $this->promptForFileSelection($availableFiles);

        if (empty($selectedFiles)) {
            $this->warn('No files were selected for processing. Exiting.');
            return Command::SUCCESS;
        }

        $keysForProcessing = $this->mapKeysToSelectedFiles($allPossibleKeys, $selectedFiles);
        $this->uniqueKeysForProcessing = array_sum(array_map('count', $keysForProcessing));
        $this->info(" ✅ Selected " . count($keysForProcessing) . " file groups containing {$this->uniqueKeysForProcessing} unique keys for processing.");

        $this->phaseTitle('📊 Phase 1.5: Analyzing Translation Status', 'blue');
        $this->performCrossCheckAndReport($keysForProcessing);
        $keysToTranslate = $this->filterOutExistingKeys($keysForProcessing);
        $this->totalKeysToTranslate = array_sum(array_map('count', $keysToTranslate));

        if ($this->totalKeysToTranslate === 0) {
            $this->success(' 🎉 All selected keys are fully translated. Nothing to do!');
            $this->displayFinalSummary();
            return Command::SUCCESS;
        }

        if ($this->isOffline) {
            $this->phaseTitle('  Offline Mode: Generating Placeholders', 'yellow');
            $this->generateOfflinePlaceholders($keysToTranslate);
        } else {
            $this->phaseTitle(' 🤖 Phase 2: Translating with Gemini AI', 'magenta');
            if ($this->option('context')) {
                $this->info("💡 Applying project-specific context for enhanced translation accuracy.");
            }
            $this->totalChunks = $this->calculateTotalChunks($keysToTranslate);
            if ($this->totalChunks === 0) {
                $this->warn('No tasks to run for translation.');
            } else {
                $this->line("Press the '<fg=bright-red;options=bold>{$this->option('stop-key')}</>' key at any time to gracefully stop the process.");
                $this->info(" 📊 Total keys needing translation: <fg=bright-yellow;options=bold>{$this->totalKeysToTranslate}</>");
                $this->info(" 📦 Total chunks to process: <fg=bright-yellow;options=bold>{$this->totalChunks}</>");
                $this->runTranslationProcess($keysToTranslate);
            }
        }
        $this->line('');

        $this->phaseTitle(' 💾 Phase 3: Writing Language Files', 'green');
        $this->writeTranslationFiles();
        if (!empty($this->failedKeys)) {
            $this->saveFailedKeysLog();
            $this->warn("Some translations failed. Failed keys have been saved to: <fg=bright-red>failed_translation_keys.json</>");
        }
        $this->displayFinalSummary();
        return Command::SUCCESS;
    }

    private function promptForConsolidation(): void
    {
        $this->consolidateModules = $this->option('consolidate-modules');
        $hasModulesSelected = count(array_diff(array_keys($this->scanTargets), [self::MAIN_APP_KEY])) > 0;

        if ($hasModulesSelected && !$this->option('consolidate-modules') && !$this->option('no-interaction')) {
            $this->consolidateModules = confirm(
                label: 'Consolidate all module translations into the main application\'s `lang` directory?',
                default: false,
                hint: 'No: Keep translations inside each module (e.g., Modules/Settings/lang). Yes: Put all translations in the root `lang/`.'
            );
        }
    }

    private function getScanTargets(): array
    {
        $targets = [];
        $targets[self::MAIN_APP_KEY] = [
            'name' => 'Main Application',
            'path' => base_path(),
            'lang_path' => base_path($this->option('target-dir')),
        ];

        if (class_exists(\Nwidart\Modules\Facades\Module::class)) {
            $modules = \Nwidart\Modules\Facades\Module::getOrdered();
            foreach ($modules as $module) {
                if ($module->isEnabled()) {
                    $targets[$module->getName()] = [
                        'name' => 'Module: ' . $module->getName(),
                        'path' => $module->getPath(),
                        'lang_path' => $module->getPath() . DIRECTORY_SEPARATOR . $this->option('target-dir'),
                    ];
                }
            }
        }
        return $targets;
    }

    private function promptForScanTargets(array $availableTargets): array
    {
        if (count($availableTargets) <= 1) {
            return array_keys($availableTargets);
        }

        $displayChoices = [self::ALL_TARGETS_KEY => '-- ALL TARGETS --'] +
            collect($availableTargets)->mapWithKeys(fn($target, $key) => [$key => $target['name']])->all();

        $selected = $this->promptForMultiChoice(
            label: 'Which parts of the application would you like to scan and process?',
            options: $displayChoices,
            hint: 'Select the main application and/or any specific modules.',
            default: [self::ALL_TARGETS_KEY]
        );

        if (in_array(self::ALL_TARGETS_KEY, $selected)) {
            return array_keys($availableTargets);
        }
        return $selected;
    }

    private function loadExistingTranslations(): void
    {
        $this->info("Reading existing language files from selected targets...");
        $languages = explode(',', $this->option('langs'));

        foreach ($this->scanTargets as $targetKey => $target) {
            $baseLangPath = $target['lang_path'];
            if (!File::isDirectory($baseLangPath)) {
                continue;
            }

            $origin = $this->consolidateModules ? self::MAIN_APP_KEY : $targetKey;

            foreach (File::directories($baseLangPath) as $langDirPath) {
                $lang = basename($langDirPath);
                if (!in_array($lang, $languages) && $lang !== 'en') {
                    continue;
                }
                foreach (File::allFiles($langDirPath) as $file) {
                    if ($file->getExtension() !== 'php') {
                        continue;
                    }
                    $relativePath = $file->getRelativePathname();
                    $fileKey = str_replace(['.php', DIRECTORY_SEPARATOR], ['', '/'], $relativePath);
                    $contextualFileKey = $origin . self::FILE_KEY_SEPARATOR . $fileKey;

                    $includedData = @include $file->getPathname();
                    if (is_array($includedData)) {
                        $flatData = Arr::dot($includedData);
                        $this->existingTranslations[$lang][$contextualFileKey] = $flatData;
                        $this->fileTargetMap[$contextualFileKey] = $origin;

                        foreach ($flatData as $keySuffix => $text) {
                            if (is_string($text)) {
                                $fullKey = "{$fileKey}.{$keySuffix}";
                                if ($lang === 'en' || !isset($this->sourceTextMap[$fullKey])) {
                                    $this->sourceTextMap[$fullKey] = $text;
                                }
                            }
                        }
                    }
                }
            }
            $jsonFinder = new Finder();
            $jsonFinder->files()->in($baseLangPath)->name('*.json');

            foreach ($jsonFinder as $jsonFile) {
                $lang = $jsonFile->getFilenameWithoutExtension();
                if (!in_array($lang, $languages) && $lang !== 'en') {
                    continue;
                }
                $relativePath = $jsonFile->getRelativePath();
                $fileKey = !empty($relativePath) ? rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/') . '/' . self::JSON_FILE_KEY : self::JSON_FILE_KEY;
                $contextualFileKey = $origin . self::FILE_KEY_SEPARATOR . $fileKey;

                $jsonContent = json_decode($jsonFile->getContents(), true);
                if (is_array($jsonContent)) {
                    $this->existingTranslations[$lang][$contextualFileKey] = $jsonContent;
                    $this->fileTargetMap[$contextualFileKey] = $origin;
                    foreach ($jsonContent as $key => $text) {
                        if (is_string($text) && ($lang === 'en' || !isset($this->sourceTextMap[$key]))) {
                            $this->sourceTextMap[$key] = $text;
                        }
                    }
                }
            }
        }
    }

    private function extractRawKeys(): array
    {
        $keysWithSources = [];
        $totalFiles = 0;
        foreach ($this->scanTargets as $target) {
            $totalFiles += $this->configureFinder([$target['path']])->count();
        }

        $extractionBar = $this->output->createProgressBar($totalFiles);
        $extractionBar->setFormat("🔎 %message%\n   %current%/%max% [%bar%] %percent:3s%%");
        $extractionBar->setMessage('Starting code scan...');
        $extractionBar->start();

        // **BUG FIX**: Get the name of the modules directory to exclude from the main app scan.
        $moduleDirectoryToExclude = [];
        if (class_exists(\Nwidart\Modules\Facades\Module::class)) {
            $modulesPath = config('modules.paths.modules', base_path('Modules'));
            $moduleDirectoryToExclude = [basename($modulesPath)];
        }

        foreach ($this->scanTargets as $targetKey => $target) {
            // If scanning the main app, add the modules directory name to the exclusion list.
            $extraExcludes = ($targetKey === self::MAIN_APP_KEY && !empty($moduleDirectoryToExclude)) ? $moduleDirectoryToExclude : [];
            $finder = $this->configureFinder([$target['path']], $extraExcludes);
            $allPatterns = $this->getExtractionPatterns();

            foreach ($finder as $file) {
                $this->filesScanned++;
                $extractionBar->setMessage('Scanning: ' . $file->getRelativePathname());
                $relativePath = $file->getRelativePathname();
                $content = $file->getContents();
                $origin = $this->consolidateModules ? self::MAIN_APP_KEY : $targetKey;

                foreach ($allPatterns as $pattern) {
                    if (preg_match_all($pattern, $content, $matches)) {
                        $foundKeys = array_merge(...array_slice($matches, 1));
                        foreach ($foundKeys as $key) {
                            if (empty($key))
                                continue;
                            $key = str_replace('/', '.', $key);
                            if (!isset($keysWithSources[$key])) {
                                $keysWithSources[$key] = [];
                            }
                            if (!in_array($relativePath, $keysWithSources[$key])) {
                                $keysWithSources[$key][] = $relativePath;
                            }
                            if (!isset($this->keyOriginMap[$key])) {
                                $this->keyOriginMap[$key] = $origin;
                            }
                        }
                    }
                }
                $extractionBar->advance();
            }
        }
        $extractionBar->finish();
        $this->line('');
        return [array_keys($keysWithSources), $keysWithSources];
    }

    private function configureFinder(array $scanPaths, array $extraExcludes = []): Finder
    {
        $finder = new Finder();
        $defaultExcludes = explode(',', $this->option('exclude'));
        $filesToExclude = ['artisan', 'composer.json', 'composer.lock', 'failed_translation_keys.json', 'translation_extraction_log.json', 'laravel-translation-extractor.sh', 'package.json', 'package-lock.json', 'phpunit.xml', 'README.md', 'vite.config.js', '.env*', '.phpactor.json', '.phpunit.result.cache', 'Homestead.*', 'auth.json',];

        $finder->files()
            ->in($scanPaths)
            ->exclude(array_merge($defaultExcludes, $extraExcludes)) // Use merged excludes
            ->notName($filesToExclude)
            ->notName('*.log')
            ->ignoreDotFiles(true)
            ->ignoreVCS(true);

        $extensions = explode(',', $this->option('extensions'));
        foreach ($extensions as $ext) {
            $finder->name('*.' . trim($ext));
        }
        return $finder;
    }

    private function ksortRecursive(array &$a): void
    {
        ksort($a);
        foreach ($a as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }


    private function writeTranslationFiles()
    {
        $actionVerb = $this->isOffline ? 'Generated placeholder' : ($this->option('skip-existing') ? 'Updated' : 'Wrote');

        if (empty($this->translations)) {
            $this->info("No new translations were generated, so no files were written.");
            return;
        }

        $this->info(" 💾 {$actionVerb} translation files on disk:");

        foreach ($this->translations as $lang => $processedFiles) {
            foreach ($processedFiles as $contextualFileKey => $newData) {
                [$targetKey, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);

                // If consolidating, all writes go to the main app's lang path
                $writeTargetKey = $this->consolidateModules ? self::MAIN_APP_KEY : $targetKey;
                $target = $this->availableScanTargets[$writeTargetKey];
                $targetBaseDir = $target['lang_path'];

                $existingData = $this->existingTranslations[$lang][$contextualFileKey] ?? [];
                $finalFlatData = array_merge($existingData, $newData);

                if (empty($finalFlatData))
                    continue;
                ksort($finalFlatData);

                if (str_ends_with($fileKey, self::JSON_FILE_KEY)) {
                    $relativePath = str_replace(self::JSON_FILE_KEY, '', $fileKey);
                    $filePath = rtrim($targetBaseDir, '/') . '/' . $relativePath . $lang . '.json';
                    File::ensureDirectoryExists(dirname($filePath));
                    File::put($filePath, json_encode($finalFlatData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $this->line("  <fg=bright-green;options=bold> ✅ {$actionVerb}:</> <fg=bright-cyan>{$filePath}</> <fg=bright-white>(" . count($finalFlatData) . " total keys)</>");
                } else {
                    $finalNestedData = Arr::undot($finalFlatData);
                    $this->ksortRecursive($finalNestedData);

                    $filePath = rtrim($targetBaseDir, '/') . '/' . $lang . '/' . $fileKey . '.php';
                    File::ensureDirectoryExists(dirname($filePath));

                    $content = "<?php\n\nreturn " . var_export($finalNestedData, true) . ";\n";
                    File::put($filePath, $content);

                    $this->line("  <fg=bright-green;options=bold> ✅ {$actionVerb}:</> <fg=bright-cyan>{$filePath}</> <fg=bright-white>(" . count($finalFlatData) . " total keys)</>");
                }
            }
        }
    }

    private function mapKeysToSelectedFiles(array $rawKeys, array $selectedFiles): array
    {
        $structured = [];

        // Invert the selected files for quick lookups
        $selectedFileMap = array_flip($selectedFiles);

        foreach ($rawKeys as $rawKey) {
            $origin = $this->keyOriginMap[$rawKey] ?? self::MAIN_APP_KEY;
            $isPhpKey = false;

            // Determine if the key is a PHP-style key (`file.key`)
            if (str_contains($rawKey, '.')) {
                $prefix = explode('.', $rawKey, 2)[0];
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $prefix)) {
                    $contextualFileKey = $origin . self::FILE_KEY_SEPARATOR . $prefix;

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
                    fn($file) => str_starts_with($file, $origin . self::FILE_KEY_SEPARATOR) && str_ends_with($file, self::JSON_FILE_KEY)
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


    private function determineAvailableFiles(array $allPossibleKeys): array
    {
        $fileGroups = [];

        foreach ($this->fileTargetMap as $contextualFileKey => $targetKey) {
            $fileGroups[$contextualFileKey] = true;
        }

        foreach ($allPossibleKeys as $key) {
            $origin = $this->keyOriginMap[$key] ?? self::MAIN_APP_KEY;

            if (str_contains($key, '.')) {
                $prefix = explode('.', $key, 2)[0];
                if (preg_match('/^[a-zA-Z0-9_-]+$/', $prefix)) {
                    $contextualFileKey = $origin . self::FILE_KEY_SEPARATOR . $prefix;
                    $fileGroups[$contextualFileKey] = true;
                } else {
                    // It's a sentence-like key, so it belongs in a root JSON file
                    $contextualFileKey = $origin . self::FILE_KEY_SEPARATOR . self::JSON_FILE_KEY;
                    $fileGroups[$contextualFileKey] = true;
                }
            } else {
                // No dot, so it belongs in a root JSON file
                $contextualFileKey = $origin . self::FILE_KEY_SEPARATOR . self::JSON_FILE_KEY;
                $fileGroups[$contextualFileKey] = true;
            }
        }

        $uniqueFiles = array_keys($fileGroups);
        sort($uniqueFiles);
        return $uniqueFiles;
    }

    private function promptForFileSelection(array $availableFiles): array
    {
        if (empty($availableFiles)) {
            $this->warn('No processable translation file groups were found.');
            return [];
        }

        $displayChoices = [self::ALL_FILES_KEY => '-- ALL FILES --'] +
            collect($availableFiles)->mapWithKeys(function ($contextualFileKey) {
                [$targetKey, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);
                $targetName = $this->availableScanTargets[$targetKey]['name'];

                if (str_ends_with($fileKey, self::JSON_FILE_KEY)) {
                    $path = str_replace(self::JSON_FILE_KEY, '', $fileKey);
                    $displayName = "{$targetName}: JSON File ({$path}*.json)";
                } else {
                    $displayName = "{$targetName}: {$fileKey}.php";
                }
                return [$contextualFileKey => $displayName];
            })->all();

        $selected = $this->promptForMultiChoice(
            label: 'Which translation files would you like to process?',
            options: $displayChoices,
            hint: 'Use comma-separated numbers (e.g., "1,3") on Windows/simple terminals. Use <space> to select, <enter> to confirm on other systems.'
        );

        if (in_array(self::ALL_FILES_KEY, $selected)) {
            return $availableFiles;
        }

        return $selected;
    }

    private function promptForJsonKeyPrefixes(array $rawKeys, string $jsonFileKey): array
    {
        return [];
    }

    private function populateSourceTextForNewKeys(array $allKeys): void
    {
        foreach ($allKeys as $key) {
            if (!isset($this->sourceTextMap[$key])) {
                if ($this->isOffline) {
                    $this->sourceTextMap[$key] = $key;
                }
            }
        }
    }


    private function loadFrameworkTranslations(): void
    {
        $this->info("Reading Laravel framework default language files...");
        $frameworkLangPath = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        if (!File::isDirectory($frameworkLangPath)) {
            $this->warn("Could not find Laravel framework language directory. Skipping.");
            return;
        }

        foreach (File::files($frameworkLangPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $filename = $file->getFilenameWithoutExtension();
            $contextualFileKey = self::MAIN_APP_KEY . self::FILE_KEY_SEPARATOR . $filename;
            // $this->keyOriginMap[$filename] = self::MAIN_APP_KEY;

            $includedData = @include $file->getPathname();
            if (is_array($includedData)) {
                $flatData = Arr::dot($includedData);
                $this->existingTranslations['en'][$contextualFileKey] = array_merge(
                    $this->existingTranslations['en'][$contextualFileKey] ?? [],
                    $flatData
                );
                foreach ($flatData as $keySuffix => $text) {
                    if (is_string($text)) {
                        $fullKey = "{$filename}.{$keySuffix}";
                        $this->keyOriginMap[$fullKey] = self::MAIN_APP_KEY;
                        if (!isset($this->sourceTextMap[$fullKey])) {
                            $this->sourceTextMap[$fullKey] = $text;
                        }
                    }
                }
            }
        }
    }

    private function getAllKeySources(array $scannedKeys): array
    {
        $allKeys = $scannedKeys;
        foreach ($this->existingTranslations as $lang => $files) {
            foreach ($files as $contextualFileKey => $data) {
                [, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);
                if (str_ends_with($fileKey, self::JSON_FILE_KEY)) {
                    $allKeys = array_merge($allKeys, array_keys($data));
                } else {
                    $prefix = str_replace('/', '.', $fileKey);
                    foreach (array_keys($data) as $keySuffix) {
                        $allKeys[] = "{$prefix}.{$keySuffix}";
                    }
                }
            }
        }
        $allKeys = array_merge($allKeys, array_keys($this->sourceTextMap));
        return array_values(array_unique($allKeys));
    }


    private function performCrossCheckAndReport(array $structuredKeys): void
    {
        $languages = explode(',', $this->option('langs'));
        $missingStats = [];
        foreach ($structuredKeys as $filename => $keys) {
            foreach ($keys as $key) {
                foreach ($languages as $lang) {
                    if (!isset($this->existingTranslations[$lang][$filename][$key])) {
                        $missingStats[$filename][$lang][] = $key;
                    }
                }
            }
        }

        if (empty($missingStats)) {
            $this->success("All selected keys are fully translated and synchronized across all target languages!");
            return;
        }

        $this->warn("Found missing translations needing synchronization:");
        foreach ($missingStats as $contextualFileKey => $langData) {
            [$targetKey, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);
            $targetName = $this->availableScanTargets[$targetKey]['name'];
            $fileNameDisplay = str_ends_with($fileKey, self::JSON_FILE_KEY) ? "JSON File (" . str_replace(self::JSON_FILE_KEY, '*.json', $fileKey) . ")" : "{$fileKey}.php";
            $this->line("  <fg=bright-yellow;options=bold>File: {$targetName} -> {$fileNameDisplay}</>");
            foreach ($langData as $lang => $keys) {
                $count = count($keys);
                $this->line("    <fg=bright-white>-> Language '<fg=bright-cyan>{$lang}</>' is missing <fg=bright-red;options=bold>{$count}</> keys.</>");
            }
        }
    }

    private function filterOutExistingKeys(array $structuredKeys): array
    {
        if (!$this->option('skip-existing')) {
            return $structuredKeys;
        }

        $languages = explode(',', $this->option('langs'));
        $keysThatNeedTranslation = [];

        foreach ($structuredKeys as $contextualFileKey => $keys) {
            foreach ($keys as $key) {
                $isMissingInAtLeastOneLang = false;
                foreach ($languages as $lang) {
                    if (!isset($this->existingTranslations[$lang][$contextualFileKey][$key])) {
                        $isMissingInAtLeastOneLang = true;
                        break;
                    }
                }

                if ($isMissingInAtLeastOneLang) {
                    $keysThatNeedTranslation[$contextualFileKey][] = $key;
                }
            }
        }
        foreach ($keysThatNeedTranslation as &$keys) {
            $keys = array_unique($keys);
        }

        return $keysThatNeedTranslation;
    }


    private function promptForMultiChoice(string $label, array $options, string $hint = '', ?array $default = null): array
    {
        // 1️⃣ Non-interactive environment (CI, cron, supervisor)
        // Do not prompt. Just return defaults or everything.
        if (!$this->input->isInteractive()) {
            return $default ?? array_keys($options);
        }

        // 2️⃣ Windows interactive terminal fallback
        // Laravel Prompts multiselect does NOT work properly on Windows cmd/powershell
        if (PHP_OS_FAMILY === 'Windows') {
            $this->line("<fg=yellow;options=bold>{$label}</>");
            if ($hint) {
                $this->comment($hint);
            }

            // Basic numbered list selection
            $selection = $this->choice(
                question: $label,
                choices: array_values($options),
                default: null,
                attempts: null,
                multiple: true
            );

            $flipped = array_flip($options);

            // Return selected keys based on display string values
            return array_values(
                array_filter(
                    array_map(fn($display) => $flipped[$display] ?? null, $selection)
                )
            );
        }

        // 3️⃣ Interactive Linux/macOS → full multiselect UI
        return multiselect(
            label: $label,
            options: $options,
            hint: $hint,
            default: $default ?? []
        );
    }


    public static function staticStructureTranslationsFromGemini(
        array $geminiData,
        array $originalKeys,
        string $contextualFileKey,
        array $languages,
        array $sourceTextMap
    ): array {
        $chunkTranslations = [];
        [, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);
        $isJsonFile = str_ends_with($fileKey, self::JSON_FILE_KEY);
        $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

        foreach ($originalKeys as $originalKey) {
            $keyToLookup = $isJsonFile ? $originalKey : $prefix . $originalKey;
            $keyTranslations = $geminiData[$keyToLookup] ?? null;

            foreach ($languages as $lang) {
                // no placeholders; always yield clean text
                if (is_array($keyTranslations) && isset($keyTranslations[$lang]) && is_string($keyTranslations[$lang])) {
                    $text = $keyTranslations[$lang];
                } elseif (is_string($keyTranslations) && count($languages) === 1) {
                    // single-language run: Gemini returned a raw string
                    $text = $keyTranslations;
                } else {
                    // fallback to known source text, or the key itself
                    $text = $sourceTextMap[$keyToLookup] ?? $keyToLookup;
                }
                $chunkTranslations[$lang][$contextualFileKey][$originalKey] = $text;
            }
        }
        return $chunkTranslations;
    }

    private function processTranslationResults(array $results, $progressBar): void
    {
        foreach ($results as $result) {
            if ($this->checkForExitSignal()) {
                $this->warn("\n 🛑 User requested to stop. Finishing up...");
                break;
            }
            $this->processedChunks++;
            $chunkCount = $result['chunk_keys_count'] ?? 0;
            if ($result['status'] === 'success') {
                $this->mergeTranslations($result['data']);
                $this->totalKeysSuccessfullyProcessed += $chunkCount;
                $progressBar->setMessage("✅ Chunk {$this->processedChunks}/{$this->totalChunks} - SUCCESS ({$chunkCount} keys)");
            } else {
                $this->error(" ❌ Chunk {$this->processedChunks}/{$this->totalChunks}: " . $result['message']);
                $this->totalKeysFailed += $chunkCount;
                if (isset($result['failed_keys'], $result['filename'])) {
                    $this->failedKeys[$result['filename']] = array_merge(
                        $this->failedKeys[$result['filename']] ?? [],
                        $result['failed_keys']
                    );
                }
                $progressBar->setMessage(" ❌ Chunk {$this->processedChunks}/{$this->totalChunks} - FAILED ({$chunkCount} keys)");
            }
            $progressBar->advance($chunkCount);
        }
    }

    private function checkForExitSignal(): bool
    {
        if ($this->shouldExit)
            return true;
        if (!stream_isatty(STDIN))
            return false;
        stream_set_blocking(STDIN, false);
        $char = fread(STDIN, 1);
        stream_set_blocking(STDIN, true);
        if ($char === $this->option('stop-key')) {
            $this->shouldExit = true;
            return true;
        }
        return false;
    }

    public static function staticTranslateKeysWithGemini(array $keys, array $languages, string $contextualFileKey, int $maxRetries, int $baseRetryDelay, ?string $projectContext = null): array
    {
        $langString = implode(', ', $languages);
        $keysString = '';
        foreach ($keys as $key) {
            $keysString .= "- `{$key}`\n";
        }

        [, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);
        $fileNameForPrompt = str_ends_with($fileKey, self::JSON_FILE_KEY)
            ? 'a main JSON file (e.g., en.json)'
            : "'{$fileKey}.php'";
        $projectContextString = '';
        if (!empty($projectContext)) {
            $sanitizedContext = trim(str_replace(["\n", "\r"], ' ', $projectContext));
            $projectContextString = "- **Project-Specific Context**: Your translations should be tailored for the following context: {$sanitizedContext}\n";
        }

        $prompt = <<<PROMPT
You are an expert Laravel translation generator. Your task is to generate high-quality, professional translations for a list of localization keys. Follow ALL rules below EXACTLY. These rules are strict and non-negotiable.

## 1. OBJECTIVE & CONTEXT
- Goal: Produce accurate translations for the provided keys.
- Source File Context: These keys belong to the Laravel file: {$fileNameForPrompt}.
{$projectContextString}
- Target Languages: Generate translations for: {$langString}.

## 2. KEY INTERPRETATION LOGIC (EXTREMELY IMPORTANT)
You will receive a list of keys. Each key is one of two types:

A) Namespaced Laravel Keys (e.g., auth.failed, validation.required)
- These follow file.subkey patterns.
- Interpret meaning using Laravel's convention.
- If it is a standard Laravel key:
  - Use the official standard phrasing (no creative rewrites).
- If it is a custom namespaced key:
  - Provide a clear, natural, human-readable translation.

B) Literal UI Text (e.g., "Profile", "Save Changes", "An unknown error occurred.")
- Translate the literal displayed text.
- Do not change wording, tone, casing, punctuation, or capitalization unless required for grammar.

## 3. TRANSLATION KEYS TO PROCESS
Translate the following keys:
{$keysString}

## 4. OUTPUT FORMAT RULES (STRICT)
Your entire output must follow ALL these rules:

A) VALID JSON OBJECT ONLY
- Output EXACTLY one JSON object.
- Do NOT include code fences, markdown, comments, or explanations.

B) USE EXACT KEYS
- Top-level keys MUST match the input keys exactly.
- Do NOT modify key names in any way.
- Do NOT split dotted keys.
- Do NOT convert dotted keys into nested objects.
- JSON keys must remain flat, exactly as given.

C) STRICT LANGUAGE STRUCTURE
Each top-level key must map to an object of language => translation pairs.
Example structure (do not output this literally):
{
  "some.key": {
    "en": "English text",
    "ru": "Russian text"
  }
}
- Only include the exact target languages: {$langString}.
- Do NOT invent additional languages.
- Do NOT remove any required languages.

D) NO HTML
- Remove all HTML tags.
- Translate only the human-readable text.

E) PRESERVE PLACEHOLDERS
- Keep placeholders like :attribute, :seconds, :count.
- Do NOT translate placeholder names.
- Do NOT add new placeholders.
- Do NOT remove existing placeholders.

F) TRANSLATION QUALITY REQUIREMENTS
- Use natural, professional language.
- Avoid overly literal translations.
- Maintain correct grammar.
- Do NOT add words or change meaning.
- Do NOT add punctuation unless necessary for grammatical correctness.
- Do NOT invent context.

G) PROPER NOUN PRESERVATION
- Do NOT translate proper names, brand names, or system names.
- Translate only surrounding text.

H) WHITESPACE & FORMATTING
- Preserve spacing exactly.
- Do NOT add extra spaces.
- Do NOT remove spaces.
- Do NOT add trailing whitespace.

## 5. IF A KEY IS UNKNOWN
If a key has no clear or conventional meaning:
- Translate literally.
- Do NOT guess hidden meaning.
- Do NOT output placeholders like "Needs translation".
- Do NOT output internal comments.

## 6. WORKED EXAMPLE (for instruction only)
This example demonstrates the required structure and formatting. This example must NOT appear in your actual output.

Example input:
auth.throttle
Save Changes
I agree to the <strong>Terms of Service</strong>

Example correct output structure:
{
  "auth.throttle": {
    "en": "Too many login attempts. Please try again in :seconds seconds.",
    "ru": "Слишком много попыток входа. Пожалуйста, повторите попытку через :seconds секунд.",
    "uz": "Juda ko‘p urinishlar bo‘ldi. Iltimos, :seconds soniyadan so‘ng qayta urinib ko‘ring."
  },
  "Save Changes": {
    "en": "Save Changes",
    "ru": "Сохранить изменения",
    "uz": "O'zgarishlarni saqlash"
  },
  "I agree to the <strong>Terms of Service</strong>": {
    "en": "I agree to the Terms of Service",
    "ru": "Я согласен с Условиями обслуживания",
    "uz": "Men Xizmat ko‘rsatish shartlariga roziman"
  }
}

## 7. FINAL RULE
Return ONLY the valid JSON object. No other text.
PROMPT;


        $modelToUse = config('gemini.model', 'gemini-2.5-flash-lite');
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Gemini::generativeModel(model: $modelToUse)->generateContent($prompt);
                $responseText = $response->text();
                $cleanedResponseText = preg_replace('/^```json\s*([\s\S]*?)\s*```$/m', '$1', $responseText);
                $decoded = json_decode(trim($cleanedResponseText), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded))
                    return $decoded;
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), 'exceeded')) {
                    if ($attempt < $maxRetries) {
                        $delay = ($baseRetryDelay * pow(2, $attempt) + mt_rand(500, 1500) / 1000);
                        usleep($delay * 1000000);
                        continue;
                    }
                }
                if ($attempt < $maxRetries) {
                    $delay = ($baseRetryDelay * $attempt + mt_rand(500, 2000) / 1000);
                    usleep($delay * 1000000);
                } else {
                    throw $e;
                }
            }
        }
        throw new \Exception("Failed to get valid JSON response from Gemini after {$maxRetries} attempts for keys in {$fileKey}.");
    }

    private function getExtractionPatterns(): array
    {
        $functions = implode('|', ['__', 'trans', 'trans_choice', '@lang', '@choice', 'Lang::get', 'Lang::choice', 'Lang::has', '\$t', 'i18n\.t']);
        $attributes = implode('|', ['v-t', 'x-text']);
        $mainPattern = "/" . "(?:route|config)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$functions})\s*\(\s*['\"]([^'\"]+)['\"]" . "|" . "(?:{$attributes})=['\"]([^'\"]+)['\"]" . "/";
        $patterns = [$mainPattern];
        if (!$this->option('no-advanced')) {
            $commonPrefixes = implode('|', ['messages', 'validation', 'auth', 'pagination', 'passwords', 'general', 'models', 'enums', 'attributes']);
            $advancedPattern = "/" . "(?:route|config)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "['\"]((?:{$commonPrefixes})[.\/][\w.-]+)['\"]" . "/";
            $patterns[] = $advancedPattern;
        }
        return $patterns;
    }

    private function buildTranslationTasks(array $structuredKeys): array
    {
        $languages = explode(',', $this->option('langs'));
        $chunkSize = (int) $this->option('chunk-size');
        $maxRetries = (int) $this->option('max-retries');
        $retryDelay = (int) $this->option('retry-delay');
        $projectContext = $this->option('context');
        $tasks = [];

        foreach ($structuredKeys as $contextualFileKey => $keys) {
            if (empty($keys))
                continue;

            [, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);
            $isJsonFile = str_ends_with($fileKey, self::JSON_FILE_KEY);
            $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

            $fullKeysForAI = $isJsonFile ? $keys : array_map(fn($key) => $prefix . $key, $keys);

            $keyChunks = array_chunk($fullKeysForAI, $chunkSize);
            $originalKeyChunks = array_chunk($keys, $chunkSize);

            foreach ($keyChunks as $index => $chunk) {
                $originalChunk = $originalKeyChunks[$index];

                // capture instance map for the worker/closure
                $sourceTextMap = $this->sourceTextMap;

                $tasks[] = static function () use ($chunk, $originalChunk, $languages, $contextualFileKey, $maxRetries, $retryDelay, $projectContext, $sourceTextMap) {
                    try {
                        $geminiResponse = self::staticTranslateKeysWithGemini(
                            $chunk,
                            $languages,
                            $contextualFileKey,
                            $maxRetries,
                            $retryDelay,
                            $projectContext
                        );

                        $structured = self::staticStructureTranslationsFromGemini(
                            $geminiResponse,
                            $originalChunk,
                            $contextualFileKey,
                            $languages,
                            $sourceTextMap
                        );

                        return [
                            'status' => 'success',
                            'data' => $structured,
                            'chunk_keys_count' => count($chunk),
                        ];
                    } catch (Throwable $e) {
                        return [
                            'status' => 'error',
                            'message' => "File: {$contextualFileKey}, Keys: " . implode(',', array_slice($originalChunk, 0, 3)) . "... - Error: " . $e->getMessage(),
                            'chunk_keys_count' => count($chunk),
                            'failed_keys' => $originalChunk,
                            'filename' => $contextualFileKey
                        ];
                    }
                };
            }

        }
        return $tasks;
    }

    private function runTranslationProcess(array $keysToTranslate): void
    {
        $driver = $this->option('driver');
        if ($driver === 'fork' && function_exists('pcntl_fork') && class_exists(Fork::class)) {
            $this->info("⚡ Using 'fork' driver for high-performance concurrency.");
            $progressBar = $this->output->createProgressBar($this->totalKeysToTranslate);
            $progressBar->setFormatDefinition('custom', '🚀 %current%/%max% [%bar%] %percent:3s%% -- %message% ⏱️  %elapsed:6s%');
            $progressBar->setFormat('custom');
            $progressBar->setMessage('Initializing parallel translation process...');
            $progressBar->start();
            $tasks = $this->buildTranslationTasks($keysToTranslate);
            $results = Fork::new()->concurrent(15)->run(...$tasks);
            $this->processTranslationResults($results, $progressBar);
            $progressBar->finish();
            return;
        }
        $this->warn(" 🐌 Running in synchronous mode - this will be slower but more stable!");
        $this->line('');
        $this->runSeriallyAndTranslate($keysToTranslate);
    }

    private function runSeriallyAndTranslate(array $keysToTranslate): void
    {
        $tasks = $this->buildTranslationTasks($keysToTranslate);
        $this->totalChunks = count($tasks);

        foreach ($tasks as $i => $task) {
            if ($this->checkForExitSignal()) {
                $this->warn("\n 🛑 User requested to stop. Finishing up...");
                break;
            }
            $this->processedChunks++;
            $this->output->write("  <fg=bright-yellow>-></> Processing chunk {$this->processedChunks}/{$this->totalChunks}... ");

            $result = $task();
            $chunkCount = $result['chunk_keys_count'] ?? 0;

            if ($result['status'] === 'success') {
                $this->mergeTranslations($result['data']);
                $this->totalKeysSuccessfullyProcessed += $chunkCount;
                $this->output->writeln("<fg=green;options=bold>✓ Done</>");
            } else {
                $this->output->writeln("<fg=red;options=bold>✗ Failed</>");
                $this->error("     Error: " . $result['message']);
                $this->totalKeysFailed += $chunkCount;
                if (isset($result['failed_keys'], $result['filename'])) {
                    $this->failedKeys[$result['filename']] = array_merge($this->failedKeys[$result['filename']] ?? [], $result['failed_keys']);
                }
            }
        }
    }

    private function generateOfflinePlaceholders(array $keysToTranslate): void
    {
        $this->info("Generating placeholder values for new keys...");
        $languages = explode(',', $this->option('langs'));

        foreach ($keysToTranslate as $contextualFileKey => $keys) {
            [, $fileKey] = explode(self::FILE_KEY_SEPARATOR, $contextualFileKey, 2);
            $isJsonFile = str_ends_with($fileKey, self::JSON_FILE_KEY);
            $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

            foreach ($keys as $key) {
                $fullKey = $isJsonFile ? $key : $prefix . $key;
                $placeholderValue = $this->sourceTextMap[$fullKey] ?? $fullKey;
                foreach ($languages as $lang) {
                    $this->translations[$lang][$contextualFileKey][$key] = $placeholderValue;
                }
            }
        }
        $this->totalKeysSuccessfullyProcessed = $this->totalKeysToTranslate;
        $this->success("Placeholder generation complete.");
    }


    private function mergeTranslations(array $chunkTranslations)
    {
        foreach ($chunkTranslations as $lang => $files) {
            foreach ($files as $filename => $data) {
                $this->translations[$lang][$filename] = array_merge($this->translations[$lang][$filename] ?? [], $data);
            }
        }
    }

    private function saveFailedKeysLog()
    {
        $logData = ['timestamp' => date('Y-m-d H:i:s'), 'failed_keys_by_file' => $this->failedKeys, 'total_failed_count' => $this->totalKeysFailed];
        File::put('failed_translation_keys.json', json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function saveExtractionLog(array $keysWithSources)
    {
        ksort($keysWithSources);
        $logData = ['scan_timestamp' => date('Y-m-d H:i:s'), 'total_unique_keys_found_in_code' => count($keysWithSources), 'keys' => $keysWithSources];
        File::put('translation_extraction_log.json', json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function calculateTotalChunks(array $structuredKeys): int
    {
        $chunkSize = (int) $this->option('chunk-size');
        $total = 0;
        foreach ($structuredKeys as $keys) {
            if (!empty($keys)) {
                $total += count(array_chunk($keys, $chunkSize));
            }
        }
        return $total;
    }

    private function showWelcome()
    {
        $this->line('');
        $this->line('<fg=bright-magenta;options=bold>╔═══════════════════════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=bright-magenta;options=bold></>         <fg=bright-cyan;options=bold> 🌐 LARAVEL AI TRANSLATION SYNCHRONIZATION TOOL (v3.7)</>         <fg=bright-magenta;options=bold></>');
        $this->line('<fg=bright-magenta;options=bold></>         <fg=bright-white>Powered by Gemini AI • Built for Modern Laravel Applications</>        <fg=bright-magenta;options=bold></>');
        $this->line('<fg=bright-magenta;options=bold>╚═══════════════════════════════════════════════════════════════════════════════╝</>');
        $this->line('');
    }

    private function phaseTitle(string $title, string $color = 'yellow')
    {
        $this->line('');
        $pad = max(0, 70 - mb_strlen($title));
        $padding = str_repeat('═', $pad);
        $this->line("<fg=bright-{$color};options=bold>╔═{$title} {$padding}╗</>");
        $this->line('');
    }

    private function success(string $message)
    {
        $this->line("<fg=bright-green;options=bold> ✅ {$message}</>");
    }

    private function displayFinalSummary()
    {
        $executionTime = round(microtime(true) - $this->startTime, 2);
        $this->line('');
        $this->line('<fg=bright-blue;options=bold>╔══════════════════════════════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=bright-blue;options=bold></>                   <fg=bright-white;options=bold>📈 COMPLETE TRANSLATION SUMMARY REPORT</>                     <fg=bright-blue;options=bold></>');
        $this->line('<fg=bright-blue;options=bold>╚══════════════════════════════════════════════════════════════════════════════════════╝</>');
        $this->line('');
        $this->line('  <fg=bright-cyan;options=bold>🔍 DISCOVERY & ANALYSIS STATS</>');
        $this->line("    <fg=bright-white>Code Files Scanned:</>           <fg=bright-cyan;options=bold>{$this->filesScanned}</>");
        $this->line("    <fg=bright-white>Unique Keys Selected:</>         <fg=bright-cyan;options=bold>{$this->uniqueKeysForProcessing}</>");
        $this->line('');
        $this->line('  <fg=bright-magenta;options=bold> 🤖 TRANSLATION STATS</>');
        $this->line("    <fg=bright-white>Total Keys Targeted:</>          <fg=bright-yellow;options=bold>{$this->totalKeysToTranslate}</>");
        $this->line("    <fg=bright-white>Chunks Processed:</>             <fg=bright-yellow;options=bold>{$this->processedChunks} / {$this->totalChunks}</>");
        $this->line("    <fg=bright-white>Keys Successfully Translated:</>  <fg=bright-green;options=bold>{$this->totalKeysSuccessfullyProcessed}</>");
        $this->line("    <fg=bright-white>Keys Failed or Missing:</>       <fg=bright-red;options=bold>{$this->totalKeysFailed}</>");
        if ($this->totalKeysToTranslate > 0) {
            $successRate = $this->totalKeysSuccessfullyProcessed > 0 ? round(($this->totalKeysSuccessfullyProcessed / $this->totalKeysToTranslate) * 100, 2) : 0;
            $rateColor = $successRate >= 95 ? 'bright-green' : ($successRate >= 75 ? 'bright-yellow' : 'bright-red');
            $this->line("    <fg=bright-white>Success Rate:</>                 <fg={$rateColor};options=bold>{$successRate}%</>");
        }
        $this->line('');
        $this->line('  <fg=bright-yellow;options=bold> ⚙️  GENERAL INFO</>');
        $this->line("    <fg=bright-white>Total Execution Time:</>         <fg=bright-yellow;options=bold>{$executionTime} seconds</>");
        if ($this->isOffline) {
            $this->line("    <fg=bright-white>Mode:</>                        <fg=yellow;options=bold>Offline (Placeholders Generated)</>");
        }
        $this->line("    <fg=bright-white>Extraction Log:</>               <fg=bright-cyan>translation_extraction_log.json</>");
        if (!empty($this->failedKeys)) {
            $this->line("    <fg=bright-white>Failure Log:</>                  <fg=bright-red>failed_translation_keys.json</>");
        }
        if ($this->option('context')) {
            $this->line("    <fg=bright-white>Project Context:</>              <fg=bright-cyan>Provided</>");
        }
        $this->line('');
        if ($this->shouldExit) {
            $this->line('<fg=bright-yellow;options=bold> ⚠️  Process was stopped by the user.</>');
        }
        $this->line('<fg=bright-green;options=bold> 🎉 All tasks completed!</>');
        $this->line('');
    }
}