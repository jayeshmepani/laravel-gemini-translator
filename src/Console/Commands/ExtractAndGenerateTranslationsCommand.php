<?php

namespace Jayesh\LaravelGeminiTranslator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Gemini\Laravel\Facades\Gemini;
use Spatie\Fork\Fork;
use Symfony\Component\Finder\Finder;
use Throwable;
use function Laravel\Prompts\multiselect;

class ExtractAndGenerateTranslationsCommand extends Command
{
    private const JSON_FILE_KEY = '__JSON__';
    private const ALL_FILES_KEY = '__ALL_FILES__';
    private const ALL_PREFIXES_KEY = '__ALL_PREFIXES__';

    protected $signature = 'translations:extract-and-generate
                            {--source=. : The root directory of the application to scan for keys}
                            {--target-dir=lang : Root directory for final Laravel translation files}
                            {--langs=en,ru,uz : Comma-separated language codes to translate to}
                            {--exclude=vendor,node_modules,storage,public,bootstrap,tests,lang,config,database,routes,app/Console,.phpunit.cache,lang-output,.fleet,.idea,.nova,.vscode,.zed : Comma-separated directories to exclude from scanning}
                            {--extensions=php,blade.php,vue,js,jsx,ts,tsx : Comma-separated file extensions to search}
                            {--custom-patterns= : Path to a file with custom regex patterns (one per line: PATTERN|DESCRIPTION|GROUP)}
                            {--no-advanced : Disable advanced, context-based pattern detection}
                            {--chunk-size=100 : Number of keys to send to Gemini in a single request}
                            {--driver=default : Concurrency driver (default, fork, sync)}
                            {--skip-existing : Only translate keys that are missing from one or more language files, then append them.}
                            {--max-retries=5 : Maximum number of retries for failed API calls}
                            {--retry-delay=3 : Base delay in seconds between retries (exponential backoff)}
                            {--stop-key=q : The key to press to gracefully stop the translation process}
                            {--context= : Provide project-specific context to Gemini for better translations}';

    protected $description = ' 🌐 Extracts, cross-checks, translates, and synchronizes language files via Gemini AI.';

    // --- State Properties ---
    private array $translations = [];
    private array $existingTranslations = [];
    private array $sourceTextMap = []; // Stores the source text for each key, prioritizing 'en'
    private array $failedKeys = [];
    private float $startTime;
    private bool $shouldExit = false;

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

        // --- PHASE 1: SCAN & GATHER ---
        $this->phaseTitle(' 🔍 Phase 1: Gathering Keys from All Sources', 'cyan');

        $this->loadExistingTranslations();
        $this->loadFrameworkTranslations();

        [$scannedKeys, $keysWithSources] = $this->extractRawKeys();
        $this->saveExtractionLog($keysWithSources);
        $this->info("Detailed code extraction log saved to <fg=bright-cyan>translation_extraction_log.json</>");

        $allPossibleKeys = $this->getAllKeySources($scannedKeys);
        if (empty($allPossibleKeys)) {
            $this->alert('No translation keys were found from any source (code scan, existing lang files, framework defaults). Exiting.');
            return Command::SUCCESS;
        }

        // FIX: Ensure all discovered keys have a source text, even if it's just the key itself.
        $this->populateSourceTextForNewKeys($allPossibleKeys);

        $this->success("Key discovery complete! Found " . count($allPossibleKeys) . " unique keys from all sources combined.");
        $this->line('');

        // --- INTERACTIVE SELECTION ---
        $availableFiles = $this->determineAvailableFiles($allPossibleKeys);
        $selectedFiles = $this->promptForFileSelection($availableFiles);
        $selectedJsonKeyPrefixes = [];
        if (in_array(self::JSON_FILE_KEY, $selectedFiles)) {
            $selectedJsonKeyPrefixes = $this->promptForJsonKeyPrefixes($allPossibleKeys);
            if (empty($selectedJsonKeyPrefixes)) {
                $selectedFiles = array_diff($selectedFiles, [self::JSON_FILE_KEY]);
            }
        }
        if (empty($selectedFiles)) {
            $this->warn('No files or prefixes were selected for translation. Exiting.');
            return Command::SUCCESS;
        }
        $keysForProcessing = $this->mapKeysToSelectedFiles($allPossibleKeys, $selectedFiles, $selectedJsonKeyPrefixes);
        $this->uniqueKeysForProcessing = array_sum(array_map('count', $keysForProcessing));
        $this->info(" ✅ Selected " . count($keysForProcessing) . " file groups containing {$this->uniqueKeysForProcessing} unique keys for processing.");

