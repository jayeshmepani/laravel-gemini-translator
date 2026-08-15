# Changelog

All notable changes to this project will be documented in this file.

## [v5.1.0] - 2026-08-14

Everything below is new since **v5.0.1**.

### ⭐ Added

- **Translation Manager** at `/translations-manager` (prefix, enable flag, and middleware are configurable). Routes and JSON APIs register automatically: data, languages, existing map, scan, save, add-languages. Guests must sign in when the host already has login/register/sign-in routes (browser redirect or JSON 401 + unauthenticated view). If the host has no auth routes, the manager is open. Publish tags: `gemini-translator-config`, `gemini-translator-views`, `gemini-translator-assets`, `gemini-translator-manager`. `ManagerViewComposer` inlines CSS/JS so the page works without publishing assets. Optional `@include('gemini-translator::partials.workspace')` for an existing layout.
- **Manager UI:** semantic component CSS (no Tailwind/Bootstrap utilities), native `<dialog>` / `<search>` / `popover`, light/dark theme (`localStorage` key `gemini-translator-theme`), sticky KEY column, pagination footer (default 5 rows, last two pages always visible with an ellipsis), Type / Module / Pack / Scope / PHP-files / Language / “show only missing” / **Highlight script faults** filters, Add Languages, Sync, Save. Checkboxes use a `✓` mark on the monochromatic tokens (sun stays yellow, Save stays green).
- **Manager script-fault highlighter:** optional checkbox (off by default, stored as `gemini-translator-malform-detector`). Uses `LocaleHelper::detectorCatalog()` / `malformReasons()`. Flags letters that do not belong to the cell’s locale — including Latin inside Gujarati/Hindi/etc. Placeholders (`:name`, `{0}`, `[2,*]`) and Common marks (danda `।`) are ignored. Faulty editors get an amber outline/background and a tooltip of the unexpected scripts. Stricter than the CLI `hasDisallowedScript()` check, which still allows Latin in native scripts.
- **Lang packs (manager + CLI):** extra folders such as `lang/app3/` and `lang/web/` are first-class trees, separate from `lang/`. The manager Pack filter appears only after you select a module (or Non-module) that actually has more than one pack. PHP file pickers list files from the selected pack. A selected language lists only keys that exist in that locale file.
- **Registered / published lang paths:** the manager also scans `loadJsonTranslationsFrom()` / `loadTranslationsFrom()` directories (for example `base_path('custom_dir')`) and published `resources/lang/modules/{name}` (published copy wins on the same key). Composer `vendor/` lang trees are skipped.
- **CLI pack step:** after you pick a module that has extra lang folders, `translations:extract-and-generate` asks which packs to process. The JSON/PHP file list is then limited to those packs. Modules with only `lang/` skip the prompt. Pack PHP files load and write as `lang/{pack}/{locale}/messages.php`.
- **249-language catalog** (`LanguageCatalog`) with display names and fallbacks (`zh` → `zh_CN`, `pt` → `pt_BR`, `pa` → `pa_GURU`, and similar). Short file codes still resolve to the primary variant.
- **Multi-OS adapter layer:** `PromptInterface` and `TaskRunnerInterface` via `PlatformFactory` on `PHP_OS_FAMILY`
  - Unix: Laravel Prompts + `spatie/fork` (`pcntl`)
  - Windows: isolated `kernel32.dll` FFI console binder + Symfony Process workers
  - Sequential `sync` runner on every OS, with cooperative stop
- **`--driver=process`:** concurrent Symfony Process workers (also used when `--driver=fork` is selected on Windows)
- Hidden `translations:run-payload` worker command for Windows/process child jobs
- Suggested extensions: `ext-pcntl` (fork) and `ext-ffi` (native Windows menus)
- **`--refresh-clean`:** rebuild existing keys only, ignoring stale file wording. Official Laravel English is the source for `auth` / `pagination` / `passwords` / `validation` (not `ucwords` of the key)
- **Dated Gemini quota snapshot** in publishable `config/gemini-translator.php` (as of 2026-08-13). Add, raise, lower, zero, or remove rows when Google changes RPM/RPD. `0` RPM/RPD is “no free-tier budget”, not a crash. `GEMINI_TRANSLATOR_APPLY_FREE_TIER_CAPS` can turn snapshot caps off
- **Configurable Gemini model:** `--model=`, `GEMINI_MODEL` / `GEMINI_TRANSLATOR_MODEL`, then `config/gemini-translator.php`, then `config('gemini.model')`, then the package default. Paid/ListModels ids are allowed and are not free-tier capped unless listed in the snapshot
- Per-locale **writing-system map** on `LocaleHelper` (Unicode `sc=` so shared danda `।` is Common, not a mix) plus `looksUntranslated` / disallowed-script checks. Map now includes Tibetan, N’Ko, Tifinagh, Ol Chiki, Meitei, Canadian Aboriginal, extra Devanagari/Cyrillic/Arabic catalog codes, and script tags (`_latn`, `_cyrl`, `_arab`, `_deva`, `_guru`, `_hans`/`_hant`, `_tfng`, `_olck`, `_syll`)

