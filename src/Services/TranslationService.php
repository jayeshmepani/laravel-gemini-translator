<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Services;

use Exception;
use Gemini\Data\Content;
use Gemini\Laravel\Facades\Gemini;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Jayesh\LaravelGeminiTranslator\Gemini\FreeTierQuota;
use Jayesh\LaravelGeminiTranslator\Gemini\FreeTierQuotaCatalog;
use Jayesh\LaravelGeminiTranslator\Platform\PlatformFactory;
use Jayesh\LaravelGeminiTranslator\Tasks\TranslationChunkHandler;
use Jayesh\LaravelGeminiTranslator\Utils\LocaleHelper;
use Jayesh\LaravelGeminiTranslator\Utils\TextHelper;
use JsonException;
use Throwable;

class TranslationService
{
    public const string DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    /** @var array<string, string>|null */
    private static $frameworkEnglish;

    public function __construct(
        private readonly PlatformFactory $platformFactory,
        private readonly FreeTierQuotaCatalog $quotaCatalog,
    ) {}

    /** Run the translation process */
    public function runTranslationProcess(
        array $keysToTranslate,
        array $targetLanguages,
        array $sourceTextMap,
        array $options,
        callable $stopSignal,
        $output,
    ): array {
        $translations = [];
        $totalKeysSuccessfullyProcessed = 0;
        $totalKeysFailed = 0;
        $failedKeys = [];

        // Calculate total chunks
        $totalChunks = $this->calculateTotalChunks($keysToTranslate, (int) $options['chunk-size']);

        $runner = $this->platformFactory->taskRunner((string) ($options['driver'] ?? 'default'));

        if ($runner->supportsCooperativeStop()) {
            $output->writeln("Press the '<fg=bright-red;options=bold>{$options['stop-key']}</>' key at any time to gracefully stop the process.");
        } else {
            $output->writeln(' ⚠️  Parallel mode: Translation cannot be stopped mid-process. Press Ctrl+C to terminate.');
        }

        $output->writeln(' 📊 Total keys needing translation: <fg=bright-yellow;options=bold>' . array_sum(array_map(count(...), $keysToTranslate)) . '</>');
        $output->writeln(" 📦 Total chunks to process: <fg=bright-yellow;options=bold>{$totalChunks}</>");

        if (($options['refresh_clean'] ?? false) === true) {
            $sourceTextMap = $this->rebuildSourceMapForRefresh($keysToTranslate);
            $output->writeln(' 🔄 Clean refresh: re-translating from keys only. Existing lang-file wording is ignored so stale or faulty values cannot leak into Gemini.');
        } else {
            $sourceTextMap = $this->replaceEmptySourceWithKeyDerived($keysToTranslate, $sourceTextMap);
        }

        $payloads = $this->buildTranslationPayloads(
            $keysToTranslate,
            $targetLanguages,
            $sourceTextMap,
            $options,
        );

        $processedChunks = 0;
        $requestedConcurrency = max(1, (int) ($options['concurrency'] ?? 15));
        $concurrencyExplicit = (bool) ($options['concurrency_explicit'] ?? false);
        $model = self::resolveModel(isset($options['model']) && is_string($options['model']) ? $options['model'] : null);
        config(['gemini.model' => $model]);
        $quota = $this->quotaCatalog->find($model);
        $concurrency = $this->quotaCatalog->applyCaps() && $quota instanceof FreeTierQuota
            ? $quota->effectiveConcurrency($requestedConcurrency, $concurrencyExplicit)
            : $requestedConcurrency;

        if ($quota instanceof FreeTierQuota) {
            $asOf = $quota->asOf !== '' ? " · snapshot {$quota->asOf}" : '';
            $output->writeln(" 🤖 Model: <fg=bright-cyan>{$quota->model}</> · {$quota->tier}: {$quota->formatRpm()} RPM / {$quota->formatRpd()} RPD{$asOf}");

            if ($quota->hasNoRequestBudget()) {
                $output->writeln(' ⚠️  Recorded free-tier budget is 0 RPM and/or 0 RPD. Google may have retired this model. Update config/gemini-translator.php or switch models.');
            }

            if ($quota->exceedsDailyBudget($totalChunks)) {
                $output->writeln(" ⚠️  This run needs {$totalChunks} API requests; recorded free-tier RPD for {$quota->model} is {$quota->formatRpd()}.");
            }

            if ($runner->isParallel() && $this->quotaCatalog->applyCaps() && $concurrency < $requestedConcurrency) {
                $output->writeln(" ⚠️  Capped concurrency from {$requestedConcurrency} to {$concurrency} to stay within recorded {$quota->formatRpm()} RPM. Pass --concurrency explicitly or edit the snapshot to override.");
            } elseif ($runner->isParallel() && $quota->exceedsRpm($concurrency)) {
                $output->writeln(" ⚠️  Concurrency {$concurrency} is above recorded {$quota->formatRpm()} RPM for {$quota->model}. Expect 429s unless this key is billed or the snapshot is stale.");
            }
        } else {
            $output->writeln(' 🤖 Model: <fg=bright-cyan>' . FreeTierQuotaCatalog::normalize($model) . '</> · no quota snapshot (add the model to config/gemini-translator.php when Google publishes limits)');
        }

        if ($runner->isParallel()) {
            $output->writeln("⚡ Using '{$runner->name()}' driver for high-performance concurrency ({$concurrency} concurrent processes).");

            $totalKeys = array_sum(array_map(count(...), $keysToTranslate));
            $progressBar = $output->createProgressBar($totalKeys);
            $progressBar->setFormatDefinition('custom', '🚀 %current%/%max% [%bar%] %percent:3s%% -- %message% ⏱️  %elapsed:6s%');
            $progressBar->setFormat('custom');
            $progressBar->setMessage('Initializing parallel translation process...');
            $progressBar->start();

            $results = $runner->run($payloads, TranslationChunkHandler::class, $concurrency, $stopSignal);

            foreach ($results as $result) {
                $processedChunks++;
                $chunkCount = $result['chunk_keys_count'] ?? 0;

                if (($result['status'] ?? '') === 'success') {
                    $this->mergeTranslations($translations, $result['data'] ?? [], $options['skip-existing'] ?? false, $options['existing_translations'] ?? []);
                    $totalKeysSuccessfullyProcessed += $chunkCount;
                    $progressBar->setMessage("✅ Chunk {$processedChunks}/{$totalChunks} - SUCCESS ({$chunkCount} keys)");
                } else {
                    $totalKeysFailed += $chunkCount;
                    if (isset($result['failed_keys'], $result['filename'])) {
                        $failedKeys[$result['filename']] = array_merge(
                            $failedKeys[$result['filename']] ?? [],
                            $result['failed_keys'],
                        );
                    }

                    $progressBar->setMessage(" ❌ Chunk {$processedChunks}/{$totalChunks} - FAILED ({$chunkCount} keys)");
                }

                $progressBar->advance($chunkCount);
            }

            $progressBar->finish();
            $output->newLine();
        } else {
            $output->writeln(' 🐌 Running in synchronous mode - this will be slower but more stable!');
            $output->newLine();

            $stopped = false;
            $results = $runner->run(
                $payloads,
                TranslationChunkHandler::class,
                1,
                function () use ($stopSignal, &$stopped): bool {
                    if ($stopSignal()) {
                        $stopped = true;

                        return true;
                    }

                    return false;
                },
            );

            if ($stopped) {
                $output->writeln("\n 🛑 User requested to stop. Finishing up...");
            }

            foreach ($results as $result) {
                $processedChunks++;
                $output->write("  <fg=bright-yellow>-></> Processing chunk {$processedChunks}/{$totalChunks}... ");
                $chunkCount = $result['chunk_keys_count'] ?? 0;

                if (($result['status'] ?? '') === 'success') {
                    $this->mergeTranslations($translations, $result['data'] ?? [], $options['skip-existing'] ?? false, $options['existing_translations'] ?? []);
                    $totalKeysSuccessfullyProcessed += $chunkCount;
                    $output->writeln('<fg=green;options=bold>✓ Done</>');
                } else {
                    $output->writeln('<fg=red;options=bold>✗ Failed</>');
                    $output->writeln('     Error: ' . ($result['message'] ?? 'unknown'));
                    $totalKeysFailed += $chunkCount;
                    if (isset($result['failed_keys'], $result['filename'])) {
                        $failedKeys[$result['filename']] = array_merge(
                            $failedKeys[$result['filename']] ?? [],
                            $result['failed_keys'],
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
            'processed_chunks' => $processedChunks,
        ];
    }

    /** Static method to translate keys with Gemini */
    public static function staticTranslateKeysWithGemini(array $keys, array $languages, string $contextualFileKey, int $maxRetries, int $baseRetryDelay, ?string $projectContext = null, array $sourceTextMap = []): array
    {
        // Filter out empty or whitespace-only keys to prevent syntax errors
        $keys = array_filter($keys, fn($key) => is_string($key) && trim($key) !== '');

        // Re-index array after filtering
        $keys = array_values($keys);

        // If all keys were filtered out, return empty array
        if ($keys === []) {
            return [];
        }

        $langString = implode(', ', $languages);
        $scriptRequirements = LocaleHelper::scriptRequirementsForPrompt($languages);
        $keysString = '';
        foreach ($keys as $key) {
            $source = $sourceTextMap[$key] ?? null;
            if (is_string($source) && trim($source) === '') {
                $source = null;
            }

            $keysString .= self::describeKeyForPrompt($key, $source);
        }

        [, $fileKey] = explode('::', $contextualFileKey, 2);
        $fileNameForPrompt = str_ends_with($fileKey, '__JSON__')
            ? 'a main JSON file (e.g., en.json)'
            : "'{$fileKey}.php'";
        $projectContextString = '';
        if ($projectContext !== null && $projectContext !== '') {
            $sanitizedContext = trim(str_replace(["\n", "\r"], ' ', $projectContext));
            $projectContextString = "- **Project-Specific Context**: Your translations should be tailored for the following context: {$sanitizedContext}" . PHP_EOL;
        }

        // Static system instructions (rules, format, etc.) - no variables here!
        $systemPrompt = <<<'SYSTEM_WRAP'
        You are an expert Laravel translation generator. Your task is to generate high-quality, professional translations for a list of localization keys. Follow ALL rules below EXACTLY. These rules are strict and non-negotiable.
        
        ## 1. ROLE & CONSTRAINTS
        - Goal: Produce accurate translations for the provided keys.
        - Source File Context: These keys belong to the Laravel file provided in the query.
        - Target Languages: Generate translations ONLY for the languages specified in the query.
        - Each item may include optional source text. When source text is present, that text is the meaning you must translate. Do not replace it with a guess derived only from the key name.
        
        ## ⚠️ CRITICAL LANGUAGE REQUIREMENT
        **You MUST generate translations for ONLY the exact languages specified in the query.**
        - Do NOT include any other languages in your response.
        - Do NOT mix languages from previous requests.
        - Each key MUST have translations for ALL specified languages.
        - Each key MUST have ONLY these languages - no more, no less.
        - Verify language codes match EXACTLY those in the query.
        - If a language code is not in the query's list, DO NOT include it.
        - Do NOT mix scripts inside a single language value: `en` must be English (Latin script). `hi` must be Hindi (Devanagari). Never put Hindi words in an `en` value. Never put a full English sentence in `hi` except untranslatable proper nouns.
        - Every language code has exactly one native writing system. Neighboring Indic scripts look similar but are different Unicode blocks — mixing them is invalid. `gu` is Gujarati (`પો`, `ફીલ્ડ`), never Devanagari (`नवी`, `फ़ील्ड`) and never Kannada/Telugu matras (`ೋ`, `ో`). `kn` is Kannada. `te` is Telugu. `ta` is Tamil. `ml` is Malayalam. `bn` is Bengali. `pa` is Gurmukhi. `mr`/`ne` are Devanagari like `hi`.
        
        ## 2. KEY INTERPRETATION LOGIC (EXTREMELY IMPORTANT)
        You will receive a list of keys. Each key is one of these types:
        
        A) Namespaced Laravel Keys (e.g., auth.failed, validation.required)
        - These follow file.subkey patterns.
        - Interpret meaning using Laravel's convention.
        - If it is a standard Laravel key (`auth.*`, `pagination.*`, `passwords.*`, `validation.*`):
          - Use the official standard phrasing (no creative rewrites).
          - Do NOT shorten official English (do not drop the word "field" from validation messages).
        - If it is a custom namespaced key:
          - If source text is provided, translate that source.
          - If no source text is provided, provide a clear, natural, human-readable translation from the last segment only.
          - Do NOT invent slogans, greetings, or extra clauses that are not in the source (e.g. do not turn a title key into "Welcome to our platform").
        
        B) Literal UI Text (e.g., "Profile", "Save Changes", "An unknown error occurred.", "By :author")
        - Translate the literal displayed text (or the provided source text).
        - Do not change wording, tone, casing, punctuation, or capitalization unless required for grammar.
        
        C) Laravel container keys (e.g., validation.attributes, validation.custom, validation.values)
        - These name nested arrays, not user-facing sentences.
        - If no source sentence is provided, set EVERY requested language to an empty string.
        
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
        - Keep placeholders like :attribute, :seconds, :count, :author, :name, :max, :min, :other, :value, :values, :date, :format, :digits.
        - Copy each placeholder token EXACTLY. `:author` stays `:author` — never rename it to `:name`.
        - Do NOT translate placeholder names.
        - Do NOT add new placeholders.
        - Do NOT remove existing placeholders.
        - If source text contains placeholders, the translation MUST contain the same set of placeholder names.
        
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
        
        I) LARAVEL PLURALIZATION STRINGS
        Laravel plural strings use `{0}`, `{1}`, `[2,*]`, `[2,10]`, `[21,Inf]` and `|`.
        - Keep the SAME number of segments, the SAME range tokens, and the SAME `|` separators.
        - Translate only the human words inside each segment.
        - Do NOT collapse `[2,10]|[11,20]|[21,Inf]` into `[2,*]`.
        - Do NOT drop spaces that are part of the official syntax.
        - A key whose last segment is `plural_test` or similar is NOT a plural string unless the source text actually contains `|` and `{` / `[`.
        
        J) HTML ENTITIES AND EXISTING MARKUP
        - Preserve `&laquo;`, `&raquo;`, `&amp;`, `&nbsp;` and similar entities exactly.
        - Do not turn `&raquo;` into `»` or into the word "next".
        