        // --- PHASE 1.5: ANALYZE & REPORT ---
        $this->phaseTitle('📊 Phase 1.5: Analyzing Translation Status', 'blue');
        $this->performCrossCheckAndReport($keysForProcessing);

        // --- DETERMINE KEYS TO TRANSLATE ---
        $keysToTranslate = $this->filterOutExistingKeys($keysForProcessing);
        $this->totalKeysToTranslate = array_sum(array_map('count', $keysToTranslate));

        if ($this->totalKeysToTranslate === 0) {
            $this->success(' 🎉 All selected keys already have translations in all target languages. Nothing to do!');
            $this->displayFinalSummary();
            return Command::SUCCESS;
        }

        // --- PHASE 2: TRANSLATE ---
        $this->phaseTitle(' 🤖 Phase 2: Translating with Gemini AI', 'magenta');
        if ($this->option('context')) {
            $this->info("💡 Applying project-specific context for enhanced translation accuracy.");
        }
        $this->totalChunks = $this->calculateTotalChunks($keysToTranslate);
        if ($this->totalChunks === 0) {
            $this->warn('No tasks to run for translation.');
            $this->displayFinalSummary();
            return Command::SUCCESS;
        }
        $this->line("Press the '<fg=bright-red;options=bold>{$this->option('stop-key')}</>' key at any time to gracefully stop the process.");
        $this->info(" 📊 Total keys needing translation: <fg=bright-yellow;options=bold>{$this->totalKeysToTranslate}</>");
        $this->info(" 📦 Total chunks to process: <fg=bright-yellow;options=bold>{$this->totalChunks}</>");

        // --- Run translation based on driver ---
        $this->runTranslationProcess($keysToTranslate);
        $this->line('');


