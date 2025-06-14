<?php

namespace Jayesh\LaravelGeminiTranslator\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Gemini\Laravel\Facades\Gemini;
use Spatie\Fork\Fork;
use Throwable;
use function Laravel\Prompts\multiselect;

class ExtractAndGenerateTranslationsCommand extends Command
{
    /**
     * A special internal key to represent the root JSON translation file (e.g., en.json).
     */
    private const JSON_FILE_KEY = '__JSON__';
    private const ALL_FILES_KEY = '__ALL_FILES__';
    private const ALL_PREFIXES_KEY = '__ALL_PREFIXES__';


    /**
     * The name and signature of the console command.
     */
    protected $signature = 'translations:extract-and-generate
                            {--source=. : The root directory of the application to scan for keys}
                            {--target-dir=lang : Root directory for final Laravel translation files}
                            {--langs=en,ru,uz : Comma-separated language codes to translate to}
                            {--exclude=vendor,node_modules,storage,public,bootstrap,tests,lang,config,database,routes,app/Console,.phpunit.cache,lang-output,.fleet,.idea,.nova,.vscode,.zed : Comma-separated directories to exclude from scanning}
                            {--extensions=php,blade.php,vue,js,jsx,ts,tsx : Comma-separated file extensions to search}
                            {--custom-patterns= : Path to a file with custom regex patterns (one per line: PATTERN|DESCRIPTION|GROUP)}
                            {--no-advanced : Disable advanced, context-based pattern detection}
                            {--chunk-size=100 : Number of keys to send to Gemini in a single request}
                            {--driver=default : Concurrency driver (default, fork, async, sync)}
                            {--skip-existing : Skip keys that already have translations in all target languages}
                            {--max-retries=5 : Maximum number of retries for failed API calls}
                            {--retry-delay=3 : Base delay in seconds between retries (exponential backoff)}
                            {--stop-key=q : The key to press to gracefully stop the translation process}';

    protected $description = ' 🌐 Interactively extracts, translates (flat PHP & JSON), and generates language files via Gemini AI.';

    // --- State Properties ---
    private array $translations = [];
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

        $this->phaseTitle(' 🔍 Phase 1: Scanning Project & Extracting All Keys', 'cyan');
        [$rawKeys, $keysWithSources] = $this->extractRawKeys();

        $this->saveExtractionLog($keysWithSources);
        $this->info("Detailed extraction log saved to <fg=bright-cyan>translation_extraction_log.json</>");

        if (empty($rawKeys)) {
            $this->alert('No translation keys were found in the project. Exiting.');
            return Command::SUCCESS;
        }
        $this->success("Scan complete! Found " . count($rawKeys) . " unique raw keys.");
        $this->line('');

        $availableFiles = $this->determineAvailableFiles($rawKeys);
        $selectedFiles = $this->promptForFileSelection($availableFiles);

        $selectedJsonKeyPrefixes = [];
        if (in_array(self::JSON_FILE_KEY, $selectedFiles)) {
            $selectedJsonKeyPrefixes = $this->promptForJsonKeyPrefixes($rawKeys);
            if (empty($selectedJsonKeyPrefixes)) {
                $selectedFiles = array_diff($selectedFiles, [self::JSON_FILE_KEY]);
            }
        }

        if (empty($selectedFiles)) {
            $this->warn('No files or prefixes were selected for translation. Exiting.');
            return Command::SUCCESS;
        }

        $structuredKeys = $this->mapKeysToSelectedFiles($rawKeys, $selectedFiles, $selectedJsonKeyPrefixes);
        $this->uniqueKeysForProcessing = array_sum(array_map('count', $structuredKeys));

        if ($this->uniqueKeysForProcessing === 0) {
            $this->warn('After filtering based on your selection, there are no keys to process. Exiting.');
            return Command::SUCCESS;
        }
        $this->info(" ✅ Processing {$this->uniqueKeysForProcessing} keys for the selected files and prefixes.");

        $this->phaseTitle(' 🤖 Phase 2: Translating Selected Keys with Gemini AI', 'magenta');

        if ($this->option('skip-existing')) {
            $structuredKeys = $this->filterOutExistingKeys($structuredKeys);
            $this->info(" 🎯 After checking existing translations, " . array_sum(array_map('count', $structuredKeys)) . " keys remain to be translated.");
        }

        $this->totalKeysToTranslate = array_sum(array_map('count', $structuredKeys));

        if ($this->totalKeysToTranslate === 0) {
            $this->success(' 🎉 All selected keys already have translations. Nothing to do!');
            $this->displayFinalSummary();
            return Command::SUCCESS;
        }

