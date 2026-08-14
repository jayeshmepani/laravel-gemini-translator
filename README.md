# Laravel Gemini AI Translation Extractor

An interactive Artisan command that scans your Laravel project for translation keys, translates them using Google's Gemini AI, and generates the necessary language files with advanced safety and performance features.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![Total Downloads](https://img.shields.io/packagist/dt/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![License](https://img.shields.io/packagist/l/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)

## 🚀 Key Features

- **AI-Powered Translation:** Uses Gemini AI for high-quality translations with context awareness
- **Translation Manager:** Browser UI at `/translations-manager` to browse, edit, save, scan, and add languages
- **Lang packs:** Extra folders such as `lang/app3/` and `lang/web/` are handled separately from `lang/` in both the CLI and the manager
- **Interactive & Cross-Platform:** Laravel Prompts + fork on Linux/macOS; `kernel32` menus + Symfony Process workers on Windows
- **Flexible Concurrency:** Fork driver (`pcntl`), Process driver, and a sequential sync fallback
- **Smart Key Detection:** Scans Blade, PHP, Vue, JS, and TypeScript files comprehensively
- **Framework Integration:** Automatic Laravel framework translation bootstrapping
- **Four Operational Modes:** Full sync, missing-only (`--skip-existing`), refresh from file wording (`--refresh`), clean refresh from keys only (`--refresh-clean`)
- **Configurable model:** `--model`, `GEMINI_MODEL` / `GEMINI_TRANSLATOR_MODEL`, or config (default `gemini-3.5-flash-lite`)
- **Writing-system guard:** Mixed-script or leftover-English Gemini output is rejected; JSON keeps dotted PHP-style keys
- **Production-Ready Safety:** Atomic file writes, path validation, and security checks
- **Module Support:** Full integration with `nwidart/laravel-modules` with consolidation options

## 📋 Requirements

- PHP 8.3 or higher
- Laravel 11.0, 12.0, or 13.0
- Google Gemini API key
- `pcntl` extension (for the fork driver on Linux/macOS)
- `ffi` extension (optional; native Windows menus via `kernel32.dll`)
- `tokenizer` PHP extension (for proper code parsing)

## ⚡ Quick Start

### 1. Installation

```bash
composer require jayesh/laravel-gemini-translator
php artisan vendor:publish --provider="Jayesh\LaravelGeminiTranslator\TranslationServiceProvider"
php artisan vendor:publish --tag=gemini-translator-config
```

### Translation Manager page

The package registers the UI and JSON APIs for you. Open:

`/translations-manager`

Example: `http://127.0.0.1:8000/translations-manager`

Auth is automatic:

- **App already has login/register (Breeze, Fortify, Jetstream, Filament, …):** guests must sign in first. Browser requests redirect to the login page; JSON/AJAX requests get **401 Unauthenticated**.
- **App has no auth routes:** the manager is available without signing in.

Optional publish (only if you want to edit the Blade or serve CSS/JS as files):

```bash
php artisan vendor:publish --tag=gemini-translator-manager
```

That copies views to `resources/views/vendor/gemini-translator/` and CSS/JS to `public/vendor/gemini-translator/`. After publishing assets, pass `$assetCss` / `$assetJs` to use those files instead of the inlined CSS/JS.

Change the URL or turn the UI off in `config/gemini-translator.php` (publish with `--tag=gemini-translator-config`):

```php
'manager' => [
    'enabled' => true,
    'prefix' => 'translations-manager',
    'middleware' => ['web'],
],
```

Or `.env`:

```env
GEMINI_TRANSLATOR_MANAGER=true
GEMINI_TRANSLATOR_MANAGER_PREFIX=translations-manager
GEMINI_TRANSLATOR_MANAGER_MIDDLEWARE=web
```

To drop the workspace into an existing layout:

```blade
@include('gemini-translator::partials.workspace')
```

The include picks up the registered `/translations-manager/*` endpoints automatically.

The table reads every lang tree Laravel knows about: `lang/`, `Modules/*/lang`, published `resources/lang/modules/{name}`, and any extra directory passed to `loadJsonTranslationsFrom()` / `loadTranslationsFrom()`. If a module (or the app) registers more than one folder — for example `lang/`, `lang/app3/`, and `lang/web/` — each pack stays a separate set of keys. Select **Module**, then **Pack**, to work on one folder at a time.

The Artisan command uses the same pack step: after you pick a module that has extra lang folders, it asks which packs to process (`lang/`, `lang/app3/`, `lang/web/`). Then it lists JSON and PHP files from those packs only. Modules with a single `lang/` tree skip that prompt.

### 2. Configuration

Add to your `.env`:

```env
GEMINI_API_KEY="YOUR_GEMINI_API_KEY"
GEMINI_REQUEST_TIMEOUT=600

# Optional — any Gemini model id (free or paid). Default: gemini-3.5-flash-lite
GEMINI_MODEL="gemini-2.5-pro"
# or: GEMINI_TRANSLATOR_MODEL="gemini-3.5-flash"
```

Or one run at a time:

```bash
php artisan translations:extract-and-generate --model=gemini-2.5-pro --langs=en,hi
```