        // --- PHASE 3: WRITE FILES ---
        $this->phaseTitle(' 💾 Phase 3: Writing Language Files', 'green');
        $this->writeTranslationFiles();
        if (!empty($this->failedKeys)) {
            $this->saveFailedKeysLog();
            $this->warn("Some translations failed. Failed keys have been saved to: <fg=bright-red>failed_translation_keys.json</>");
        }
        $this->displayFinalSummary();
        return Command::SUCCESS;
    }

    private function populateSourceTextForNewKeys(array $allKeys): void
    {
        foreach ($allKeys as $key) {
            if (!isset($this->sourceTextMap[$key])) {
                // For brand new keys found only in code, use the key itself as the source text.
                $this->sourceTextMap[$key] = $key;
            }
        }
    }

    private function loadExistingTranslations(): void
    {
        if (!empty($this->existingTranslations)) {
            return;
        }

        $this->info("Reading existing project language files to find all keys...");
        $targetBaseDir = rtrim($this->option('target-dir'), '/');
        $languages = explode(',', $this->option('langs'));
        $loadedTranslations = [];

        // Ensure all languages have an entry to avoid errors later
        foreach ($languages as $lang) {
            $loadedTranslations[$lang] = [];
        }

        if (!File::isDirectory($targetBaseDir)) {
            $this->comment("Language directory '{$targetBaseDir}' not found. Skipping project language file scan.");
            $this->existingTranslations = $loadedTranslations;
            return;
        }

        $allLangDirs = File::directories($targetBaseDir);

        foreach ($allLangDirs as $langDirPath) {
            $lang = basename($langDirPath);
            // Load PHP files
            foreach (File::files($langDirPath, '*.php') as $file) {
                $filename = $file->getFilenameWithoutExtension();
                $includedData = @include $file->getPathname();
                if (is_array($includedData)) {
                    $flatData = Arr::dot($includedData);
                    $loadedTranslations[$lang][$filename] = $flatData;
                    // Populate source text map, prioritizing 'en'
                    foreach ($flatData as $keySuffix => $text) {
                        if (is_string($text)) {
                            $fullKey = "{$filename}.{$keySuffix}";
                            if ($lang === 'en' || !isset($this->sourceTextMap[$fullKey])) {
                                $this->sourceTextMap[$fullKey] = $text;
                            }
                        }
                    }
                }
            }
        }

        // Load JSON files for all languages
        $jsonFiles = File::glob($targetBaseDir . '/*.json');
        foreach ($jsonFiles as $jsonPath) {
            $lang = pathinfo($jsonPath, PATHINFO_FILENAME);
            $jsonContent = json_decode(File::get($jsonPath), true);
            if (is_array($jsonContent)) {
                $loadedTranslations[$lang][self::JSON_FILE_KEY] = $jsonContent;
                foreach ($jsonContent as $key => $text) {
                    if (is_string($text) && ($lang === 'en' || !isset($this->sourceTextMap[$key]))) {
                        $this->sourceTextMap[$key] = $text;
                    }
                }
            }
        }

        $this->existingTranslations = $loadedTranslations;
    }

    private function loadFrameworkTranslations(): void
    {
        $this->info("Reading Laravel framework default language files...");
        $frameworkLangPath = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        if (!File::isDirectory($frameworkLangPath)) {
            $this->warn("Could not find Laravel framework language directory. Skipping.");
            return;
        }

        foreach (File::files($frameworkLangPath, '*.php') as $file) {
            $filename = $file->getFilenameWithoutExtension();
            $includedData = @include $file->getPathname();
            if (is_array($includedData)) {
                $flatData = Arr::dot($includedData);
                // We add these to the 'en' section of existing translations
                // and directly to the source text map, as they are our primary source.
                $this->existingTranslations['en'][$filename] = array_merge(
                    $this->existingTranslations['en'][$filename] ?? [],
                    $flatData
                );
                foreach ($flatData as $keySuffix => $text) {
                    if (is_string($text)) {
                        $fullKey = "{$filename}.{$keySuffix}";
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

        // Add all keys from the existing translations (all languages)
        foreach ($this->existingTranslations as $lang => $files) {
            foreach ($files as $filename => $data) {
                if ($filename === self::JSON_FILE_KEY) {
                    $allKeys = array_merge($allKeys, array_keys($data));
                } else {
                    foreach (array_keys($data) as $keySuffix) {
                        $allKeys[] = "{$filename}.{$keySuffix}";
                    }
                }
            }
        }

        // Add keys from the source map (catches framework keys specifically)
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
        foreach ($missingStats as $file => $langData) {
            $fileNameDisplay = ($file === self::JSON_FILE_KEY) ? "JSON File" : "{$file}.php";
            $this->line("  <fg=bright-yellow;options=bold>File: {$fileNameDisplay}</>");
            foreach ($langData as $lang => $keys) {
                $count = count($keys);
                $this->line("    <fg=bright-white>-> Language '<fg=bright-cyan>{$lang}</>' is missing <fg=bright-red;options=bold>{$count}</> keys.</>");
            }
        }
    }

    private function filterOutExistingKeys(array $structuredKeys): array
    {
        if (empty($this->existingTranslations) && !$this->option('skip-existing')) {
            return $structuredKeys;
        }

        $languages = explode(',', $this->option('langs'));
        $keysThatNeedTranslation = [];

        foreach ($structuredKeys as $filename => $keys) {
            foreach ($keys as $key) {
                $isMissingInAtLeastOneLang = false;
                foreach ($languages as $lang) {
                    // Check if the key is missing in any of the target languages
                    if (!isset($this->existingTranslations[$lang][$filename][$key])) {
                        $isMissingInAtLeastOneLang = true;
                        break;
                    }
                }

                if (!$this->option('skip-existing') || $isMissingInAtLeastOneLang) {
                    // We need source text to translate it
                    $fullKey = ($filename === self::JSON_FILE_KEY) ? $key : "{$filename}.{$key}";
                    if (isset($this->sourceTextMap[$fullKey])) {
                        $keysThatNeedTranslation[$filename][] = $key;
                    }
                }
            }
        }
        foreach ($keysThatNeedTranslation as $filename => &$keys) {
            $keys = array_unique($keys);
        }

        return $keysThatNeedTranslation;
    }

    private function writeTranslationFiles()
    {
        $targetBaseDir = rtrim($this->option('target-dir'), '/');
        $actionVerb = $this->option('skip-existing') ? 'Updated' : 'Wrote';

        if (empty($this->translations)) {
            $this->info("No new translations were generated, so no files were written.");
            return;
        }

        $this->info(" 💾 {$actionVerb} translation files on disk:");

        foreach ($this->translations as $lang => $processedFiles) {
            $langDir = $targetBaseDir . '/' . $lang;
            File::ensureDirectoryExists($langDir);

            foreach ($processedFiles as $filename => $newData) {
                $existingData = $this->existingTranslations[$lang][$filename] ?? [];
                $finalFlatData = array_merge($existingData, $newData);

                if (empty($finalFlatData)) {
                    continue;
                }
                ksort($finalFlatData);

                if ($filename === self::JSON_FILE_KEY) {
                    $filePath = $targetBaseDir . '/' . $lang . '.json';
                    File::put($filePath, json_encode($finalFlatData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $this->line("  <fg=bright-green;options=bold> ✅ {$actionVerb}:</> <fg=bright-cyan>{$filePath}</> <fg=bright-white>(" . count($finalFlatData) . " total keys)</>");
                } else {
                    $finalNestedData = Arr::undot($finalFlatData);
                    ksort($finalNestedData);
                    $filePath = $langDir . '/' . $filename . '.php';
                    $content = "<?php\n\nreturn " . var_export($finalNestedData, true) . ";\n";
                    File::put($filePath, $content);
                    $this->line("  <fg=bright-green;options=bold> ✅ {$actionVerb}:</> <fg=bright-cyan>{$filePath}</> <fg=bright-white>(" . count($finalFlatData) . " total keys)</>");
                }
            }
        }
    }

    private function promptForMultiChoice(string $label, array $options, string $hint = '', ?array $default = null): array
    {
        if (PHP_OS_FAMILY === 'Windows' || !stream_isatty(STDIN)) {
            $this->line("<fg=yellow;options=bold>{$label}</>");
            if ($hint) {
                $this->comment($hint);
            }
            $selection = $this->choice($label, array_values($options), $default ? implode(',', $default) : null, null, true);
            $selectedKeys = [];
            $flippedOptions = array_flip($options);
            foreach ($selection as $selectedDisplay) {
                if (isset($flippedOptions[$selectedDisplay])) {
                    $selectedKeys[] = $flippedOptions[$selectedDisplay];
                }
            }
            return $selectedKeys;
        }
        return multiselect(label: $label, options: $options, hint: $hint, default: $default ?? []);
    }

    private function determineAvailableFiles(array $rawKeys): array
    {
        $files = [];
        $hasJsonKey = false;
        foreach ($rawKeys as $key) {
            if (str_contains($key, '.')) {
                $files[] = explode('.', $key, 2)[0];
            } else {
                $hasJsonKey = true;
            }
        }
        $uniqueFiles = array_values(array_unique($files));
        sort($uniqueFiles);
        if ($hasJsonKey) {
            array_unshift($uniqueFiles, self::JSON_FILE_KEY);
        }
        return $uniqueFiles;
    }

    private function promptForFileSelection(array $availableFiles): array
    {
        if (empty($availableFiles)) {
            $this->warn('No processable translation file groups (like messages.php or validation.php) were found.');
            return [];
        }

        $displayChoices = [self::ALL_FILES_KEY => '-- ALL FILES --'] +
            collect($availableFiles)->mapWithKeys(fn($fileKey) => [$fileKey => $fileKey === self::JSON_FILE_KEY ? 'Root JSON File' : "{$fileKey}.php"])->all();

        $selected = $this->promptForMultiChoice(
            label: 'Which translation files would you like to process?',
            options: $displayChoices,
            hint: 'Use comma-separated numbers (e.g., "1,3") on Windows/simple terminals. Use <space> to select, <enter> to confirm on other systems. Selecting "ALL" will select everything.'
        );

        if (in_array(self::ALL_FILES_KEY, $selected)) {
            return $availableFiles;
        }

        return $selected;
    }

    private function promptForJsonKeyPrefixes(array $rawKeys): array
    {
        $prefixes = [];
        foreach ($rawKeys as $key) {
            if (str_contains($key, '.')) {
                $prefixes[] = explode('.', $key, 2)[0];
            }
        }
        $uniquePrefixes = array_values(array_unique($prefixes));
        sort($uniquePrefixes);

        if (empty($uniquePrefixes)) {
            return [];
        }

        $displayChoices = [self::ALL_PREFIXES_KEY => '-- ALL PREFIXES --'] + array_combine($uniquePrefixes, $uniquePrefixes);

        $selected = $this->promptForMultiChoice(
            label: 'For the JSON file, which key prefixes should be processed?',
            options: $displayChoices,
            hint: 'Keys starting with these prefixes (e.g., "messages.foo") will be added to the JSON file.'
        );

        if (in_array(self::ALL_PREFIXES_KEY, $selected)) {
            return $uniquePrefixes;
        }

        return $selected;
    }

    private function mapKeysToSelectedFiles(array $rawKeys, array $selectedFiles, array $selectedJsonKeyPrefixes): array
    {
        $structured = [];
        $phpFilePrefixes = array_diff($selectedFiles, [self::JSON_FILE_KEY]);
        $isJsonSelected = in_array(self::JSON_FILE_KEY, $selectedFiles);

        foreach ($rawKeys as $rawKey) {
            $wasMappedToPhp = false;
            if (str_contains($rawKey, '.')) {
                $prefix = explode('.', $rawKey, 2)[0];
                if (in_array($prefix, $phpFilePrefixes)) {
                    $keyWithoutPrefix = substr($rawKey, strlen($prefix) + 1);
                    $structured[$prefix][] = $keyWithoutPrefix;
                    $wasMappedToPhp = true;
                }
            }
            if ($isJsonSelected && !$wasMappedToPhp) {
                if (!str_contains($rawKey, '.')) {
                    $structured[self::JSON_FILE_KEY][] = $rawKey;
                } else {
                    $prefix = explode('.', $rawKey, 2)[0];
                    if (in_array($prefix, $selectedJsonKeyPrefixes)) {
                        $structured[self::JSON_FILE_KEY][] = $rawKey;
                    }
                }
            }
        }
        foreach ($structured as &$keys) {
            $keys = array_values(array_unique($keys));
        }
        return $structured;
    }

    public static function staticStructureTranslationsFromGemini(array $geminiData, array $originalKeys, string $filename, array $languages): array
    {
        $chunkTranslations = [];
        foreach ($originalKeys as $originalKey) {
            $keyToLookup = ($filename === self::JSON_FILE_KEY) ? $originalKey : "{$filename}.{$originalKey}";
            $keyTranslations = $geminiData[$keyToLookup] ?? null;

            foreach ($languages as $lang) {
                $translationText = $keyTranslations[$lang] ?? "NEEDS TRANSLATION (KEY: {$keyToLookup})";
                $chunkTranslations[$lang][$filename][$originalKey] = $translationText;
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

    public static function staticTranslateKeysWithGemini(array $keysWithSourceText, array $languages, string $contextFilename, int $maxRetries, int $baseRetryDelay, ?string $projectContext = null): array
    {
        $langString = implode(', ', $languages);
        $keysString = '';
        foreach ($keysWithSourceText as $key => $text) {
            $sanitizedText = str_replace(['"', "\n", "\r"], ["'", ' ', ''], $text);
            $keysString .= "- Key: `{$key}`\n  Text: \"{$sanitizedText}\"\n";
        }

        $fileNameForPrompt = $contextFilename === self::JSON_FILE_KEY ? 'the main JSON file (e.g., en.json)' : "'{$contextFilename}.php'";
        $projectContextString = '';
        if (!empty($projectContext)) {
            $sanitizedContext = trim(str_replace(["\n", "\r"], ' ', $projectContext));
            $projectContextString = "- **Project-Specific Context**: Your translations should be tailored for the following context: {$sanitizedContext}\n";
        }

        $prompt = <<<PROMPT
You are a specialized translation assistant for a Laravel web application. Your sole purpose is to provide high-quality, professional translations for application strings. You must follow the rules below without exception.

### 1. CONTEXT & OBJECTIVE
- **Source File Context**: The following translation keys are from a Laravel file named **{$fileNameForPrompt}**.
{$projectContextString}- **Target Languages**: Translate the text for each key into: **{$langString}**.
- **Primary Goal**: Provide accurate, natural-sounding translations as **PLAIN TEXT ONLY**.

### 2. TRANSLATION KEYS & SOURCE TEXT
Translate the **Text** for each **Key** listed below. Before translating, mentally strip any HTML tags from the original text.
{$keysString}

### 3. CRITICAL TRANSLATION RULES - NON-NEGOTIABLE
#### A. ABSOLUTELY NO HTML
- **THIS IS THE MOST IMPORTANT RULE.** Your output must **NEVER** contain any HTML tags (e.g., `<strong>`, `<a>`, `<span>`, `<br>`, `<i>`).
- If the original text contains HTML, you **MUST** strip it out and translate only the plain text content.
#### B. KEYS & JSON STRUCTURE
- **Use Exact Keys**: You MUST use the exact keys provided in the "TRANSLATION KEYS & SOURCE TEXT" section (e.g., `auth.throttle`, `messages.welcome`). Do not alter, rename, or omit them.
- **Valid JSON Only**: Your entire output must be a single, valid JSON object. Do not include any text, explanations, or code fences like \`\`\`json before or after the JSON.
#### C. PLACEHOLDERS (CRITICAL)
- **Preserve Existing Only**: Only keep Laravel placeholders (words starting with a colon, like `:attribute`, `:name`, `:seconds`) if they ALREADY exist in the original text.
- **Never Invent New Placeholders**: If the original is "50% off", the translation must not become ":percent% off".
- **Do Not Translate Placeholder Names**: The placeholder name itself must NOT be translated. `:attribute` must remain `:attribute` in all languages, never `:атрибут` or `:xususiyat`.
#### D. TONE & CONTENT
- **Natural Language**: Translate phrases naturally. Avoid literal, word-for-word translations.
- **Concise & Clear**: Keep translations clear and to the point.
- **Consistent Terminology**: Keep technical terms like "Login", "Email", "Password", "Dashboard" consistent.

### 4. EXAMPLE
- **INPUT KEYS & TEXT:**
  - Key: `auth.throttle`
    Text: "Too many login attempts. Please try again in :seconds seconds."
  - Key: `messages.agree_html`
    Text: "I agree to the <strong>Terms of Service</strong>"
- **YOUR REQUIRED JSON OUTPUT:**
{
  "auth.throttle": {
    "en": "Too many login attempts. Please try again in :seconds seconds.",
    "ru": "Слишком много попыток входа. Пожалуйста, повторите попытку через :seconds секунд.",
    "uz": "Juda koʻp urinishlar boʻldi. Iltimos, :seconds soniyadan so'ng qayta urinib ko'ring."
  },
  "messages.agree_html": {
    "en": "I agree to the Terms of Service",
    "ru": "Я согласен с Условиями обслуживания",
    "uz": "Men Xizmat ko‘rsatish shartlariga roziman"
  }
}

### 5. FINAL OUTPUT FORMAT
Return ONLY a valid JSON object with the structure shown in the example above. Do not add any commentary.
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
                        $delay = ($baseRetryDelay * pow(3, $attempt) + mt_rand(500, 1500) / 1000);
                        usleep($delay * 1000000);
                    } else {
                        throw $e;
                    }
                } else {
                    if ($attempt < $maxRetries) {
                        $delay = ($baseRetryDelay * $attempt + mt_rand(1000, 3000) / 1000);
                        usleep($delay * 1000000);
                    } else {
                        throw $e;
                    }
                }
            }
        }
        throw new \Exception("Failed to get valid JSON response from Gemini after {$maxRetries} attempts for keys in {$contextFilename}.");
    }

    private function extractRawKeys(): array
    {
        $sourceDir = $this->option('source');
        if (!File::isDirectory($sourceDir)) {
            $this->error(" ❌ Source directory '{$sourceDir}' not found.");
            return [[], []];
        }

        $finder = $this->configureFinder();
        $allPatterns = $this->getExtractionPatterns();
        $keysWithSources = [];

        $extractionBar = $this->output->createProgressBar($finder->count());
        $extractionBar->setFormat("🔎 %message%\n   %current%/%max% [%bar%] %percent:3s%%");
        $extractionBar->setMessage('Scanning project source code...');
        $extractionBar->start();

        foreach ($finder as $file) {
            $this->filesScanned++;
            $relativePath = $file->getRelativePathname();
            $extractionBar->setMessage('Scanning: ' . $relativePath);
            $content = $file->getContents();
            foreach ($allPatterns as $pattern) {
                if (preg_match_all($pattern, $content, $matches)) {
                    $foundKeys = array_merge(...array_slice($matches, 1));
                    foreach ($foundKeys as $key) {
                        if (empty($key))
                            continue;
                        if (!isset($keysWithSources[$key]))
                            $keysWithSources[$key] = [];
                        if (!in_array($relativePath, $keysWithSources[$key]))
                            $keysWithSources[$key][] = $relativePath;
                    }
                }
            }
            $extractionBar->advance();
        }
        $extractionBar->finish();
        $this->line('');
        return [array_keys($keysWithSources), $keysWithSources];
    }

    private function configureFinder(): Finder
    {
        $finder = new Finder();
        $filesToExclude = ['artisan', 'composer.json', 'composer.lock', 'failed_translation_keys.json', 'translation_extraction_log.json', 'laravel-translation-extractor.sh', 'package.json', 'package-lock.json', 'phpunit.xml', 'README.md', 'vite.config.js', '.env*', '.phpactor.json', '.phpunit.result.cache', 'Homestead.*', 'auth.json',];
        $finder->files()->in($this->option('source'))->exclude(explode(',', $this->option('exclude')))->notName($filesToExclude)->notName('*.log')->ignoreDotFiles(true)->ignoreVCS(true);
        $extensions = explode(',', $this->option('extensions'));
        foreach ($extensions as $ext)
            $finder->name('*.' . trim($ext));
        return $finder;
    }

    private function getExtractionPatterns(): array
    {
        $functions = implode('|', ['__', 'trans', 'trans_choice', '@lang', '@choice', 'Lang::get', 'Lang::choice', 'Lang::has', '\$t', 'i18n\.t']);
        $attributes = implode('|', ['v-t', 'x-text']);
        $mainPattern = "/" . "(?:route|config)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "(?:{$functions})\s*\(\s*['\"]([^'\"]+)['\"]" . "|" . "(?:{$attributes})=['\"]([^'\"]+)['\"]" . "/";
        $patterns = [$mainPattern];
        if (!$this->option('no-advanced')) {
            $commonPrefixes = implode('|', ['messages', 'validation', 'auth', 'pagination', 'passwords', 'general', 'models', 'enums', 'attributes']);
            $advancedPattern = "/" . "(?:route|config)\s*\([^\)]+\)(*SKIP)(*FAIL)" . "|" . "['\"]((?:{$commonPrefixes})\.[\w.-]+)['\"]" . "/";
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
        foreach ($structuredKeys as $filename => $keys) {
            if (empty($keys))
                continue;
            $keyChunks = array_chunk($keys, $chunkSize);
            foreach ($keyChunks as $chunk) {
                $chunkWithSourceText = [];
                foreach ($chunk as $key) {
                    $fullKey = ($filename === self::JSON_FILE_KEY) ? $key : "{$filename}.{$key}";
                    $chunkWithSourceText[$fullKey] = $this->sourceTextMap[$fullKey] ?? $key;
                }
                $tasks[] = static function () use ($chunk, $chunkWithSourceText, $languages, $filename, $maxRetries, $retryDelay, $projectContext) {
                    try {
                        $geminiResponse = self::staticTranslateKeysWithGemini($chunkWithSourceText, $languages, $filename, $maxRetries, $retryDelay, $projectContext);
                        return ['status' => 'success', 'data' => self::staticStructureTranslationsFromGemini($geminiResponse, $chunk, $filename, $languages), 'chunk_keys_count' => count($chunk)];
                    } catch (Throwable $e) {
                        return ['status' => 'error', 'message' => "File: {$filename}, Keys: " . implode(',', array_slice($chunk, 0, 3)) . "... - Error: " . $e->getMessage(), 'chunk_keys_count' => count($chunk), 'failed_keys' => $chunk, 'filename' => $filename];
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
        $languages = explode(',', $this->option('langs'));
        $chunkSize = (int) $this->option('chunk-size');
        $maxRetries = (int) $this->option('max-retries');
        $retryDelay = (int) $this->option('retry-delay');
        $projectContext = $this->option('context');

        foreach ($keysToTranslate as $filename => $keys) {
            if (empty($keys))
                continue;
            $this->line('');
            $this->info("Processing file: <fg=bright-cyan>{$filename}</>");
            $keyChunks = array_chunk($keys, $chunkSize);
            $totalKeysInFile = count($keys);
            $processedKeysInFile = 0;
            foreach ($keyChunks as $i => $chunk) {
                if ($this->checkForExitSignal()) {
                    $this->warn("\n 🛑 User requested to stop. Finishing up...");
                    break 2;
                }
                $this->processedChunks++;
                $chunkKeyCount = count($chunk);
                $startKeyNum = $processedKeysInFile + 1;
                $endKeyNum = $processedKeysInFile + $chunkKeyCount;
                $this->output->write("  <fg=bright-yellow>-></> Processing keys {$startKeyNum}-{$endKeyNum} of {$totalKeysInFile}... ");
                try {
                    $chunkWithSourceText = [];
                    foreach ($chunk as $key) {
                        $fullKey = ($filename === self::JSON_FILE_KEY) ? $key : "{$filename}.{$key}";
                        $chunkWithSourceText[$fullKey] = $this->sourceTextMap[$fullKey] ?? $key;
                    }
                    $geminiResponse = self::staticTranslateKeysWithGemini($chunkWithSourceText, $languages, $filename, $maxRetries, $retryDelay, $projectContext);
                    $structuredTranslations = self::staticStructureTranslationsFromGemini($geminiResponse, $chunk, $filename, $languages);
                    $this->mergeTranslations($structuredTranslations);
                    $this->totalKeysSuccessfullyProcessed += $chunkKeyCount;
                    $this->output->writeln("<fg=green;options=bold>✓ Done</>");
                } catch (Throwable $e) {
                    $this->output->writeln("<fg=red;options=bold>✗ Failed</>");
                    $this->error("     Error: " . $e->getMessage());
                    $this->totalKeysFailed += $chunkKeyCount;
                    $this->failedKeys[$filename] = array_merge($this->failedKeys[$filename] ?? [], $chunk);
                }
                $processedKeysInFile += $chunkKeyCount;
            }
        }
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
        $this->line('<fg=bright-magenta;options=bold></>         <fg=bright-cyan;options=bold> 🌐 LARAVEL AI TRANSLATION SYNCHRONIZATION TOOL (v3.0)</>         <fg=bright-magenta;options=bold></>');
        $this->line('<fg=bright-magenta;options=bold></>         <fg=bright-white>Powered by Gemini AI • Built for Modern Laravel Applications</>        <fg=bright-magenta;options=bold></>');
        $this->line('<fg=bright-magenta;options=bold>╚═══════════════════════════════════════════════════════════════════════════════╝</>');
        $this->line('');
    }

    private function phaseTitle(string $title, string $color = 'yellow')
    {
        $this->line('');
        $padding = str_repeat('═', 70 - mb_strlen($title));
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
        $this->line("    <fg=bright-white>Output Directory:</>             <fg=bright-cyan>{$this->option('target-dir')}</>");
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