# Changelog

All notable changes to this project will be documented in this file.

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