Precedence: `--model` → `GEMINI_TRANSLATOR_MODEL` / `config/gemini-translator.php` → `config('gemini.model')` → package default. Paid models are allowed; they are not free-tier RPM-capped unless you add them to the snapshot.

Get your API key from [Google AI Studio](https://makersuite.google.com/app/apikey).

**⚠️ IMPORTANT:** If you published the `config/gemini.php` file from the `google-gemini-php/laravel` package, make sure the `request_timeout` is cast to an integer:

```php
// ✅ CORRECT
'request_timeout' => (int) env('GEMINI_REQUEST_TIMEOUT', 600),

// ❌ WRONG - Will cause "Configuration value must be an integer" error
'request_timeout' => env('GEMINI_REQUEST_TIMEOUT', 600),
```

### 3. Basic Usage

```bash
# Linux/macOS (fastest with configurable concurrency)
php artisan translations:extract-and-generate --driver=fork --concurrency=10

# Windows (parallel via Symfony Process)
php artisan translations:extract-and-generate --driver=fork --concurrency=10

# Sequential (any OS)
php artisan translations:extract-and-generate --driver=sync

# Preview changes without writing files
php artisan translations:extract-and-generate --dry-run

# Refresh only existing translations (uses current file wording as source)
php artisan translations:extract-and-generate --refresh

# Clean-refresh existing keys from the key shape only (ignores stale/faulty file text)
php artisan translations:extract-and-generate --refresh-clean

# Add only missing translations (recommended for updates)
php artisan translations:extract-and-generate --skip-existing
```

## 📖 Documentation

For detailed documentation, step-by-step guides, and advanced usage examples, visit our comprehensive documentation:

**[📚 Full Documentation](https://jayeshmepani.github.io/laravel-gemini-translator/)**

## 🔧 Available Options

### Basic Options

```bash
# Custom languages (English is always used as source)
php artisan translations:extract-and-generate --langs=en,es,fr,de

# Skip existing translations (translate only missing keys)
php artisan translations:extract-and-generate --skip-existing

# Refresh existing translations (re-translate existing keys only)
php artisan translations:extract-and-generate --refresh

# Preview without writing files
php artisan translations:extract-and-generate --dry-run

# Custom chunk size for API requests
php artisan translations:extract-and-generate --chunk-size=50

# Custom concurrency (when using fork driver)
php artisan translations:extract-and-generate --concurrency=20

# Exclude directories
php artisan translations:extract-and-generate --exclude=vendor,node_modules

# Custom target directory
php artisan translations:extract-and-generate --target-dir=custom-lang

# Provide project context for better translations
php artisan translations:extract-and-generate --context="E-commerce platform with payment features"
```

### Advanced Options

```bash
# Concurrency driver (default, fork, process, sync)
php artisan translations:extract-and-generate --driver=fork
php artisan translations:extract-and-generate --driver=process

# Retry settings
php artisan translations:extract-and-generate --max-retries=3 --retry-delay=5

# Custom extensions
php artisan translations:extract-and-generate --extensions=php,blade.php,vue,js,ts,json

# Consolidate module translations
php artisan translations:extract-and-generate --consolidate-modules

# Get help
php artisan help translations:extract-and-generate
```

### Mode Compatibility

- `--refresh`, `--refresh-clean`, and `--skip-existing` are mutually exclusive (the command will fail if more than one is used)
- `--dry-run` works with all other options to preview changes
- `--concurrency` affects the `fork` and `process` drivers

## 🏗️ File Structure & Support

### Directory Structure

```
lang/
├── en/
│   ├── auth.php
│   ├── pagination.php
│   ├── passwords.php
│   └── validation.php
├── es/
│   ├── auth.php
│   ├── pagination.php
│   ├── passwords.php
│   └── validation.php
├── en.json
├── es.json
└── fr.json
```

### Supported File Types

- **Templates:** `.blade.php`
- **PHP Files:** `.php`
- **Frontend:** `.vue`, `.js`, `.jsx`, `.ts`, `.tsx`
- **Configuration:** `.json`

### Translation Functions

- **Laravel:** `__()`, `trans()`, `trans_choice()`, `@lang()`, `@choice()`
- **Facade:** `Lang::get()`, `Lang::choice()`, `Lang::has()`
- **Vue:** `$t()`, `i18n.t()`
- **Attributes:** `v-t`, `x-text`, `:v-t`, `:x-text`, `v-bind:v-t`, `v-bind:x-text`

Supports all quote types: single (`'`), double (`"`), and backtick (`` ` ``).

## 🌐 Internationalization Features

### Locale Support

- Automatic locale canonicalization (converts `en-US` to `en_US`)
- Script-aware formatting (title case for English, sentence case for other Latin languages)
- Proper handling for RTL, CJK, Brahmic, and Cyrillic scripts
- Placeholder preservation across all language families

### Translation Quality

- Placeholder mismatch detection to prevent runtime errors
- Pluralization string handling to maintain Laravel pluralization format
- Smart machine key humanization for better offline placeholders
- Context-aware translation via project-specific context option

## 🚀 Performance & Safety

### Concurrency Options

- **Fork Driver:** Parallel processing on Linux/macOS via `spatie/fork` (`pcntl`)
- **Process Driver:** Parallel processing on Windows via Symfony Process workers
- **Sync Driver:** Sequential processing on any OS — more stable, supports cooperative stop
- **Configurable Concurrency:** Control number of parallel processes
- **OS Routing:** `PHP_OS_FAMILY` selects Laravel Prompts + fork on Unix, or kernel32 FFI prompts + Process on Windows

### Safety Features

- **Atomic File Writes:** Temp files with atomic rename to prevent corruption
- **Path Validation:** Protection against directory traversal attacks
- **Memory Optimization:** Efficient chunk processing to minimize memory usage
- **Retry Logic:** Intelligent error handling with differentiated retry strategies

### Framework Integration

- Automatic Laravel framework translation bootstrapping
- Smart merging of vendor and app translations
- Only updates files when new keys are detected

## 🛡️ Security Features

- **Path Validation:** All file paths validated against base directory
- **Atomic Operations:** Temp file strategy prevents partial writes
- **Input Sanitization:** User inputs and context properly sanitized
- **Directory Traversal Prevention:** Strict path checking before file operations

## 🐛 Troubleshooting

### Rate Limits (Gemini free tier)

Google can change free-tier RPM/RPD, drop a model to 0, or introduce a higher-quota model at any time. The table below is a **snapshot as of 2026-08-13**, not an API contract.

Publish and edit `config/gemini-translator.php` when AI Studio disagrees with the snapshot:

```bash
php artisan vendor:publish --tag=gemini-translator-config
```

```php
// Add a new model, raise/lower a row, or retire one with 0/0
'gemini-4-flash-lite' => ['rpm' => 60, 'rpd' => 2000],
'gemini-2.5-flash' => ['rpm' => 0, 'rpd' => 0],
```

| Model | In ListModels | RPM | RPD |
| :--- | :---: | ---: | ---: |
| `gemini-3.5-flash-lite` (package default) | yes | 15 | 500 |
| `gemini-3.1-flash-lite` | yes | 15 | 500 |
| `gemini-2.5-flash-lite` | yes | 10 | 20 |
| `gemini-2.5-flash` | yes | 5 | 20 |
| `gemini-3.5-flash` | yes | 5 | 20 |
| `gemini-3.6-flash` | yes | 5 | 20 |

- Default `--concurrency=15` is capped to a **recorded** positive RPM unless you pass `--concurrency` or set `GEMINI_TRANSLATOR_APPLY_FREE_TIER_CAPS=false`
- A recorded `0` RPM/RPD is treated as “no free-tier budget” (sequential, with a warning) — not as a crash
- Unknown models are not invented; add them to the config when Google publishes numbers
- Paid keys: pass `--concurrency` explicitly, or turn caps off
- Use `--retry-delay` / `--max-retries` for 429 backoff

### Performance Tips

- Use `--driver=fork --concurrency=N` on Linux/macOS (pcntl) or Windows (Process) for best performance
- Adjust `--chunk-size` based on API limits (default: 25 keys per request)
- Increase `--concurrency` carefully to avoid hitting rate limits

### Common Issues

#### Configuration Error: "must be an integer, string given"

If you see this error:

```
Configuration value for key [gemini.request_timeout] must be an integer, string given.
```

**Fix:** Edit `config/gemini.php` and cast the timeout to integer:

```php
'request_timeout' => (int) env('GEMINI_REQUEST_TIMEOUT', 600),
```

#### Other Issues

- **Windows:** `--driver=fork` maps to Symfony Process workers with the same concurrency, result order, and “cannot stop mid-process” UX as Linux `pcntl` fork. Native menus (`ext-ffi`) match Laravel Prompts (boxed list, arrows, space, enter). Without FFI the command falls back to Symfony `choice()` / `confirm()`.
- **Large Projects:** Use smaller `--chunk-size` to avoid API timeouts
- **Module Projects:** Ensure `nwidart/laravel-modules` is properly configured
- **Empty Keys:** Package automatically filters empty/whitespace-only keys
- **Very Long Keys:** Automatic chunk size adjustment for keys >80 characters

### Debugging

- Use `--dry-run` to preview changes without writing
- Check `translation_extraction_log.json` for detailed code extraction
- Check `failed_translation_keys.json` for failed translations

## 🏢 Enterprise Features

### Module Support

- Full integration with `nwidart/laravel-modules`
- Ability to consolidate module translations to main app directory
- Independent module language file management
- Proper separation of application and module keys

### Production Ready

- Atomic file operations prevent corruption
- Comprehensive error handling and logging
- Dry-run mode for safe testing
- Configurable concurrency for server environments

### Quality Assurance

- Placeholder safety checking prevents runtime errors
- Multiple fallback chains for translation failures
- Cross-checking between languages
- Validation of translation quality

## 📜 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

⭐ **Star this repo** if you find it helpful! | 🐛 **Report issues** on GitHub | 📖 **Read full docs** at [Here](https://jayeshmepani.github.io/laravel-gemini-translator/)
