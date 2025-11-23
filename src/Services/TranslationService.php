<?php

namespace Jayesh\LaravelGeminiTranslator\Services;

use Exception;
use Gemini\Data\Content;
use Gemini\Laravel\Facades\Gemini;
use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;
use Jayesh\LaravelGeminiTranslator\Utils\TextHelper;
use JsonException;
use Spatie\Fork\Fork;
use Throwable;

class TranslationService
{
    /**
     * Run the translation process
     */
    public function runTranslationProcess(
        array $keysToTranslate,
        array $targetLanguages,
        array $sourceTextMap,
        array $options,
        callable $stopSignal,
        $output
    ): array {
        $translations = [];
        $totalKeysSuccessfullyProcessed = 0;
        $totalKeysFailed = 0;
        $failedKeys = [];


        // Calculate total chunks
        $totalChunks = $this->calculateTotalChunks($keysToTranslate, $options['chunk-size']);

        $driver = $options['driver'];
        $isForkMode = $driver === 'fork' && function_exists('pcntl_fork') && class_exists(Fork::class);

        if (!$isForkMode) {
            $output->writeln("Press the '<fg=bright-red;options=bold>{$options['stop-key']}</>' key at any time to gracefully stop the process.");
        } else {
            $output->writeln(" ⚠️  Fork mode: Translation cannot be stopped mid-process. Press Ctrl+C to terminate.");
        }

        $output->writeln(" 📊 Total keys needing translation: <fg=bright-yellow;options=bold>" . array_sum(array_map('count', $keysToTranslate)) . "</>");
        $output->writeln(" 📦 Total chunks to process: <fg=bright-yellow;options=bold>{$totalChunks}</>");

        $tasks = $this->buildTranslationTasks(
            $keysToTranslate,
            $targetLanguages,
            $sourceTextMap,
            $options
        );

        $processedChunks = 0;

        if ($isForkMode) {
            $concurrency = (int) ($options['concurrency'] ?? 15);
            $output->writeln("⚡ Using 'fork' driver for high-performance concurrency ({$concurrency} concurrent processes).");

            $totalKeys = array_sum(array_map('count', $keysToTranslate));
            $progressBar = $output->createProgressBar($totalKeys);
            $progressBar->setFormatDefinition('custom', '🚀 %current%/%max% [%bar%] %percent:3s%% -- %message% ⏱️  %elapsed:6s%');
            $progressBar->setFormat('custom');
            $progressBar->setMessage('Initializing parallel translation process...');
            $progressBar->start();

            $results = Fork::new()->concurrent($concurrency)->run(...$tasks);

            foreach ($results as $result) {
                $processedChunks++;
                $chunkCount = $result['chunk_keys_count'] ?? 0;

                if ($result['status'] === 'success') {
                    $this->mergeTranslations($translations, $result['data'], $options['skip-existing'] ?? false, $options['existing_translations'] ?? []);
                    $totalKeysSuccessfullyProcessed += $chunkCount;
                    $progressBar->setMessage("✅ Chunk {$processedChunks}/{$totalChunks} - SUCCESS ({$chunkCount} keys)");
                } else {
                    $totalKeysFailed += $chunkCount;
                    if (isset($result['failed_keys'], $result['filename'])) {
                        $failedKeys[$result['filename']] = array_merge(
                            $failedKeys[$result['filename']] ?? [],
                            $result['failed_keys']
                        );
                    }
                    $progressBar->setMessage(" ❌ Chunk {$processedChunks}/{$totalChunks} - FAILED ({$chunkCount} keys)");
                }
                $progressBar->advance($chunkCount);
            }
            $progressBar->finish();
            $output->newLine();
        } else {
            $output->writeln(" 🐌 Running in synchronous mode - this will be slower but more stable!");
            $output->newLine();

            foreach ($tasks as $task) {
                if ($stopSignal()) {
                    $output->writeln("\n 🛑 User requested to stop. Finishing up...");
                    break;
                }

                $processedChunks++;
                $output->write("  <fg=bright-yellow>-></> Processing chunk {$processedChunks}/{$totalChunks}... ");

                $result = $task();
                $chunkCount = $result['chunk_keys_count'] ?? 0;

                if ($result['status'] === 'success') {
                    $this->mergeTranslations($translations, $result['data'], $options['skip-existing'] ?? false, $options['existing_translations'] ?? []);
                    $totalKeysSuccessfullyProcessed += $chunkCount;
                    $output->writeln("<fg=green;options=bold>✓ Done</>");
                } else {
                    $output->writeln("<fg=red;options=bold>✗ Failed</>");
                    $output->writeln("     Error: " . $result['message']);
                    $totalKeysFailed += $chunkCount;
                    if (isset($result['failed_keys'], $result['filename'])) {
                        $failedKeys[$result['filename']] = array_merge(
                            $failedKeys[$result['filename']] ?? [],
                            $result['failed_keys']
                        );
                    }
                }
            }
        }

        return [
            'translations' => $translations,
            'success_count' => $totalKeysSuccessfullyProcessed,
            'fail_count' => $totalKeysFailed,
            'failed_keys' => $failedKeys,
            'processed_chunks' => $processedChunks
        ];
    }