### 🐛 Fixed

- Manager pager `aria-label` no longer uses `__('Pagination')`. On Windows that key loads `lang/{locale}/pagination.php` as an array and crashes `htmlspecialchars()`.

### 🔧 Changed

- Interactive prompts and parallel execution go through the platform factory instead of branching on `PHP_OS_FAMILY` inside the command/services
- `--driver=fork` on Windows runs Process workers instead of silently falling back to sync
- Windows FFI menus match Laravel Prompts (boxed list, checkboxes, arrows, space, enter). Process workers match fork: ordered results, inherited env, no cooperative mid-flight stop, same progress UX
- `--refresh` is the original refresh again: existing keys only, current file wording is the source
- Default Gemini model is `gemini-3.5-flash-lite` (15 RPM / 500 RPD in the snapshot and present in ListModels). Snapshot rows for ids missing from ListModels (`gemini-2.5-flash-exp`, `gemini-3-flash`) were removed
- JSON writes keep dotted PHP-style keys (`messages.welcome`, `validation.required`) when that group is selected. Blank literal JSON values are filled from the key; empty/whitespace source is treated as missing
- JSON-only selection no longer re-translates `file.subkey` keys that belong to a selected PHP lang file
- Translation prompts include source text and reject mixed-script, placeholder, and plural-token drift. Rejected Gemini output falls back to source
- Command summary records `processed_chunks` from the translation runner
- CLI file picker labels pack PHP as `lang/{pack}/{file}.php`. Selecting a single file returns that file (no longer `array_keys` of a list)

### 🐛 Fixed

- Mixed-script deformities (Gujarati + Kannada `ೋ`, Hindi + Gujarati, leftover English in Indic locales) are rejected instead of written
- Add-languages no longer 500s as a false positive when one writable root succeeds and another (for example a module `lang/`) is not writable
- Manager writes treat “file exists after put” as success so pessimistic `is_writable` / `www-data` checks do not throw after a successful write
- `web` (and other 2–3 letter pack folder names) are packs when they contain locale JSON or locale dirs, not locales
- Nested PHP groups such as `foo/bar.php` stay on the root pack unless the first segment is a known pack
- `LanguageCatalog::FALLBACKS` typed as `array<string, string>`

### 📚 Tests

- Suite covers the manager routes/views/auth, language catalog, script guard, malform detector catalog, model resolution, refresh source map, platform factory, Windows process runner, pack discovery, pack PHP I/O, and pack CLI prompts

## [v5.0.1] - 2026-04-27

### ⭐ Added

- `driftingly/rector-laravel` plus `rector:dry` / `rector:debug` composer scripts

### 🔧 Changed

- Rector Laravel rule sets and composer-based versioning; auto-imports and unused-import cleanup (`Sleep::usleep()`, `sprintf()`, strict comparisons)

## [v5.0.0] - 2026-04-27

### ⚠ BREAKING CHANGES

- **PHP 8.3 Required**: Minimum PHP version bumped from 8.2 to 8.3 (required for Laravel 13 support)
- **Strict Typing**: Added `declare(strict_types=1)` to all PHP files for type safety

### ⭐ Added

- **Laravel 13 Support**: Full compatibility with Laravel 13.x
- **Comprehensive Test Suite**: Added 37 tests with 110 assertions using Orchestra Testbench
  - Unit tests for `LocaleHelper` and `TextHelper` utilities
  - Feature tests for `CommandRegistration`, `ServiceProvider`, `ScannerService`, and `FileSystemService`
- **Code Quality Tools**: Integrated PHPStan (level 5), Laravel Pint, and Rector
- **Type Safety**: Strict type declarations and explicit type casting throughout codebase

### 🔧 Changed

