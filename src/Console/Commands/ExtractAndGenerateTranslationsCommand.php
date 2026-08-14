<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Console\Commands;

use Illuminate\Console\Command;
use Jayesh\LaravelGeminiTranslator\Services\FileSystemService;
use Jayesh\LaravelGeminiTranslator\Services\InteractionService;
use Jayesh\LaravelGeminiTranslator\Services\ScannerService;
use Jayesh\LaravelGeminiTranslator\Services\TranslationService;
use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;
use Jayesh\LaravelGeminiTranslator\Utils\TextHelper;
use Nwidart\Modules\Facades\Module;

class ExtractAndGenerateTranslationsCommand extends Command
{
    private const string JSON_FILE_KEY = '__JSON__';

    private const string MAIN_APP_KEY = '__MAIN__';

    protected $signature = 'translations:extract-and-generate
                            {--target-dir=lang : Root directory for final Laravel translation files}
                            {--langs=en : Comma-separated language codes to translate to}
                            {--exclude=vendor,node_modules,storage,public,bootstrap,tests,lang,config,database,routes,app/Console,.phpunit.cache,lang-output,.fleet,.idea,.nova,.vscode,.zed : Comma-separated directories to exclude from scanning}
                            {--extensions=php,blade.php,vue,js,jsx,ts,tsx : Comma-separated file extensions to search}
                            {--chunk-size=25 : Number of keys to send to Gemini in a single request}
                            {--driver=default : Concurrency driver (default, fork, process, sync)}
                            {--concurrency=15 : Number of concurrent processes when using fork or process driver}
                            {--skip-existing : Only translate keys that are missing from one or more language files, then append them.}
                            {--refresh : Re-translate existing keys only, using current file wording as source. Do NOT add new keys.}
                            {--refresh-clean : Like --refresh, but ignore current file wording so stale/faulty values cannot leak. Do NOT add new keys.}
                            {--consolidate-modules : Consolidate all module translations into the main application\'s lang directory.}
                            {--max-retries=5 : Maximum number of retries for failed API calls}
                            {--retry-delay=3 : Base delay in seconds between retries (exponential backoff)}
                            {--stop-key=q : The key to press to gracefully stop the translation process}
                            {--context= : Provide project-specific context to Gemini for better translations}
                            {--model= : Gemini model id (overrides GEMINI_MODEL / config). Any ListModels id, including paid models}
                            {--dry-run : Run full extraction + mapping but show what files would be modified without writing anything}';

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

    private array $targetLanguages = [];

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

    public function __construct(
        private readonly FileSystemService $fileSystemService,
        private readonly ScannerService $scannerService,
        private readonly TranslationService $translationService,
        private readonly InteractionService $interactionService,
    ) {
        parent::__construct();
    }

    /** Check if the input is interactive (for CI/CD detection) */
    public function isInteractive(): bool
    {
        return $this->input->isInteractive();
    }

    public function handle()
    {
        $this->startTime = microtime(true);
        $this->showWelcome();

        if ($this->option('refresh') && $this->option('skip-existing')) {
            $this->error('You cannot use --refresh and --skip-existing together. Choose one.');

            return Command::FAILURE;
        }

        if ($this->option('refresh-clean') && $this->option('skip-existing')) {
            $this->error('You cannot use --refresh-clean and --skip-existing together. Choose one.');

            return Command::FAILURE;
        }

        if ($this->option('refresh') && $this->option('refresh-clean')) {
            $this->error('You cannot use --refresh and --refresh-clean together. Choose one.');

            return Command::FAILURE;
        }

        // Get target languages
        $langsOption = $this->option('langs');
        $languages = array_filter(array_map(trim(...), explode(',', $langsOption)), fn($v) => $v !== '');

        $this->targetLanguages = array_map($this->canonicalizeLocale(...), $languages);

        if ($this->option('dry-run')) {
            $this->info(' 📋 Dry run mode enabled - no files will be written to disk.');
        }

        $apiKey = config('gemini.api_key');
        if (! is_string($apiKey) || $apiKey === '' || $apiKey === 'YOUR_API_KEY') {
            $this->isOffline = true;
            $this->warn(' ⚠️  Gemini API key is not configured. Running in OFFLINE mode.');
            $this->comment('   New translation files will be generated with keys as placeholder values.');
        }

        $this->phaseTitle(' 🔍 Phase 1: Gathering Keys from All Sources', 'cyan');

        $this->availableScanTargets = $this->getScanTargets($this->option('target-dir'));
        $selectedTargets = $this->interactionService->promptForScanTargets($this->availableScanTargets, $this);
        if ($selectedTargets === []) {
            $this->warn('No application or module targets were selected for scanning. Exiting.');

            return Command::SUCCESS;
        }

        $this->scanTargets = array_intersect_key($this->availableScanTargets, array_flip($selectedTargets));
        $this->info('Scanning ' . count($this->scanTargets) . ' target(s): ' . implode(', ', array_column($this->scanTargets, 'name')));

        $availablePacks = $this->fileSystemService->discoverPacks($this->scanTargets);
        $selectedPacks = $this->interactionService->promptForPackSelection($availablePacks, $this);
        if ($selectedPacks === []) {
            $this->warn('No language packs were selected. Exiting.');

            return Command::SUCCESS;
        }
        if (count($availablePacks) > 1) {
            $labels = [];
            foreach ($selectedPacks as $pack) {
                $labels[] = $availablePacks[$pack] ?? ($pack === '' ? 'lang/' : 'lang/' . $pack . '/');
            }
            $this->info('Using pack(s): ' . implode(', ', $labels));
        }

        $this->consolidateModules = $this->interactionService->promptForConsolidation(
            array_diff(array_keys($this->scanTargets), [self::MAIN_APP_KEY]) !== [],
            $this->option('no-interaction'),
            $this->option('consolidate-modules'),
            $this,
        );

        // Load existing translations using the service
        [$this->existingTranslations, $this->fileTargetMap, $this->sourceTextMap, $this->keyOriginMap]
            = $this->fileSystemService->loadExistingTranslations(
                $this->scanTargets,
                $this->targetLanguages,
                $this->consolidateModules,
                $this->output,
                $selectedPacks,
            );

        // Only load framework translations for main application
        if (isset($this->scanTargets[self::MAIN_APP_KEY])) {
            [$this->existingTranslations, $this->fileTargetMap, $this->sourceTextMap, $this->keyOriginMap]
                = $this->fileSystemService->loadFrameworkTranslations(
                    [$this->existingTranslations, $this->fileTargetMap, $this->sourceTextMap, $this->keyOriginMap],
                    $this->option('target-dir'),
                    $this->targetLanguages,
                    $this->option('dry-run'),
                    $this->output,
                );
        }

        [$scannedKeys, $keysWithSources, $this->filesScanned, $keyOriginUpdates]
            = $this->scannerService->extractRawKeys(
                $this->scanTargets,
                [
                    'exclude' => $this->option('exclude'),
                    'extensions' => $this->option('extensions'),
                    'consolidate-modules' => $this->consolidateModules,
                ],
                $this->output,
            );

        // Update key origin map with new values
        $this->keyOriginMap = array_merge($this->keyOriginMap, $keyOriginUpdates);

        $this->fileSystemService->saveExtractionLog($keysWithSources, $this->option('dry-run'), $this->output);
        $this->info('Detailed code extraction log saved to <fg=bright-cyan>translation_extraction_log.json</>');

        $allPossibleKeys = $this->scannerService->getAllKeySources($scannedKeys, $this->existingTranslations, $this->sourceTextMap);
        if ($allPossibleKeys === []) {
            $this->alert('No translation keys were found from any source. Exiting.');

            return Command::SUCCESS;
        }

        $this->sourceTextMap = $this->scannerService->populateSourceTextForNewKeys($allPossibleKeys, $this->sourceTextMap, $this->isOffline);

        $this->success('Key discovery complete! Found ' . count($allPossibleKeys) . ' unique keys from all sources combined.');
        $this->line('');

        $availableFiles = $this->scannerService->determineAvailableFiles($allPossibleKeys, $this->fileTargetMap, $this->scanTargets, $this->keyOriginMap);
        $availableFiles = $this->scannerService->filterFilesByPacks($availableFiles, $selectedPacks, array_keys($availablePacks));
        $selectedFiles = $this->interactionService->promptForFileSelection($availableFiles, $this->scanTargets, $this);

        if ($selectedFiles === []) {
            $this->warn('No files were selected for processing. Exiting.');

            return Command::SUCCESS;
        }

        $keysForProcessing = $this->scannerService->mapKeysToSelectedFiles($allPossibleKeys, $selectedFiles, $this->keyOriginMap);
        $this->uniqueKeysForProcessing = array_sum(array_map(count(...), $keysForProcessing));
        $this->info(' ✅ Selected ' . count($keysForProcessing) . " file groups containing {$this->uniqueKeysForProcessing} unique keys for processing.");

        $refreshOnly = $this->option('refresh') || $this->option('refresh-clean');
        $refreshClean = (bool) $this->option('refresh-clean');
        $skipExisting = $this->option('skip-existing');

        if ($refreshOnly) {
            if ($refreshClean) {
                $this->info('Clean-refreshing existing translations from keys only (existing file wording is ignored).');
            } else {
                $this->info('Refreshing existing translations only (current file wording is used as source; no new keys).');
            }
            $keysToTranslate = $this->translationService->filterForRefreshOnly($keysForProcessing, $this->existingTranslations, $this->targetLanguages);

            // no Phase 1.5 cross-check, because we explicitly ignore "missing"
        } else {
            // For modes A and B we still want the missing-keys report
            $this->phaseTitle('📊 Phase 1.5: Analyzing Translation Status', 'blue');
            $this->translationService->performCrossCheckAndReport(
                $keysForProcessing,
                $this->existingTranslations,
                $this->targetLanguages,
                $this->scanTargets,
                $this->output,
            );

            if ($skipExisting) {
                // MODE B: missing-only
                $keysToTranslate = $this->translationService->filterOutExistingKeys($keysForProcessing, $this->existingTranslations, $this->targetLanguages);
            } else {
                // MODE A: full sync (existing + new)
                $keysToTranslate = $keysForProcessing;
            }
        }

        $this->totalKeysToTranslate = array_sum(array_map(count(...), $keysToTranslate));

        if ($this->totalKeysToTranslate === 0) {
            if ($refreshOnly) {
                $this->success('No existing translations to refresh for the selected files.');
            } elseif ($skipExisting) {
                $this->success('🎉 All selected keys are fully translated. Nothing to do!');
            } else {
                $this->success('No keys found to process for the current selection.');
            }

            $this->displayFinalSummary();

            return Command::SUCCESS;
        }

        if ($this->isOffline) {
            $this->phaseTitle('  Offline Mode: Generating Placeholders', 'yellow');
            $this->generateOfflinePlaceholders($keysToTranslate);
        } else {
            $this->phaseTitle(' 🤖 Phase 2: Translating with Gemini AI', 'magenta');
            if ($this->option('context')) {
                $this->info('💡 Applying project-specific context for enhanced translation accuracy.');
            }

            $this->totalChunks = $this->translationService->calculateTotalChunks($keysToTranslate, (int) $this->option('chunk-size'));
            if ($this->totalChunks === 0) {
                $this->warn('No tasks to run for translation.');
            } else {
                $results = $this->translationService->runTranslationProcess(
                    $keysToTranslate,
                    $this->targetLanguages,
                    $this->sourceTextMap,
                    [
                        'driver' => $this->option('driver'),
                        'chunk-size' => $this->option('chunk-size'),
                        'stop-key' => $this->option('stop-key'),
                        'context' => $this->option('context'),
                        'concurrency' => $this->option('concurrency'),
                        'concurrency_explicit' => $this->input->hasParameterOption('--concurrency'),
                        'skip-existing' => $this->option('skip-existing'),
                        'existing_translations' => $this->existingTranslations,
                        'max-retries' => $this->option('max-retries'),
                        'retry-delay' => $this->option('retry-delay'),
                        'model' => $this->option('model'),
                        'refresh' => $refreshOnly,
                        'refresh_clean' => $refreshClean,
                    ],
                    fn() => $this->checkForExitSignal(),
                    $this->output,
                );

                $this->translations = $results['translations'];
                $this->totalKeysSuccessfullyProcessed = $results['success_count'];
                $this->totalKeysFailed = $results['fail_count'];
                $this->failedKeys = $results['failed_keys'];
                $this->processedChunks = $results['processed_chunks'] ?? 0;
            }
        }

        $this->line('');

        $this->phaseTitle(' 💾 Phase 3: Writing Language Files', 'green');
        $this->fileSystemService->writeTranslationFiles(
            $this->translations,
            $this->scanTargets,
            $this->consolidateModules,
            $this->option('dry-run'),
            $this->option('target-dir'),
            $this->existingTranslations,
            $this->output,
            $this->isOffline,
            $this->option('skip-existing'),
        );

        if ($this->failedKeys !== []) {
            $this->fileSystemService->saveFailedKeysLog($this->failedKeys, $this->option('dry-run'), $this->output);
            $this->warn('Some translations failed. Failed keys have been saved to: <fg=bright-red>failed_translation_keys.json</>');
        }

        $this->displayFinalSummary();

        return Command::SUCCESS;
    }

    private function checkForExitSignal(): bool
    {
        if ($this->shouldExit) {
            return true;
        }

        // Check if STDIN is a TTY (interactive terminal)
        if (! stream_isatty(STDIN)) {
            return false;
        }

        // Set STDIN to non-blocking
        stream_set_blocking(STDIN, false);

        // Read a character from STDIN
        $char = fread(STDIN, 1);

        // Restore blocking mode
        stream_set_blocking(STDIN, true);

        if ($char === $this->option('stop-key')) {
            $this->shouldExit = true;

            return true;
        }

        return false;
    }

    private function generateOfflinePlaceholders(array $keysToTranslate): void
    {
        $this->info('Generating placeholder values for new keys...');

        foreach ($keysToTranslate as $contextualFileKey => $keys) {
            // Parse the contextual key
            if (str_contains((string) $contextualFileKey, '::')) {
                [$targetKey, $fileKey] = explode('::', (string) $contextualFileKey, 2);
            } else {
                $fileKey = $contextualFileKey;
            }

            $isJsonFile = str_ends_with((string) $fileKey, self::JSON_FILE_KEY);
            $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

            foreach ($keys as $key) {
                $fullKey = $isJsonFile ? $key : $prefix . $key;
                $placeholderValue = $this->sourceTextMap[$fullKey] ?? null;

                // If no source text exists, extract display text from machine-like keys and humanize
                if ($placeholderValue === null) {
                    if (TextHelper::looksMachineKey($fullKey)) {
                        $displayText = TextHelper::extractDisplayTextFromNamespacedKey($fullKey);
                        $placeholderValue = LocaleHelper::humanizeForLang($displayText, 'en');
                    } else {
                        $placeholderValue = $fullKey;
                    }
                }

                foreach ($this->targetLanguages as $lang) {
                    $this->translations[$lang][$contextualFileKey][$key] = $placeholderValue;
                }
            }
        }

        $this->totalKeysSuccessfullyProcessed = $this->totalKeysToTranslate;
        $this->success('Placeholder generation complete.');
    }

    private function showWelcome(): void
    {
        $this->line('');
        $this->line('<fg=bright-magenta;options=bold>╔═══════════════════════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=bright-magenta;options=bold></> <fg=bright-cyan;options=bold> 🌐 LARAVEL AI TRANSLATION SYNCHRONIZATION TOOL (v5.1.0)</> <fg=bright-magenta;options=bold></>');
        $this->line('<fg=bright-magenta;options=bold></> <fg=bright-white>Powered by Gemini AI • Built for Modern Laravel Applications</> <fg=bright-magenta;options=bold></>');
        $this->line('<fg=bright-magenta;options=bold>╚═══════════════════════════════════════════════════════════════════════════════╝</>');
        $this->line('');
    }

    private function phaseTitle(string $title, string $color = 'yellow'): void
    {
        $this->line('');
        $pad = max(0, 70 - mb_strlen($title));
        $padding = str_repeat('═', $pad);
        $this->line("<fg=bright-{$color};options=bold>╔═{$title} {$padding}╗</>");
        $this->line('');
    }

    private function success(string $message): void
    {
        $this->line("<fg=bright-green;options=bold> ✅ {$message}</>");
    }

    private function displayFinalSummary(): void
    {
        $executionTime = round(microtime(true) - $this->startTime, 2);
        $this->line('');
        $this->line('<fg=bright-blue;options=bold>╔══════════════════════════════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=bright-blue;options=bold></> <fg=bright-white;options=bold>📈 COMPLETE TRANSLATION SUMMARY REPORT</> <fg=bright-blue;options=bold></>');
        $this->line('<fg=bright-blue;options=bold>╚══════════════════════════════════════════════════════════════════════════════════════╝</>');
        $this->line('');
        $this->line('  <fg=bright-cyan;options=bold>🔍 DISCOVERY & ANALYSIS STATS</>');
        $this->line("<fg=bright-white>Code Files Scanned:</>           <fg=bright-cyan;options=bold>{$this->filesScanned}</>");
        $this->line("<fg=bright-white>Unique Keys Selected:</>         <fg=bright-cyan;options=bold>{$this->uniqueKeysForProcessing}</>");
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
            $this->line('    <fg=bright-white>Mode:</>                        <fg=yellow;options=bold>Offline (Placeholders Generated)</>');
        }

        $this->line('    <fg=bright-white>Extraction Log:</>               <fg=bright-cyan>translation_extraction_log.json</>');
        if ($this->failedKeys !== []) {
            $this->line('    <fg=bright-white>Failure Log:</>                  <fg=bright-red>failed_translation_keys.json</>');
        }

        if ($this->option('context')) {
            $this->line('    <fg=bright-white>Project Context:</>              <fg=bright-cyan>Provided</>');
        }

        $this->line('');
        if ($this->shouldExit) {
            $this->line('<fg=bright-yellow;options=bold> ⚠️  Process was stopped by the user.</>');
        }

        $this->line('<fg=bright-green;options=bold> 🎉 All tasks completed!</>');
        $this->line('');
    }

    /** Get scan targets */
    private function getScanTargets(string $targetDir = 'lang'): array
    {
        $targets = [];
        $targets[self::MAIN_APP_KEY] = [
            'name' => 'Main Application',
            'path' => base_path(),
            'lang_path' => base_path($targetDir),
        ];

        // Check if modules exist and add them to targets
        if (class_exists(Module::class)) {
            $modules = Module::getOrdered();
            foreach ($modules as $module) {
                if ($module->isEnabled()) {
                    $targets[$module->getName()] = [
                        'name' => 'Module: ' . $module->getName(),
                        'path' => $module->getPath(),
                        'lang_path' => $module->getPath() . DIRECTORY_SEPARATOR . $targetDir,
                    ];
                }
            }
        }

        return $targets;
    }

    private function canonicalizeLocale(string $locale): string
    {
        return LocaleHelper::canonicalize($locale);
    }
}
