# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### ⭐ Added

- **Multi-OS adapter layer:** `PromptInterface` and `TaskRunnerInterface` with a `PlatformFactory` that routes on `PHP_OS_FAMILY`
  - Unix: Laravel Prompts + `spatie/fork` (`pcntl`)
  - Windows: isolated `kernel32.dll` FFI console binder + Symfony Process workers
  - Sequential `sync` runner on every OS, with cooperative stop
- **`--driver=process`:** Concurrent Symfony Process workers (used automatically when `--driver=fork` is selected on Windows)
- Hidden `translations:run-payload` worker command for Windows/process child jobs
- Suggested extensions: `ext-pcntl` (fork) and `ext-ffi` (native Windows menus)
- **Dated Gemini quota snapshot** in publishable `config/gemini-translator.php` (as of 2026-08-13). Rows can be raised, lowered, zeroed, removed, or extended when Google changes RPM/RPD or ships a new model. `0` RPM/RPD is a first-class “no free-tier budget” state, not a crash.
- **Translation Manager** at `/translations-manager` (configurable prefix). The package registers the page plus data/save/scan/add-languages JSON APIs. If the host app already has login/register routes, guests must sign in first (redirect or JSON 401). If it has no auth routes, the manager is open. Optional publish: `--tag=gemini-translator-manager`. Semantic component CSS (no Tailwind/Bootstrap utilities), native `<dialog>` / `<search>`, theme switch, and token layers.

### 🔧 Changed

- Interactive prompts and parallel execution no longer branch on `PHP_OS_FAMILY` inside the command/services; they go through the factory
- `--driver=fork` on Windows now runs Process workers instead of silently falling back to sync
- Windows FFI menus use the same boxed UI, checkboxes, and keybindings as Laravel Prompts (arrows, space, enter). Symfony Process workers match fork: ordered results, inherited env, no cooperative mid-flight stop, same progress UX
- Command summary now records `processed_chunks` from the translation runner
- `--refresh` is the original refresh again (existing keys only, current file wording is the source). New `--refresh-clean` is the key-only rebuild that ignores stale/faulty file text. Clean source for `auth`/`pagination`/`passwords`/`validation` is Laravel's official English, not `ucwords(str_replace('_', ' ', $key))`
- JSON writes keep dotted PHP-style keys (`messages.welcome`, `validation.required`) when that is the selected group. Blank literal JSON values are filled from the key; empty/whitespace source is treated as missing
- Gemini model is user-configurable: `--model=`, `GEMINI_MODEL` / `GEMINI_TRANSLATOR_MODEL`, then `config/gemini-translator.php`. Paid/ListModels ids are allowed and are not free-tier capped unless listed in the snapshot
- Default Gemini model is `gemini-3.5-flash-lite` (highest free-tier RPM/RPD that appears in ListModels: 15 RPM / 500 RPD). Snapshot rows for models missing from ListModels (`gemini-2.5-flash-exp`, `gemini-3-flash`) were removed.
- JSON-only selection no longer re-translates `file.subkey` keys that belong to PHP lang files
- Translation prompts now include source text and reject mixed-script / placeholder / plural-token drift
- Per-locale writing-system guard: `LocaleHelper` maps each language code to one Unicode script (`sc=`, so shared danda `।` is not a mix). Gemini output with neighbor-script letters (Gujarati+Kannada `ೋ`, Hindi+Gujarati, Urdu inside `hi`) or leftover English sentences is rejected and falls back to source
- Translation Manager language catalog is the full 249-code set (including `zh_CN` / `zh_TW`, `pt_BR` / `pt_PT`, Punjabi Gurmukhi/Shahmukhi). Short file codes such as `zh` still resolve to the primary variant name

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