- **Strict Comparisons**: Replaced `empty()` with explicit array/string comparisons (`=== []`, `!== []`)
- **Strict `in_array()`**: Added `true` parameter for strict type checking
- **Command Options**: Added explicit `(int)` casts for `chunk-size`, `retry-delay`, and `concurrency` options
- **Removed Unused Code**: Cleaned up 3 unused class constants (`ALL_FILES_KEY`, `ALL_TARGETS_KEY`, `FILE_KEY_SEPARATOR`)

### 🐛 Fixed

- **Runtime Type Errors**: Fixed `usleep()` calls to use `int` instead of `float`
- **Type Safety**: Fixed all PHPStan level 5 errors across the codebase

## [v4.0.2] - 2026-03-19

### 🐛 Fixed

- **JSON-Only File Selection Integrity**
  - Fixed unintended creation of locale directories (`lang/{locale}`) during JSON-only runs.
  - Fixed unintended framework PHP file publication (`auth.php`, `pagination.php`, `passwords.php`, `validation.php`) when only `JSON File (*.json)` groups are selected.
  - Framework/vendor translation keys are still loaded and available for JSON output (`en.json`, `hi.json`, etc.) without forcing PHP file creation.
  - PHP locale directories and files are now created only when PHP file groups are actually selected for processing.

## [v4.0.1] - 2025-11-24

### 🐛 Fixed

- **Translation Service**
  - Added automatic filtering of empty and whitespace-only translation keys before AI processing
  - Prevents "Syntax error" from Gemini API when empty string keys are present in language files
  - Empty keys are now filtered at two points: in `buildTranslationTasks()` and `staticTranslateKeysWithGemini()`

### 🚀 Performance

- **Intelligent Chunk Sizing**
  - Added automatic chunk size reduction for extremely long translation keys
  - Keys with average length > 80 characters now limited to max 3 keys per chunk
  - Keys with average length > 60 characters now limited to max 5 keys per chunk
  - Significantly improves success rate for complex validation attribute keys

### 🔧 Changed

- **Error Logging**
  - Enhanced error logging to capture raw Gemini API responses on final retry attempt
  - Logs now include truncated API response text for easier debugging
  - Helps diagnose "Syntax error" and other API-related failures

### 📚 Documentation

- Added critical warning about `config/gemini.php` type casting requirement
- Added troubleshooting guide for "Configuration value must be an integer" error with solution
- Created comprehensive troubleshooting reference covering configuration errors, rate limiting, and API connectivity
- Documented v4.0.1+ automatic features (empty key filtering, intelligent chunking)

## [v4.0.0] - 2025-11-23

### ⚠ BREAKING CHANGES

- Removed deprecated `--source` option in favor of interactive target selection
- Removed `--no-advanced` pattern detection option
- Default behavior now canonicalizes language codes using LocaleHelper
- Dry-run mode added - no files written when `--dry-run` is used

### ⭐ Added

- **New Operational Modes**
  - `--refresh` flag: Re-translate only existing keys from language directories; do NOT generate translations for new/missing keys
  - `--dry-run` flag: Run full extraction + mapping but show what files would be modified without writing anything
  - `--concurrency=N` option: Configure number of concurrent processes when using fork driver (defaults to 15)

- **Enhanced Locale & Translation Quality**
  - Added `LocaleHelper` utility class with locale canonicalization, script type detection, and humanization rules
  - Support for proper title case vs sentence case based on target language (English uses title case, other Latin languages use sentence case)
  - Script-aware placeholder validation with count checking instead of just presence
  - Pluralization string detection that operates on source text instead of keys
  - Advanced key humanization with namespaced key display extraction
  - Enhanced `looksMachineKey()` detection with comprehensive pattern matching for PascalCase, snake_case, kebab-case, and dot.notation
  - Optimized `looksMachineKey()` with fast-path checks for common patterns combined with robust regex validation

- **Framework Integration**
  - Added automatic Laravel framework translation bootstrapping that syncs vendor language files to app lang directory
  - Framework translations are merged with app overrides and updated only when new keys are added

- **New Extractor Features**
  - Support for Vue/Alpine bound attributes (`:v-t`, `:x-text`, `v-bind:v-t`, `v-bind:x-text`)
  - Multi-line string extraction support with `/s` (PCRE_DOTALL) modifier
  - Backtick string extraction support
  - Function call extraction from attribute values (e.g., `x-text="__('messages.hello')"`)