        $tasks = $this->buildTranslationTasks($structuredKeys);
        $this->totalChunks = count($tasks);

        if ($this->totalChunks === 0) {
            $this->warn('No tasks to run for translation.');
            $this->displayFinalSummary();
            return Command::SUCCESS;
        }

        $this->line("Press the '<fg=bright-red;options=bold>{$this->option('stop-key')}</>' key at any time to gracefully stop the process.");
        $this->info(" 📊 Total keys to translate: <fg=bright-yellow;options=bold>{$this->totalKeysToTranslate}</>");
        $this->info(" 📦 Total chunks to process: <fg=bright-yellow;options=bold>{$this->totalChunks}</>");

        $progressBar = $this->output->createProgressBar($this->totalKeysToTranslate);
        $progressBar->setFormatDefinition('custom', '🚀 %current%/%max% [%bar%] %percent:3s%% -- %message% ⏱️  %elapsed:6s%');
        $progressBar->setFormat('custom');
        $progressBar->setMessage('Initializing translation process...');
        $progressBar->start();

        $results = $this->runTasksInParallel($tasks, $this->option('driver'));
        $this->processTranslationResults($results, $progressBar);
        $progressBar->finish();
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

        $selected = multiselect(
            label: 'Which translation files would you like to process?',
            options: $displayChoices,
            hint: 'Press <space> to select, <enter> to confirm. Selecting "ALL" will select everything.'
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

        $selected = multiselect(
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
            $keyTranslations = $geminiData[$originalKey] ?? null;
            foreach ($languages as $lang) {
                $translationText = $keyTranslations[$lang] ?? "NEEDS TRANSLATION (KEY: {$originalKey})";
                $chunkTranslations[$lang][$filename][$originalKey] = $translationText;
            }
        }
        return $chunkTranslations;
    }