    /**
     * Build translation tasks
     */
    private function buildTranslationTasks(array $structuredKeys, array $languages, array $sourceTextMap, array $options): array
    {
        $chunkSize = (int) $options['chunk-size'];
        $maxRetries = (int) ($options['max-retries'] ?? 5);
        $retryDelay = (int) ($options['retry-delay'] ?? 3);
        $projectContext = $options['context'] ?? null;
        $tasks = [];

        foreach ($structuredKeys as $contextualFileKey => $keys) {
            if (empty($keys))
                continue;

            [, $fileKey] = explode('::', $contextualFileKey, 2);
            $isJsonFile = str_ends_with($fileKey, '__JSON__');
            $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

            $fullKeysForAI = $isJsonFile ? $keys : array_map(fn($key) => $prefix . $key, $keys);

            $keyChunks = array_chunk($fullKeysForAI, $chunkSize);
            $originalKeyChunks = array_chunk($keys, $chunkSize);

            foreach ($keyChunks as $index => $chunk) {
                $originalChunk = $originalKeyChunks[$index];

                // capture only the subset of sourceTextMap needed for the current chunk
                $chunkSourceTextMap = [];
                foreach ($chunk as $fullKey) {
                    if (isset($sourceTextMap[$fullKey])) {
                        $chunkSourceTextMap[$fullKey] = $sourceTextMap[$fullKey];
                    }
                }

                $tasks[] = static function () use ($chunk, $originalChunk, $languages, $contextualFileKey, $maxRetries, $retryDelay, $projectContext, $chunkSourceTextMap) {
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
                            $chunkSourceTextMap
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

    /**
     * Merge translations
     */
    private function mergeTranslations(array &$translations, array $chunkTranslations, bool $skipExisting, array $existingTranslations): void
    {
        foreach ($chunkTranslations as $lang => $files) {
            foreach ($files as $filename => $data) {
                $currentTranslations = $translations[$lang][$filename] ?? [];

                // If skip-existing is enabled, we should also consider the initially loaded translations
                if ($skipExisting) {
                    // Get the originally existing translations for this file/language
                    $alreadyExisting = $existingTranslations[$lang][$filename] ?? [];

                    // Combine already existing + currently built translations
                    $allExisting = array_merge($alreadyExisting, $currentTranslations);

                    // Only add new translations for keys that don't exist anywhere
                    foreach ($data as $key => $value) {
                        if (!isset($allExisting[$key])) {
                            $currentTranslations[$key] = $value;
                        }
                    }
                } else {
                    // Normal merge - all new data overwrites existing (or adds to it)
                    $currentTranslations = array_merge($currentTranslations, $data);
                }

                $translations[$lang][$filename] = $currentTranslations;
            }
        }
    }

    /**
     * Static method to translate keys with Gemini
     */
    public static function staticTranslateKeysWithGemini(array $keys, array $languages, string $contextualFileKey, int $maxRetries, int $baseRetryDelay, ?string $projectContext = null): array
    {
        $langString = implode(', ', $languages);
        $keysString = '';
        foreach ($keys as $key) {
            $keysString .= "- `{$key}`\n";
        }

        [, $fileKey] = explode('::', $contextualFileKey, 2);
        $fileNameForPrompt = str_ends_with($fileKey, '__JSON__')
            ? 'a main JSON file (e.g., en.json)'
            : "'{$fileKey}.php'";
        $projectContextString = '';
        if (!empty($projectContext)) {
            $sanitizedContext = trim(str_replace(["\n", "\r"], ' ', $projectContext));
            $projectContextString = "- **Project-Specific Context**: Your translations should be tailored for the following context: {$sanitizedContext}\n";
        }

        // Static system instructions (rules, format, etc.) - no variables here!
        $systemPrompt = <<<SYSTEM
You are an expert Laravel translation generator. Your task is to generate high-quality, professional translations for a list of localization keys. Follow ALL rules below EXACTLY. These rules are strict and non-negotiable.

## 1. ROLE & CONSTRAINTS
- Goal: Produce accurate translations for the provided keys.
- Source File Context: These keys belong to the Laravel file provided in the query.
- Target Languages: Generate translations ONLY for the languages specified in the query.

## ⚠️ CRITICAL LANGUAGE REQUIREMENT
**You MUST generate translations for ONLY the exact languages specified in the query.**
- Do NOT include any other languages in your response.
- Do NOT mix languages from previous requests.
- Each key MUST have translations for ALL specified languages.
- Each key MUST have ONLY these languages - no more, no less.
- Verify language codes match EXACTLY those in the query.
- If a language code is not in the query's list, DO NOT include it.

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

## 3. OUTPUT FORMAT RULES (STRICT)
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
- Only include the exact target languages from the query.
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

## 4. IF A KEY IS UNKNOWN
If a key has no clear or conventional meaning:
- Translate literally.
- Do NOT guess hidden meaning.
- Do NOT output placeholders like "Needs translation".
- Do NOT output internal comments.

## 5. WORKED EXAMPLE (for instruction only)
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
    "uz": "Juda ko'p urinishlar bo'ldi. Iltimos, :seconds soniyadan so'ng qayta urinib ko'ring."
  },
  "Save Changes": {
    "en": "Save Changes",
    "ru": "Сохранить изменения",
    "uz": "O'zgarishlarni saqlash"
  },
  "I agree to the <strong>Terms of Service</strong>": {
    "en": "I agree to the Terms of Service",
    "ru": "Я согласен с Условиями обслуживания",
    "uz": "Men Xizmat ko'rsatish shartlariga roziman"
  }
}

## 6. FINAL RULE
Return ONLY the valid JSON object. No other text.
SYSTEM;

        // Dynamic user content (specifics for this request)
        $userPrompt = <<<USER
## TRANSLATION REQUEST

### Keys to Translate:
{$keysString}

### File Context:
- Source File: {$fileNameForPrompt}
{$projectContextString}

### Target Languages:
Generate translations for EXACTLY these languages: {$langString}

Remember: Include ONLY the languages listed above. Each key must have all specified languages, no more, no less.
USER;

        $modelToUse = config('gemini.model', 'gemini-2.5-flash-lite');
        $lastError = null;
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Gemini::generativeModel(model: $modelToUse)
                    ->withSystemInstruction(Content::parse($systemPrompt))  // Static rules
                    ->generateContent(Content::parse($userPrompt));         // Dynamic query

                $responseText = $response->text();

                // More robust JSON extraction to handle responses with leading text or missing code blocks
                $cleanedResponseText = '';

                // First try to find JSON within ```json``` code blocks
                if (preg_match('/```json\s*([\s\S]*?)\s*```/', $responseText, $matches)) {
                    $cleanedResponseText = $matches[1];
                }
                // If no code block found, try to extract JSON from anywhere in the response
                else {
                    // Find the first { and match to its corresponding }
                    if (preg_match('/\{(?:[^{}]|(?R))*\}/', $responseText, $matches)) {
                        $cleanedResponseText = $matches[0];
                    } else {
                        // Last resort: assume the whole text is JSON-like
                        $cleanedResponseText = $responseText;
                    }
                }

                $decoded = json_decode(trim($cleanedResponseText), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded))
                    return $decoded;
            } catch (Throwable $e) {
                $lastError = $e->getMessage(); // Track the last error

                // Check if this is a JSON parsing error
                $isJsonError = $e instanceof JsonException;

                if (str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), 'exceeded')) {
                    // API quota/rate limit errors - always retry with exponential backoff
                    if ($attempt < $maxRetries) {
                        $delay = ($baseRetryDelay * pow(2, $attempt) + mt_rand(500, 1500) / 1000);
                        usleep($delay * 1000000);
                        continue;
                    }
                } else if ($isJsonError) {
                    // JSON parsing errors - still worth retrying as the model might return properly formatted JSON on retry
                    if ($attempt < $maxRetries) {
                        $delay = ($baseRetryDelay * $attempt + mt_rand(500, 2000) / 1000);
                        usleep($delay * 1000000);
                        continue;
                    }
                } else {
                    // Other errors (network, etc.) - retry with linear backoff
                    if ($attempt < $maxRetries) {
                        $delay = ($baseRetryDelay * $attempt + mt_rand(500, 2000) / 1000);
                        usleep($delay * 1000000);
                        continue;
                    }
                }