- **AI Translation Improvements**
  - Implemented system instructions separation using `withSystemInstruction()` from Gemini PHP SDK v2.0
  - Static rules and role definitions now isolated from dynamic query content
  - Enhanced language strictness with multiple validation checkpoints in prompts
  - Improved AI consistency by clearly distinguishing between role/rules and translation tasks

### 🔧 Changed

- **Command Signature**
  - Removed deprecated `--source` option
  - Removed `--no-advanced` option
  - Renamed description of `--langs` to clarify English is used as source
  - Added descriptions for new options

- **Translation Flow**
  - Converted from 2-phase to 3-phase system (Full Sync, Missing-only, Refresh-only)
  - Mode validation: Users can no longer use `--refresh` and `--skip-existing` together
  - All language codes are now canonicalized using `LocaleHelper::canonicalize()`

- **File Writing**
  - Modernized PHP array syntax from `array()` to `[]` in generated translation files
  - File writing is now atomic with proper temp file creation and cleanup
  - Added directory traversal protection for file writing

- **Concurrency Handling**
  - Fork mode concurrency is now configurable via `--concurrency` option
  - Improved stop-key messaging: no longer shows misleading message in fork mode
  - Added explicit warning when running in fork mode about inability to stop mid-process

- **Error Handling**
  - Enhanced JSON parsing from Gemini responses with multiple fallback strategies
  - Better error context with file names, keys, and last error details
  - Differentiated retry logic for quota vs JSON vs network errors

### 🐛 Fixed

- **Critical Security & Safety**
  - Fixed directory traversal vulnerability in file writing with proper path validation
  - Fixed race condition in file writing with atomic temp file strategy
  - Fixed Windows compatibility issues with case-sensitive path validation

- **Translation Logic**
  - Fixed pluralization detection to check source text instead of translation keys
  - Fixed placeholder validation to check counts instead of just presence
  - Fixed machine key detection logic for accurate humanization
  - Fixed key origin mapping to preserve module/app separation

- **Module Support**
  - Fixed module directory exclusion when scanning main application (prevents double-scanning)
  - Fixed relative path calculation for module files in extraction logs
  - Added null-safe config handling for module paths to prevent basename errors

- **Regex Extraction**
  - Fixed multi-line string extraction with proper `/s` modifier
  - Fixed attribute value extraction to handle escaped quotes properly
  - Fixed bound attribute pattern matching

- **User Interface & Selection**
  - Fixed "-- ALL FILES --" selection returning empty array instead of all available files
  - Fixed manual file selection not being detected when user selects all individual options
  - Enhanced file selection logic to handle edge cases: selecting only "ALL FILES", selecting all manually, or selecting both
  - Fixed language loading to only load explicitly requested languages instead of all available language directories

### 🚀 Performance

- **Memory Optimization**
  - Reduced memory usage in concurrent mode by passing only needed subset of `$sourceTextMap` to each closure
  - Eliminated duplicate helper methods (static vs instance) to reduce code duplication

- **Concurrency Improvements**
  - Configurable concurrency levels for better API rate limit management
  - Improved retry logic to avoid unnecessary API calls on JSON parsing errors

### 💥 Deprecated

- `--source` option (now unused, was for deprecated interactive scanning)
- `--no-advanced` option (advanced pattern detection removed as it caused false positives)

### 📝 Notes

- Version jump from v3.8 to v4.0 reflects significant architectural changes and breaking behavioral changes
- The tool now provides production-grade safety with atomic file writes and security validation
- Translation quality has been significantly improved with script-aware formatting and robust fallback mechanisms
- The addition of `--refresh` and `--dry-run` modes enables safer integration into deployment pipelines

[v4.0.0]: https://github.com/jayeshmepani/laravel-gemini-translator/compare/3.8...4.0
[v4.0.1]: https://github.com/jayeshmepani/laravel-gemini-translator/compare/4.0...4.0.1
[v4.0.2]: https://github.com/jayeshmepani/laravel-gemini-translator/compare/4.0.1...4.0.2
[v5.0.0]: https://github.com/jayeshmepani/laravel-gemini-translator/compare/4.0.3...5.0.0
[v5.0.1]: https://github.com/jayeshmepani/laravel-gemini-translator/compare/5.0.0...5.0.1
[v5.1.0]: https://github.com/jayeshmepani/laravel-gemini-translator/compare/5.0.1...5.1.0