    private function writeTranslationFiles()
    {
        $targetBaseDir = rtrim($this->option('target-dir'), '/');
        $languages = explode(',', $this->option('langs'));
        $this->info(" 💾 Writing translation files to disk (overwriting existing files):");

        foreach ($languages as $lang) {
            $langDir = $targetBaseDir . '/' . $lang;
            File::ensureDirectoryExists($langDir);

            if (isset($this->translations[$lang]) && !empty($this->translations[$lang])) {
                foreach ($this->translations[$lang] as $filename => $data) {
                    if (empty($data))
                        continue;

                    if ($filename === self::JSON_FILE_KEY) {
                        $filePath = $targetBaseDir . '/' . $lang . '.json';
                        ksort($data);
                        File::put($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $this->line("  <fg=bright-green;options=bold> ✅ Wrote:</> <fg=bright-cyan>{$filePath}</> <fg=bright-white>(" . count($data) . " keys)</>");
                    } else {
                        $filePath = $langDir . '/' . $filename . '.php';
                        ksort($data);
                        $content = "<?php\n\nreturn " . var_export($data, true) . ";\n";
                        File::put($filePath, $content);
                        $this->line("  <fg=bright-green;options=bold> ✅ Wrote:</> <fg=bright-cyan>{$filePath}</> <fg=bright-white>(" . count($data) . " keys)</>");
                    }
                }
            } else {
                $this->warn("No translations were processed for language '<fg=bright-red>{$lang}</>'.");
            }
        }
    }

    private function filterOutExistingKeys(array $structuredKeys): array
    {
        $this->info("🔍 Loading existing translations to filter out completed keys...");
        $targetBaseDir = rtrim($this->option('target-dir'), '/');
        $languages = explode(',', $this->option('langs'));
        $existingTranslationsLookup = [];

        foreach ($languages as $lang) {
            $jsonPath = $targetBaseDir . '/' . $lang . '.json';
            if (File::exists($jsonPath)) {
                $existingTranslationsLookup[$lang][self::JSON_FILE_KEY] = json_decode(File::get($jsonPath), true) ?: [];
            }
            $langDir = $targetBaseDir . '/' . $lang;
            if (File::isDirectory($langDir)) {
                foreach (File::files($langDir) as $file) {
                    $filename = $file->getFilenameWithoutExtension();
                    $existingTranslationsLookup[$lang][$filename] = @include $file->getPathname();
                }
            }
        }

        if (empty($existingTranslationsLookup)) {
            $this->warn('No existing translation files found. Translating all keys.');
            return $structuredKeys;
        }

        $keysToTranslate = [];
        foreach ($structuredKeys as $filename => $keys) {
            foreach ($keys as $key) {
                $isMissing = false;
                foreach ($languages as $lang) {
                    $translationExists = !empty(data_get($existingTranslationsLookup, "{$lang}.{$filename}.{$key}"));
                    if (!$translationExists) {
                        $isMissing = true;
                        break;
                    }
                }
                if ($isMissing) {
                    $keysToTranslate[$filename][] = $key;
                }
            }
        }
        return $keysToTranslate;
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

    public static function staticTranslateKeysWithGemini(array $keys, array $languages, string $contextFilename, int $maxRetries, int $baseRetryDelay): array
    {
        $langString = implode(', ', $languages);
        $keysString = "- " . implode("\n- ", $keys);
        $fileNameForPrompt = $contextFilename === self::JSON_FILE_KEY ? 'the main JSON file (e.g., en.json)' : "'{$contextFilename}.php'";

        $prompt = <<<PROMPT
You are a specialized translation assistant for a Laravel web application. Your sole purpose is to provide high-quality, professional translations for application strings. You must follow the rules below without exception.

### 1. CONTEXT & OBJECTIVE
- **Source File Context**: The following translation keys are from a Laravel file named **{$fileNameForPrompt}**.
- **Target Languages**: Translate the text for each key into: **{$langString}**.
- **Primary Goal**: Provide accurate, natural-sounding translations as **PLAIN TEXT ONLY**.

### 2. TRANSLATION KEYS
Translate the values for the following keys. Before translating, mentally strip any HTML tags from the original text.
{$keysString}

### 3. CRITICAL TRANSLATION RULES - NON-NEGOTIABLE

#### A. ABSOLUTELY NO HTML
- **THIS IS THE MOST IMPORTANT RULE.**
- Your output must **NEVER** contain any HTML tags (e.g., `<strong>`, `<a>`, `<span>`, `<br>`, `<i>`).
- If the original key's text contains HTML, you **MUST** strip it out and translate only the plain text content.
- **Example**: If the original is `I agree to the <strong>Terms</strong>`, your source for translation is "I agree to the Terms".

#### B. KEYS & JSON STRUCTURE
- **Use Exact Keys**: You MUST use the exact keys provided in the "TRANSLATION KEYS" section. Do not alter, rename, or omit them.
- **Valid JSON Only**: Your entire output must be a single, valid JSON object. Do not include any text, explanations, or code fences like \`\`\`json before or after the JSON.

#### C. PLACEHOLDERS (CRITICAL)
- **Preserve Existing Only**: Only keep Laravel placeholders (words starting with a colon, like `:attribute`, `:name`, `:value`, `:min`) if they ALREADY exist in the original text.
- **Never Invent New Placeholders**: If the original is "50% off", the translation must not become ":percent% off".
- **Do Not Translate Placeholder Names**: The placeholder name itself must NOT be translated. `:attribute` must remain `:attribute` in all languages, never `:атрибут` or `:xususiyat`.

#### D. TONE & CONTENT
- **Natural Language**: Translate phrases naturally. Avoid literal, word-for-word translations.
- **Concise & Clear**: Keep translations clear and to the point.
- **Consistent Terminology**: Keep technical terms like "Login", "Email", "Password", "Dashboard" consistent.
- **Preserve Symbols**: Simple symbols like `*`, `#`, `@` must be kept as-is.

### 4. EXAMPLES OF CORRECT BEHAVIOR

#### HTML STRIPPING:
- **Original**: `messages.agree_terms` -> `I agree to the <strong>Terms of Service</strong>`
- **Your Thought Process**: Original text is "I agree to the Terms of Service".
- **✅ CORRECT OUTPUT**: `{ "en": "I agree to the Terms of Service", "ru": "Я согласен с Условиями обслуживания", "uz": "Men Xizmat ko‘rsatish shartlariga roziman" }`
- **❌ INCORRECT**: `{ "ru": "Я согласен с <strong>Условиями обслуживания</strong>" }` (Contains HTML)

#### PLACEHOLDER HANDLING:
- **Original**: `messages.welcome_user` -> `Welcome, :name!`
- **✅ CORRECT OUTPUT**: `{ "en": "Welcome, :name!", "ru": "Добро пожаловать, :name!", "uz": "Xush kelibsiz, :name!" }`
- **❌ INCORRECT**: `{ "ru": "Добро пожаловать, :имя!" }` (Placeholder name was translated)

#### PLAIN TEXT HANDLING:
- **Original**: `messages.all_news` -> `All News`
- **✅ CORRECT OUTPUT**: `{ "en": "All News", "ru": "Все новости", "uz": "Barcha yangiliklar" }`
- **❌ INCORRECT**: `{ "en": "All the News Articles" }` (Unnecessary elaboration)

### 5. FINAL OUTPUT FORMAT
Return ONLY a valid JSON object with the following exact structure. Do not add any commentary.

{
  "exact.original.key": { "en": "English text", "ru": "Русский текст", "uz": "O'zbek matni" },
  "another.key.as.provided": { "en": "Another text", "ru": "Другой текст", "uz": "Boshqa matn" }
}
PROMPT;

        $modelToUse = config('gemini.model', 'gemini-2.0-flash-lite');

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Gemini::generativeModel(model: $modelToUse)->generateContent($prompt);
                $responseText = $response->text();
                $cleanedResponseText = preg_replace('/^```json\s*([\s\S]*?)\s*```$/m', '$1', $responseText);
                $decoded = json_decode(trim($cleanedResponseText), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), 'exceeded')) {
                    if ($attempt < $maxRetries) {
                        usleep(($baseRetryDelay * pow(3, $attempt) + mt_rand(500, 1500) / 1000) * 1000000);
                    } else {
                        throw $e;
                    }
                } else {
                    if ($attempt < $maxRetries) {
                        usleep(($baseRetryDelay * $attempt + mt_rand(1000, 3000) / 1000) * 1000000);
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
        $extractionBar->setMessage('Starting scan...');
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

                        if (!isset($keysWithSources[$key])) {
                            $keysWithSources[$key] = [];
                        }
                        if (!in_array($relativePath, $keysWithSources[$key])) {
                            $keysWithSources[$key][] = $relativePath;
                        }
                    }
                }
            }
            $extractionBar->advance();
        }
        $extractionBar->finish();
        $this->line('');

        $rawKeys = array_keys($keysWithSources);
        return [$rawKeys, $keysWithSources];
    }

    private function configureFinder(): Finder
    {
        $finder = new Finder();

        $filesToExclude = [
            'artisan',
            'composer.json',
            'composer.lock',
            'failed_translation_keys.json',
            'translation_extraction_log.json',
            'laravel-translation-extractor.sh',
            'package.json',
            'package-lock.json',
            'phpunit.xml',
            'README.md',
            'vite.config.js',
            '.env*',
            '.phpactor.json',
            '.phpunit.result.cache',
            'Homestead.*',
            'auth.json',
        ];

        $finder->files()
            ->in($this->option('source'))
            ->exclude(explode(',', $this->option('exclude')))
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

    private function getExtractionPatterns(): array
    {
        // Define translation functions and prefixes
        $functions = implode('|', ['__', 'trans', 'trans_choice', '@lang', '@choice', 'Lang::get', 'Lang::choice', 'Lang::has', '\$t', 'i18n\.t']);
        $attributes = implode('|', ['v-t', 'x-text']);

        // This combined pattern first finds and discards `route()` and `config()` calls, then looks for translation keys.
        $mainPattern = "/" .
            // 1. Match `route(...)` or `config(...)` and skip it
            "(?:route|config)\s*\([^\)]+\)(*SKIP)(*FAIL)" .
            "|" . // OR
            // 2. Match standard functions: `__('key.here')` or `$t('key.here')`
            "(?:{$functions})\s*\(\s*['\"]([^'\"]+)['\"]" .
            "|" . // OR
            // 3. Match attributes: `v-t="'key.here'"`
            "(?:{$attributes})=['\"]([^'\"]+)['\"]" .
            "/";

        $patterns = [$mainPattern];

        if (!$this->option('no-advanced')) {
            $commonPrefixes = implode('|', ['messages', 'validation', 'auth', 'pagination', 'passwords', 'general', 'models', 'enums', 'attributes']);

            $advancedPattern = "/" .
                "(?:route|config)\s*\([^\)]+\)(*SKIP)(*FAIL)" .
                "|" .
                "['\"]((?:{$commonPrefixes})\.[\w.-]+)['\"]" . // Capture Group 1
                "/";
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
        $tasks = [];
        foreach ($structuredKeys as $filename => $keys) {
            if (empty($keys))
                continue;

            $keyChunks = array_chunk($keys, $chunkSize);
            foreach ($keyChunks as $chunk) {
                $tasks[] = static function () use ($chunk, $languages, $filename, $maxRetries, $retryDelay) {
                    try {
                        $geminiResponse = self::staticTranslateKeysWithGemini($chunk, $languages, $filename, $maxRetries, $retryDelay);
                        return [
                            'status' => 'success',
                            'data' => self::staticStructureTranslationsFromGemini($geminiResponse, $chunk, $filename, $languages),
                            'chunk_keys_count' => count($chunk)
                        ];
                    } catch (Throwable $e) {
                        return [
                            'status' => 'error',
                            'message' => "File: {$filename}, Keys: " . implode(',', array_slice($chunk, 0, 3)) . "... - Error: " . $e->getMessage(),
                            'chunk_keys_count' => count($chunk),
                            'failed_keys' => $chunk,
                            'filename' => $filename
                        ];
                    }
                };
            }
        }
        return $tasks;
    }

    private function runTasksInParallel(array $tasks, ?string $driver): array
    {
        $driver = $driver === 'default' ? null : $driver;
        if ($driver === 'fork' && function_exists('pcntl_fork') && class_exists(Fork::class)) {
            $this->info("⚡ Using 'fork' driver for high-performance concurrency.");
            return Fork::new()->concurrent(15)->run(...$tasks);
        }
        if ($driver === 'async' || $driver === null) {
            $this->warn("The 'async' (Process Pool) driver is not fully implemented. Falling back to 'sync'.");
            return $this->runSynchronously($tasks);
        }
        $this->warn(" 🐌 Running in synchronous mode - this will be slower but more stable!");
        return $this->runSynchronously($tasks);
    }

    private function runSynchronously(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $task) {
            if ($this->checkForExitSignal())
                break;
            $results[] = $task();
        }
        return $results;
    }

    private function mergeTranslations(array $chunkTranslations)
    {
        foreach ($chunkTranslations as $lang => $files) {
            foreach ($files as $filename => $data) {
                $this->translations[$lang][$filename] = array_merge(
                    $this->translations[$lang][$filename] ?? [],
                    $data
                );
            }
        }
    }

    private function saveFailedKeysLog()
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'failed_keys_by_file' => $this->failedKeys,
            'total_failed_count' => $this->totalKeysFailed
        ];
        File::put('failed_translation_keys.json', json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function saveExtractionLog(array $keysWithSources)
    {
        ksort($keysWithSources);
        $logData = [
            'scan_timestamp' => date('Y-m-d H:i:s'),
            'total_unique_keys_found' => count($keysWithSources),
            'keys' => $keysWithSources
        ];
        File::put('translation_extraction_log.json', json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function showWelcome()
    {
        $this->line('');
        $this->line('<fg=bright-magenta;options=bold>╔═══════════════════════════════════════════════════════════════════════════════╗</>');
        $this->line('<fg=bright-magenta;options=bold></>           <fg=bright-cyan;options=bold> 🌐 LARAVEL AI TRANSLATION EXTRACTION & GENERATION TOOL</>          <fg=bright-magenta;options=bold></>');
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
        $this->line('  <fg=bright-cyan;options=bold>🔍 EXTRACTION STATS</>');
        $this->line("    <fg=bright-white>Files Scanned:</>                 <fg=bright-cyan;options=bold>{$this->filesScanned}</>");
        $this->line("    <fg=bright-white>Unique Keys for Processing:</>   <fg=bright-cyan;options=bold>{$this->uniqueKeysForProcessing}</>");

        $this->line('');
        $this->line('  <fg=bright-magenta;options=bold> 🤖 TRANSLATION STATS</>');
        $this->line("    <fg=bright-white>Total Keys Targeted:</>          <fg=bright-yellow;options=bold>{$this->totalKeysToTranslate}</>");
        $this->line("    <fg=bright-white>Chunks Processed:</>             <fg=bright-yellow;options=bold>{$this->processedChunks} / {$this->totalChunks}</>");
        $this->line("    <fg=bright-white>Keys Successfully Translated:</>  <fg=bright-green;options=bold>{$this->totalKeysSuccessfullyProcessed}</>");
        $this->line("    <fg=bright-white>Keys Failed or Missing:</>       <fg=bright-red;options=bold>{$this->totalKeysFailed}</>");

        if ($this->totalKeysToTranslate > 0) {
            $successRate = round(($this->totalKeysSuccessfullyProcessed / $this->totalKeysToTranslate) * 100, 2);
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

        $this->line('');
        if ($this->shouldExit) {
            $this->line('<fg=bright-yellow;options=bold> ⚠️  Process was stopped by the user.</>');
        }
        $this->line('<fg=bright-green;options=bold> 🎉 All tasks completed!</>');
        $this->line('');
    }
}