                // If we're on the last attempt, throw the error
                throw $e;
            }
        }
        throw new Exception(
            "Failed to translate keys after {$maxRetries} attempts. " .
            "File: {$fileKey}, Keys: " . implode(', ', array_slice($keys, 0, 5)) . "... " .
            "Last error: " . ($lastError ?? 'unknown')
        );
    }

    /**
     * Structure translations from Gemini response
     */
    public static function staticStructureTranslationsFromGemini(
        array $geminiData,
        array $originalKeys,
        string $contextualFileKey,
        array $languages,
        array $sourceTextMap
    ): array {
        $chunkTranslations = [];
        [, $fileKey] = explode('::', $contextualFileKey, 2);
        $isJsonFile = str_ends_with($fileKey, '__JSON__');
        $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

        foreach ($originalKeys as $originalKey) {
            $keyToLookup = $isJsonFile ? $originalKey : $prefix . $originalKey;
            $keyTranslations = $geminiData[$keyToLookup] ?? null;

            foreach ($languages as $lang) {
                // no placeholders; always yield clean text
                if (is_array($keyTranslations) && isset($keyTranslations[$lang]) && is_string($keyTranslations[$lang])) {
                    $text = $keyTranslations[$lang];

                    // If the translation is just the key itself (meaning no translation occurred), use fallback
                    if ($text === $keyToLookup) {
                        $text = null;
                    }
                } elseif (is_string($keyTranslations) && count($languages) === 1) {
                    // single-language run: Gemini returned a raw string
                    $text = $keyTranslations;

                    // If the translation is just the key itself (meaning no translation occurred), use fallback
                    if ($text === $keyToLookup) {
                        $text = null;
                    }
                } else {
                    $text = null;
                }

                // If we still don't have a valid translation, use fallback
                if ($text === null) {
                    // Check if the source text looks like a pluralization string (contains | and {} patterns)
                    $sourceTextForPluralCheck = $sourceTextMap[$keyToLookup] ?? null;
                    if ($sourceTextForPluralCheck !== null && TextHelper::isPluralizationString($sourceTextForPluralCheck)) {
                        // For pluralization strings, extract the actual text parts and translate them
                        $text = TextHelper::translatePluralizationString($sourceTextForPluralCheck, $lang);
                    } else {
                        // fallback to known source text, or humanize the key if it looks like a machine key
                        $fallbackText = $sourceTextMap[$keyToLookup] ?? null;
                        if ($fallbackText === null) {
                            // If it looks like a machine key, extract display text and humanize it instead of using the key directly
                            if (TextHelper::looksMachineKey($keyToLookup)) {
                                $displayText = TextHelper::extractDisplayTextFromNamespacedKey($keyToLookup);
                                if ($lang !== 'en') {
                                    $fallbackText = LocaleHelper::humanizeForLang($displayText, $lang);
                                } else {
                                    $fallbackText = $keyToLookup;
                                }
                            } else {
                                $fallbackText = $keyToLookup;
                            }
                        }
                        $text = $fallbackText;
                    }
                }

                // Check for placeholder mismatches and fallback to source if needed
                $sourceText = $sourceTextMap[$keyToLookup] ?? null;
                if ($sourceText === null && TextHelper::looksMachineKey($keyToLookup)) {
                    $displayText = TextHelper::extractDisplayTextFromNamespacedKey($keyToLookup);
                    if (empty($sourceText)) {
                        $sourceText = LocaleHelper::humanizeForLang($displayText, 'en');
                    }
                } elseif ($sourceText === null) {
                    $sourceText = $keyToLookup;
                }

                if ($sourceText !== $text && !is_null($sourceText) && TextHelper::hasPlaceholderMismatch($sourceText, $text)) {
                    // Placeholder mismatch detected, fallback to source text
                    $text = $sourceText;
                }

                $chunkTranslations[$lang][$contextualFileKey][$originalKey] = $text;
            }
        }
        return $chunkTranslations;
    }

    /**
     * Calculate total chunks
     */
    public function calculateTotalChunks(array $keysToTranslate, int $chunkSize): int
    {
        $total = 0;
        foreach ($keysToTranslate as $keys) {
            if (!empty($keys)) {
                $total += count(array_chunk($keys, $chunkSize));
            }
        }
        return $total;
    }

    /**
     * Perform cross-check and report
     */
    public function performCrossCheckAndReport(array $structuredKeys, array $existingTranslations, array $languages, array $scanTargets, $output = null): void
    {
        $missingStats = [];
        foreach ($structuredKeys as $filename => $keys) {
            foreach ($keys as $key) {
                foreach ($languages as $lang) {
                    if (!isset($existingTranslations[$lang][$filename][$key])) {
                        $missingStats[$filename][$lang][] = $key;
                    }
                }
            }
        }

        if (empty($missingStats)) {
            if ($output) {
                $output->writeln("<fg=bright-green;options=bold> ✅ All selected keys are fully translated and synchronized across all target languages!</>");
            }
            return;
        }

        if ($output) {
            $output->writeln("<fg=yellow>Found missing translations needing synchronization:</>");
            foreach ($missingStats as $contextualFileKey => $langData) {
                [$targetKey, $fileKey] = explode('::', $contextualFileKey, 2);
                $targetName = $scanTargets[$targetKey]['name'] ?? $targetKey;

                $fileNameDisplay = str_ends_with($fileKey, '__JSON__')
                    ? "JSON File (" . str_replace('__JSON__', '*.json', $fileKey) . ")"
                    : "{$fileKey}.php";

                if ($output) {
                    $output->writeln("  <fg=bright-yellow;options=bold>File: {$targetName} -> {$fileNameDisplay}</>");
                }

                foreach ($langData as $lang => $keys) {
                    $count = count($keys);
                    if ($output) {
                        $output->writeln("    <fg=bright-white>-> Language '<fg=bright-cyan>{$lang}</>' is missing <fg=bright-red;options=bold>{$count}</> keys.</>");
                    }
                }
            }

            if ($output) {
                $output->writeln("");
            }
        }
    }

    /**
     * Filter out existing keys
     */
    public function filterOutExistingKeys(array $keysForProcessing, array $existingTranslations, array $targetLanguages): array
    {
        $filteredKeys = [];

        foreach ($keysForProcessing as $fileKey => $keys) {
            $filteredKeys[$fileKey] = [];

            foreach ($keys as $key) {
                $shouldInclude = false;

                // Check if the key exists in any of the target languages
                foreach ($targetLanguages as $lang) {
                    if (!isset($existingTranslations[$lang][$fileKey][$key])) {
                        $shouldInclude = true;
                        break;
                    }
                }

                if ($shouldInclude) {
                    $filteredKeys[$fileKey][] = $key;
                }
            }

            // If no keys remain for this file, remove the file entry
            if (empty($filteredKeys[$fileKey])) {
                unset($filteredKeys[$fileKey]);
            }
        }

        foreach ($filteredKeys as &$k) {
            $k = array_values(array_unique($k));
        }

        return $filteredKeys;
    }

    /**
     * Filter for refresh only
     */
    public function filterForRefreshOnly(array $keysForProcessing, array $existingTranslations, array $targetLanguages): array
    {
        $filteredKeys = [];

        foreach ($keysForProcessing as $fileKey => $keys) {
            $filteredKeys[$fileKey] = [];

            foreach ($keys as $key) {
                // A key is "existing" if it exists in at least one language file
                $existsInAnyLang = false;

                foreach ($targetLanguages as $lang) {
                    if (isset($existingTranslations[$lang][$fileKey][$key])) {
                        $existsInAnyLang = true;
                        break;
                    }
                }

                if ($existsInAnyLang) {
                    $filteredKeys[$fileKey][] = $key;
                }
            }

            // If no keys remain for this file, remove the file entry
            if (empty($filteredKeys[$fileKey])) {
                unset($filteredKeys[$fileKey]);
            }
        }

        // Clean up array keys
        foreach ($filteredKeys as &$k) {
            $k = array_values(array_unique($k));
        }

        return $filteredKeys;
    }
}