        K) DO NOT INVENT PLACEHOLDERS
        - Add a `:token` only if it already appears in the source text OR is implied by a `by_<token>` / `at_<token>` last segment.
        - `messages.user.welcome` without source is "Welcome" — do NOT invent `:name`.
        - `messages.user.by_author` without source is "By :author" — never `:name`.
        - `categories.posts_count` without source is "Posts count" — do NOT invent `:count` or `{0}|{1}|[2,*]` pipes.
        
        L) EVERY LANGUAGE MUST ACTUALLY BE TRANSLATED
        - If the target is not `en`, do not copy the English sentence as the translation (except proper nouns and placeholders).
        - `hi` must use Devanagari for Hindi words. Do not insert Urdu/Arabic letters (e.g. `سے`, `ایک`, `بایٹ`) into a Hindi value.
        - `gu` must use Gujarati letters for Gujarati words. WRONG: Hindi `नवीनतम પોસ્ટ્સ`, Hindi `फ़ील्ड` instead of `ફીલ્ડ`, Kannada `પોસ્ટ` (U+0CCB `ೋ`) instead of Gujarati `પોસ્ટ` (U+0ABE `ો`).
        - `ru` uses Cyrillic. `uz` uses Latin (Uzbek). `ja` uses Japanese scripts. `ar` uses Arabic script. Never put the wrong script in the wrong language code.
        
        M) KEY SHAPE BEATS A CORRUPT EXISTING STRING
        - If a previous file already contains a wrong placeholder (e.g. `by_author` stored as "By :name"), IGNORE that mistake.
        - The key `*.by_<token>` always means English `By :<token>`.
        - A `*_count` / `*_test` key is a short label unless source text already contains `|` and `{` / `[`.
        - Refreshing existing files must not copy forward invented slogans or invented `:name` tokens.
        
        N) ONE WRITING SYSTEM PER LANGUAGE CODE
        - Copy Latin placeholders (`:attribute`, `:date`) and tech tokens (JSON, URL, UUID, PHP) as-is. Translate every other word into the locale's own script.
        - Indic lookalikes that must never be swapped: Devanagari `ो` / Gujarati `ો` / Kannada `ೋ` / Telugu `ో`; Devanagari `फी` vs Gujarati `ફી`; Devanagari `न` vs Gujarati `ન`.
        - `th` Thai, `el` Greek, `hy` Armenian, `ka` Georgian, `am` Ethiopic, `he` Hebrew, `ko` Hangul, `zh` Han — same rule: do not borrow letters from a neighbor.
        
        ## 4. IF A KEY IS UNKNOWN
        If a key has no clear or conventional meaning:
        - Translate literally.
        - Prefer the provided source text over guessing.
        - Do NOT guess hidden meaning.
        - Do NOT output placeholders like "Needs translation".
        - Do NOT output internal comments.
        - Do NOT output the raw key as a fake translation when source text exists.
        
        ## 5. WORKED EXAMPLE (for instruction only)
        This example demonstrates the required structure and formatting. This example must NOT appear in your actual output.
        
        Example input:
        auth.throttle
        validation.required
        validation.attributes
        messages.user.by_author   (no source)
        messages.user.welcome     (no source)
        messages.goodbye          (no source)
        messages.plural_test      (source: "This is a plural test key.")
        categories.posts_count    (no source)
        Welcome Page Title
        By :author
        web::messages.frontend.hero_title   (no source)
        I agree to the <strong>Terms of Service</strong>
        {0} No items|{1} One item|[2,10] Few items
        
        Example correct output structure:
        {
          "auth.throttle": {
            "en": "Too many login attempts. Please try again in :seconds seconds.",
            "hi": "बहुत अधिक लॉगिन प्रयास। कृपया :seconds सेकंड में पुनः प्रयास करें।"
          },
          "validation.required": {
            "en": "The :attribute field is required.",
            "hi": ":attribute फ़ील्ड आवश्यक है।"
          },
          "validation.attributes": {
            "en": "",
            "hi": ""
          },
          "messages.user.by_author": {
            "en": "By :author",
            "hi": ":author द्वारा"
          },
          "messages.user.welcome": {
            "en": "Welcome",
            "hi": "स्वागत है"
          },
          "messages.goodbye": {
            "en": "Goodbye",
            "hi": "अलविदा"
          },
          "messages.plural_test": {
            "en": "This is a plural test key.",
            "hi": "यह एक बहुवचन परीक्षण कुंजी है।"
          },
          "categories.posts_count": {
            "en": "Posts count",
            "hi": "पोस्ट संख्या"
          },
          "Welcome Page Title": {
            "en": "Welcome Page Title",
            "hi": "स्वागत पृष्ठ शीर्षक"
          },
          "By :author": {
            "en": "By :author",
            "hi": ":author द्वारा"
          },
          "web::messages.frontend.hero_title": {
            "en": "Hero title",
            "hi": "हीरो शीर्षक"
          },
          "I agree to the <strong>Terms of Service</strong>": {
            "en": "I agree to the Terms of Service",
            "hi": "मैं सेवा की शर्तों से सहमत हूँ"
          },
          "{0} No items|{1} One item|[2,10] Few items": {
            "en": "{0} No items|{1} One item|[2,10] Few items",
            "hi": "{0} कोई आइटम नहीं|{1} एक आइटम|[2,10] कुछ आइटम"
          }
        }
        
        WRONG (never do this):
        - messages.user.by_author / en = "By :name"          (renamed placeholder)
        - messages.user.welcome / en = "Welcome, :name!"     (invented placeholder)
        - messages.goodbye / en = "Goodbye! See you soon."   (invented extra clause)
        - messages.plural_test / en = "{0} ...|[2,*] ..."    (invented plural syntax; source had none)
        - messages.plural_test / hi = English copy           (left untranslated)
        - validation.attributes / en = "विशेषताएं"            (Hindi inside en; should be "")
        - hero_title / en = "Welcome to our platform"        (invented slogan)
        - feature_1 / en = "Fast and Reliable"               (invented marketing)
        - posts_count / en = "{0} :count posts|..."          (invented :count and pipes)
        - validation.required / en = "The :attribute must..." (dropped official word "field")
        - validation.max / hi uses Urdu `سے` instead of Hindi `से`
        
        ## 6. MORE LANGUAGES AND EDGE CASES (for instruction only — do not echo)
        Same rules apply no matter which codes the user listed. Only emit the codes that were requested.
        
        pagination.next
          en: "Next &raquo;"
          hi: "अगला &raquo;"
          ru: "Вперёд &raquo;"
          uz: "Keyingi &raquo;"
          (entity &raquo; is copied, never turned into » or omitted)
        
        passwords.throttled
          en: "Please wait before retrying."
          ja: "再試行する前にお待ちください。"
          ar: "يرجى الانتظار قبل إعادة المحاولة."
        
        Joined :date
          en: "Joined :date"
          hi: ":date को शामिल हुए"
          ru: "Присоединился :date"
          (placeholder stays :date in every language)
        
        {0} No comments|{1} :count comment|[2,*] :count comments
          en: same ranges
          hi: "{0} कोई टिप्पणी नहीं|{1} :count टिप्पणी|[2,*] :count टिप्पणियाँ"
          ru: "{0} Нет комментариев|{1} :count комментарий|[2,*] :count комментариев"
          (three segments, :count kept, [2,*] kept)
        
        {0} No items|{1} One item|[2,10] Few items|[11,20] Several items|[21,Inf] Many items
          Keep FIVE segments and the exact tokens {0}, {1}, [2,10], [11,20], [21,Inf].
          WRONG: collapsing everything after one into [2,*].
        
        auth.failed
          en: "These credentials do not match our records."
          hi: "ये प्रमाण हमारे रिकॉर्ड से मेल नहीं खाते।"   (से is Devanagari, never Urdu سے)
        
        Latest Posts
          en: "Latest Posts"
          gu: "નવીનતમ પોસ્ટ્સ"   (all Gujarati)
          WRONG gu: "नवीनतम પોસ્ટ્સ"   (Devanagari न + Gujarati)
          WRONG gu: "પોસ્ટ્સ"           (Kannada vowel ೋ on Gujarati પ)
        
        validation.required
          gu: ":attribute ફીલ્ડ આવશ્યક છે."   (Gujarati ફીલ્ડ / આવશ્યક)
          WRONG gu: ":attribute फ़ील्ड आवश्यक છે."   (Hindi फ़ील्ड)
        
        web::messages.frontend.features.feature_1  (no source)
          en: "Feature 1"
          hi: "फ़ीचर 1"
          WRONG: "Fast and Reliable"
        
        ## 7. FINAL RULE
        Return ONLY the valid JSON object. No other text.
        SYSTEM_WRAP;

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

### Writing system for each requested language:
{$scriptRequirements}

Remember: Include ONLY the languages listed above. Each key must have all specified languages, no more, no less. When source text is provided, translate that source. Keep every :placeholder name identical. Keep Laravel plural tokens (`{0}`, `[2,10]`, `|`) identical. Do not mix scripts across language values. Do not invent placeholders, slogans, or plural pipes that are not in the source or in a by_<token> key segment. Use only the writing system listed above for each language code.
USER;

        $modelToUse = self::resolveModel();
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
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (Throwable $e) {
                // Track the last error with more details for debugging
                $lastError = $e->getMessage();

                // Log the raw response for debugging if available
                if (isset($responseText) && $attempt === $maxRetries) {
                    // Only log on last attempt to avoid spam
                    // Log up to 10000 chars to ensure we see the invalid JSON
                    $truncatedResponse = strlen($responseText) > 10000
                        ? substr($responseText, 0, 10000) . '... [truncated]'
                        : $responseText;
                    Log::error(sprintf('Gemini API Response Error for file %s: %s', $contextualFileKey, $lastError));
                    Log::error('Raw response (truncated): ' . $truncatedResponse);
                }

                // Check if this is a JSON parsing error
                $isJsonError = $e instanceof JsonException;

                if (str_contains($e->getMessage(), 'quota') || str_contains($e->getMessage(), 'rate limit') || str_contains($e->getMessage(), 'exceeded')) {
                    // API quota/rate limit errors - always retry with exponential backoff
                    if ($attempt < $maxRetries) {
                        $delay = (int) (($baseRetryDelay * 2 ** $attempt + mt_rand(500, 1500) / 1000) * 1000000);
                        Sleep::usleep($delay);

                        continue;
                    }
                } elseif ($isJsonError) {
                    // JSON parsing errors - still worth retrying as the model might return properly formatted JSON on retry
                    if ($attempt < $maxRetries) {
                        $delay = (int) (($baseRetryDelay * $attempt + mt_rand(500, 2000) / 1000) * 1000000);
                        Sleep::usleep($delay);

                        continue;
                    }
                } else {
                    // Other errors (network, etc.) - retry with linear backoff
                    if ($attempt < $maxRetries) {
                        $delay = (int) (($baseRetryDelay * $attempt + mt_rand(500, 2000) / 1000) * 1000000);
                        Sleep::usleep($delay);

                        continue;
                    }
                }

                // If we're on the last attempt, throw the error
                throw $e;
            }
        }

        throw new Exception(
            sprintf('Failed to translate keys after %d attempts. ', $maxRetries)
            . sprintf('File: %s, Keys: ', $fileKey) . implode(', ', array_slice($keys, 0, 5)) . '... '
            . 'Last error: ' . ($lastError ?? 'unknown'),
        );
    }

    /** Structure translations from Gemini response */
    public static function staticStructureTranslationsFromGemini(
        array $geminiData,
        array $originalKeys,
        string $contextualFileKey,
        array $languages,
        array $sourceTextMap,
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
                    $text = trim($keyTranslations[$lang]);

                    // If the translation is just the key itself (meaning no translation occurred), use fallback
                    if ($text === '' || $text === $keyToLookup || self::translationRejected($text, $lang, $sourceTextMap[$keyToLookup] ?? null, $keyToLookup)) {
                        $text = null;
                    }
                } elseif (is_string($keyTranslations) && count($languages) === 1) {
                    // single-language run: Gemini returned a raw string
                    $text = trim($keyTranslations);

                    // If the translation is just the key itself (meaning no translation occurred), use fallback
                    if ($text === '' || $text === $keyToLookup || self::translationRejected($text, $lang, $sourceTextMap[$keyToLookup] ?? null, $keyToLookup)) {
                        $text = null;
                    }
                } else {
                    $text = null;
                }

                // If we still don't have a valid translation, use fallback
                if ($text === null) {
                    $implied = self::impliedEnglishForKey($keyToLookup);
                    $mappedSource = $sourceTextMap[$keyToLookup] ?? null;
                    if (is_string($mappedSource) && trim($mappedSource) === '') {
                        $mappedSource = null;
                    }
                    if (is_string($mappedSource) && $mappedSource !== '' && self::sourceContradictsKey($keyToLookup, $mappedSource)) {
                        $mappedSource = $implied ?? $mappedSource;
                    }

                    // Check if the source text looks like a pluralization string (contains | and {} patterns)
                    $sourceTextForPluralCheck = $mappedSource;
                    if ($sourceTextForPluralCheck !== null && TextHelper::isPluralizationString($sourceTextForPluralCheck)) {
                        // For pluralization strings, extract the actual text parts and translate them
                        $text = TextHelper::translatePluralizationString($sourceTextForPluralCheck, $lang);
                    } else {
                        // fallback to known source text, or humanize the key if it looks like a machine key
                        $fallbackText = $mappedSource ?? $implied;
                        if ($fallbackText === null || ($implied !== null && $mappedSource === $implied && $lang !== 'en')) {
                            if ($implied !== null && $lang !== 'en') {
                                $fallbackText = LocaleHelper::humanizeForLang($implied, $lang);
                            } elseif ($fallbackText === null && TextHelper::looksMachineKey($keyToLookup)) {
                                $displayText = TextHelper::extractDisplayTextFromNamespacedKey($keyToLookup);
                                $fallbackText = $lang !== 'en'
                                    ? LocaleHelper::humanizeForLang($displayText, $lang)
                                    : $keyToLookup;
                            } elseif ($fallbackText === null) {
                                $fallbackText = $keyToLookup;
                            }
                        }

                        $text = $fallbackText;
                    }
                }

                // Check for placeholder mismatches and fallback to source if needed
                $sourceText = $sourceTextMap[$keyToLookup] ?? null;
                if (is_string($sourceText) && $sourceText !== '' && self::sourceContradictsKey($keyToLookup, $sourceText)) {
                    $sourceText = self::impliedEnglishForKey($keyToLookup) ?? $sourceText;
                }
                if ($sourceText === null && TextHelper::looksMachineKey($keyToLookup)) {
                    $displayText = TextHelper::extractDisplayTextFromNamespacedKey($keyToLookup);
                    $sourceText = LocaleHelper::humanizeForLang($displayText, 'en');
                } elseif ($sourceText === null) {
                    $sourceText = $keyToLookup;
                }

                if ($sourceText !== $text && ! is_null($sourceText) && TextHelper::hasPlaceholderMismatch($sourceText, $text)) {
                    // Placeholder mismatch detected, fallback to source text
                    $text = $sourceText;
                }

                $chunkTranslations[$lang][$contextualFileKey][$originalKey] = $text;
            }
        }

        return $chunkTranslations;
    }

    /** Calculate total chunks */
    public function calculateTotalChunks(array $keysToTranslate, int $chunkSize): int
    {
        $total = 0;
        foreach ($keysToTranslate as $keys) {
            if ($keys !== []) {
                $total += count(array_chunk($keys, $chunkSize));
            }
        }

        return $total;
    }

    /** Perform cross-check and report */
    public function performCrossCheckAndReport(array $structuredKeys, array $existingTranslations, array $languages, array $scanTargets, $output = null): void
    {
        $missingStats = [];
        foreach ($structuredKeys as $filename => $keys) {
            foreach ($keys as $key) {
                foreach ($languages as $lang) {
                    if (! isset($existingTranslations[$lang][$filename][$key])) {
                        $missingStats[$filename][$lang][] = $key;
                    }
                }
            }
        }

        if ($missingStats === []) {
            if ($output) {
                $output->writeln('<fg=bright-green;options=bold> ✅ All selected keys are fully translated and synchronized across all target languages!</>');
            }

            return;
        }

        if ($output) {
            $output->writeln('<fg=yellow>Found missing translations needing synchronization:</>');
            foreach ($missingStats as $contextualFileKey => $langData) {
                [$targetKey, $fileKey] = explode('::', (string) $contextualFileKey, 2);
                $targetName = $scanTargets[$targetKey]['name'] ?? $targetKey;

                $fileNameDisplay = str_ends_with($fileKey, '__JSON__')
                    ? 'JSON File (' . str_replace('__JSON__', '*.json', $fileKey) . ')'
                    : $fileKey . '.php';

                $output->writeln("  <fg=bright-yellow;options=bold>File: {$targetName} -> {$fileNameDisplay}</>");

                foreach ($langData as $lang => $keys) {
                    $count = count($keys);
                    $output->writeln("    <fg=bright-white>-> Language '<fg=bright-cyan>{$lang}</>' is missing <fg=bright-red;options=bold>{$count}</> keys.</>");
                }
            }

            $output->writeln('');
        }
    }

    /** Filter out existing keys */
    public function filterOutExistingKeys(array $keysForProcessing, array $existingTranslations, array $targetLanguages): array
    {
        $filteredKeys = [];

        foreach ($keysForProcessing as $fileKey => $keys) {
            $filteredKeys[$fileKey] = [];

            foreach ($keys as $key) {
                $shouldInclude = false;

                // Check if the key exists in any of the target languages
                foreach ($targetLanguages as $lang) {
                    if (! isset($existingTranslations[$lang][$fileKey][$key])) {
                        $shouldInclude = true;
                        break;
                    }
                }

                if ($shouldInclude) {
                    $filteredKeys[$fileKey][] = $key;
                }
            }

            // If no keys remain for this file, remove the file entry
            if ($filteredKeys[$fileKey] === []) {
                unset($filteredKeys[$fileKey]);
            }
        }

        foreach ($filteredKeys as &$k) {
            $k = array_values(array_unique($k));
        }

        return $filteredKeys;
    }

    /**
     * Source text for --refresh-clean: never read existing app lang files.
     *
     * Standard Laravel keys use the framework's official English (not
     * underscore/title-case of the key). Custom keys use key shape or a
     * last-segment label. Previous Gemini mistakes cannot be reused.
     */
    public static function sourceForRefresh(string $fullKey): string
    {
        $implied = self::impliedEnglishForKey($fullKey);
        if ($implied !== null) {
            return $implied;
        }

        $official = self::officialLaravelEnglish($fullKey);
        if ($official !== null) {
            return $official;
        }

        if (self::isLiteralUiKey($fullKey)) {
            return $fullKey;
        }

        if (TextHelper::looksMachineKey($fullKey)) {
            return LocaleHelper::humanizeForLang(
                TextHelper::extractDisplayTextFromNamespacedKey($fullKey),
                'en',
            );
        }

        return $fullKey;
    }

    /** Official English from vendor/laravel/framework lang/en, or null. */
    public static function officialLaravelEnglish(string $fullKey): ?string
    {
        $catalog = self::frameworkEnglishCatalog();
        $text = $catalog[$fullKey] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }

    /**
     * @param array<string, list<string>> $structuredKeys
     *
     * @return array<string, string>
     */
    public function rebuildSourceMapForRefresh(array $structuredKeys): array
    {
        $map = [];

        foreach ($structuredKeys as $contextualFileKey => $keys) {
            $fileKey = $contextualFileKey;
            if (str_contains($contextualFileKey, '::')) {
                [, $fileKey] = explode('::', $contextualFileKey, 2);
            }

            $isJsonFile = str_ends_with($fileKey, '__JSON__');
            $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

            foreach ($keys as $key) {
                $fullKey = $isJsonFile ? $key : $prefix . $key;
                $map[$fullKey] = self::sourceForRefresh($fullKey);
            }
        }

        return $map;
    }

    /**
     * Blank or whitespace-only existing values are not usable source.
     * Replace them with key-derived English so --refresh cannot keep "".
     *
     * @param array<string, list<string>> $structuredKeys
     * @param array<string, mixed> $sourceTextMap
     *
     * @return array<string, mixed>
     */
    public function replaceEmptySourceWithKeyDerived(array $structuredKeys, array $sourceTextMap): array
    {
        $rebuilt = $this->rebuildSourceMapForRefresh($structuredKeys);

        foreach ($rebuilt as $fullKey => $derived) {
            $current = $sourceTextMap[$fullKey] ?? null;
            if (! is_string($current) || trim($current) === '') {
                $sourceTextMap[$fullKey] = $derived;

                continue;
            }

            if (self::sourceContradictsKey($fullKey, $current)) {
                $sourceTextMap[$fullKey] = $derived;
            }
        }

        return $sourceTextMap;
    }

    /** Filter for refresh only */
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
            if ($filteredKeys[$fileKey] === []) {
                unset($filteredKeys[$fileKey]);
            }
        }

        // Clean up array keys
        foreach ($filteredKeys as &$k) {
            $k = array_values(array_unique($k));
        }

        return $filteredKeys;
    }

    /** Merge translations */
    /**
     * Resolve the Gemini model the operator asked for.
     *
     * Precedence: explicit override (CLI --model) → config/gemini-translator.php
     * → config/gemini.php → package default. Unknown/paid models are allowed;
     * they simply have no free-tier RPM cap.
     */
    public static function resolveModel(?string $override = null): string
    {
        foreach ([
            $override,
            config('gemini-translator.model'),
            config('gemini.model'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return FreeTierQuotaCatalog::normalize($candidate);
            }
        }

        return self::DEFAULT_MODEL;
    }

    /** @return array<string, string> */
    private static function frameworkEnglishCatalog(): array
    {
        if (is_array(self::$frameworkEnglish)) {
            return self::$frameworkEnglish;
        }

        self::$frameworkEnglish = [];
        $dir = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');
        if (! is_dir($dir)) {
            return self::$frameworkEnglish;
        }

        foreach (['auth', 'pagination', 'passwords', 'validation'] as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file . '.php';
            if (! is_file($path)) {
                continue;
            }

            $data = include $path;
            if (! is_array($data)) {
                continue;
            }

            foreach (Arr::dot($data) as $suffix => $text) {
                if (is_string($text) && $text !== '') {
                    self::$frameworkEnglish[$file . '.' . $suffix] = $text;
                }
            }
        }

        return self::$frameworkEnglish;
    }

    /**
     * Build serializable translation payloads.
     *
     * @return list<array<string, mixed>>
     */
    private function buildTranslationPayloads(array $structuredKeys, array $languages, array $sourceTextMap, array $options): array
    {
        $chunkSize = (int) $options['chunk-size'];
        $maxRetries = (int) ($options['max-retries'] ?? 5);
        $retryDelay = (int) ($options['retry-delay'] ?? 3);
        $projectContext = $options['context'] ?? null;
        $tasks = [];

        foreach ($structuredKeys as $contextualFileKey => $keys) {
            if ($keys === []) {
                continue;
            }

            // Filter out empty or whitespace-only keys
            $keys = array_filter($keys, fn($key) => is_string($key) && trim($key) !== '');
            $keys = array_values($keys); // Re-index

            // Skip if all keys were filtered out
            if ($keys === []) {
                continue;
            }

            [, $fileKey] = explode('::', (string) $contextualFileKey, 2);
            $isJsonFile = str_ends_with($fileKey, '__JSON__');
            $prefix = $isJsonFile ? '' : str_replace('/', '.', $fileKey) . '.';

            $fullKeysForAI = $isJsonFile ? $keys : array_map(fn($key) => $prefix . $key, $keys);

            // Intelligent chunk size adjustment based on key complexity
            $effectiveChunkSize = $chunkSize;
            $avgKeyLength = array_sum(array_map(strlen(...), $fullKeysForAI)) / count($fullKeysForAI);

            // If average key length is very long (>80 chars), reduce chunk size significantly
            if ($avgKeyLength > 80) {
                $effectiveChunkSize = max(1, min(3, $chunkSize)); // Limit to max 3 keys per chunk
            } elseif ($avgKeyLength > 60) {
                $effectiveChunkSize = max(1, min(5, $chunkSize)); // Limit to max 5 keys per chunk
            }

            $keyChunks = array_chunk($fullKeysForAI, $effectiveChunkSize);
            $originalKeyChunks = array_chunk($keys, $effectiveChunkSize);

            foreach ($keyChunks as $index => $chunk) {
                $originalChunk = $originalKeyChunks[$index];

                // capture only the subset of sourceTextMap needed for the current chunk
                $chunkSourceTextMap = [];
                foreach ($chunk as $fullKey) {
                    if (isset($sourceTextMap[$fullKey])) {
                        $chunkSourceTextMap[$fullKey] = $sourceTextMap[$fullKey];
                    }
                }

                $tasks[] = [
                    'keys' => $chunk,
                    'original_keys' => $originalChunk,
                    'languages' => $languages,
                    'contextual_file_key' => $contextualFileKey,
                    'max_retries' => $maxRetries,
                    'retry_delay' => $retryDelay,
                    'project_context' => $projectContext,
                    'source_text_map' => $chunkSourceTextMap,
                    'model' => self::resolveModel(isset($options['model']) && is_string($options['model']) ? $options['model'] : null),
                ];
            }
        }

        return $tasks;
    }

    /** Build a self-explanatory per-key block for the user prompt. */
    private static function describeKeyForPrompt(string $key, mixed $source): string
    {
        $lines = ["- Key: `{$key}`"];

        $implied = self::impliedEnglishForKey($key);
        if ($implied !== null && $implied !== '' && is_string($source) && $source !== '' && self::sourceContradictsKey($key, $source)) {
            $lines[] = '  Source text looks inconsistent with the key (wrong placeholder or invented plural). IGNORE that source.';
            $lines[] = '  Use this meaning instead: ' . $implied;

            return implode("\n", $lines) . "\n";
        }

        if (is_string($source) && $source !== '') {
            $lines[] = '  Source text (translate THIS exact meaning; do not rewrite the key name): ' . $source;
            if (preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $source, $matches) > 0) {
                $lines[] = '  Required placeholders (copy each token exactly): ' . implode(', ', array_unique($matches[0]));
            }

            if (TextHelper::isPluralizationString($source)) {
                $lines[] = '  This source is a Laravel plural string. Keep every `{n}` / `[range]` token and every `|` segment. Translate only the words.';
            }

            return implode("\n", $lines) . "\n";
        }

        $lines[] = '  Source text: (none)';

        if (preg_match('/(?:^|\.)(attributes|custom|values)$/', $key) === 1) {
            $lines[] = '  This is a Laravel array-container key, not a UI sentence. Set EVERY language to an empty string.';

            return implode("\n", $lines) . "\n";
        }

        if (preg_match('/(?:^|\.)by_([A-Za-z][A-Za-z0-9_]*)$/', $key, $match) === 1) {
            $token = ':' . $match[1];
            $lines[] = "  No source. Last segment `by_{$match[1]}` means English \"By {$token}\". The only allowed placeholder is {$token} — never rename it.";

            return implode("\n", $lines) . "\n";
        }

        $isHumanReadableKey = str_contains($key, ' ')
            && ! str_contains($key, '.')
            && preg_match('/\p{L}/u', $key) === 1;

        if ($isHumanReadableKey) {
            $lines[] = '  This key is already human-readable UI text. For `en`, copy the key EXACTLY. Translate that same wording for other languages. Do not shorten or expand it.';

            return implode("\n", $lines) . "\n";
        }

        $lastSegment = $key;
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $lastSegment = end($parts);
        }

        $human = str_replace(['_', '-'], ' ', $lastSegment);
        $lines[] = "  No source. Derive a short label from the last segment only (\"{$human}\"). Do not add placeholders, slogans, extra sentences, or Laravel plural pipes.";

        return implode("\n", $lines) . "\n";
    }

    /** English implied by the key shape, used when source is missing or corrupt. */
    private static function impliedEnglishForKey(string $key): ?string
    {
        if (preg_match('/(?:^|\.)(attributes|custom|values)$/', $key) === 1) {
            return '';
        }

        if (preg_match('/(?:^|\.)by_([A-Za-z][A-Za-z0-9_]*)$/', $key, $match) === 1) {
            return 'By :' . $match[1];
        }

        return null;
    }

    private static function isLiteralUiKey(string $key): bool
    {
        if (str_starts_with($key, '{') || str_contains($key, '|')) {
            return true;
        }

        return str_contains($key, ' ') && preg_match('/\p{L}/u', $key) === 1;
    }

    private static function sourceContradictsKey(string $key, string $source): bool
    {
        if (preg_match('/(?:^|\.)by_([A-Za-z][A-Za-z0-9_]*)$/', $key, $match) === 1) {
            return ! str_contains($source, ':' . $match[1]);
        }

        return false;
    }

    /** Drop Gemini output that mixes scripts or invents placeholders. */
    private static function translationRejected(string $text, string $lang, mixed $sourceText, string $key = ''): bool
    {
        if (LocaleHelper::hasDisallowedScript($text, $lang)) {
            return true;
        }

        if (LocaleHelper::looksUntranslated($text, $lang)) {
            return true;
        }

        if ($lang !== 'en' && is_string($sourceText) && $sourceText !== '' && $text === $sourceText && preg_match('/[A-Za-z]{4,}/', $sourceText) === 1) {
            return true;
        }

        $effectiveSource = is_string($sourceText) ? $sourceText : '';
        if ($key !== '' && $effectiveSource !== '' && self::sourceContradictsKey($key, $effectiveSource)) {
            $effectiveSource = self::impliedEnglishForKey($key) ?? $effectiveSource;
        }

        if ($effectiveSource !== '' && TextHelper::hasPlaceholderMismatch($effectiveSource, $text)) {
            return true;
        }

        if ($effectiveSource !== '' && ! TextHelper::isPluralizationString($effectiveSource) && TextHelper::isPluralizationString($text)) {
            return true;
        }

        if ($effectiveSource !== '' && TextHelper::isPluralizationString($effectiveSource)) {
            preg_match_all('/\{[0-9]+\}|\[[^\]]+\]/', $effectiveSource, $sourceTokens);
            preg_match_all('/\{[0-9]+\}|\[[^\]]+\]/', $text, $textTokens);
            if ($sourceTokens[0] !== $textTokens[0]) {
                return true;
            }
        }

        if (preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $text, $found) > 0) {
            $allowed = [];
            if ($effectiveSource !== '' && preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $effectiveSource, $srcFound) > 0) {
                $allowed = array_unique($srcFound[0]);
            }

            if ($key !== '' && preg_match('/(?:^|\.)by_([A-Za-z][A-Za-z0-9_]*)$/', $key, $match) === 1) {
                $allowed[] = ':' . $match[1];
            }

            foreach (array_unique($found[0]) as $token) {
                if ($allowed !== [] && ! in_array($token, $allowed, true)) {
                    return true;
                }

                if ($allowed === [] && $effectiveSource === '') {
                    return true;
                }
            }
        }

        return false;
    }

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
                        if (! isset($allExisting[$key])) {
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
}
