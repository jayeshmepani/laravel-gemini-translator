# Laravel Gemini AI Translation Extractor

An interactive Artisan command that scans your Laravel project for translation keys, translates them using Google's Gemini AI, and generates the necessary language files with advanced safety and performance features.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![Total Downloads](https://img.shields.io/packagist/dt/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![License](https://img.shields.io/packagist/l/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)

## 🚀 Key Features

- **AI-Powered Translation:** Uses Gemini AI for high-quality translations with context awareness
- **Interactive & Cross-Platform:** Works on all operating systems with robust fallback
- **Flexible Concurrency:** Fork driver for Linux/macOS, sync driver for Windows  
- **Smart Key Detection:** Scans Blade, PHP, Vue, JS, and TypeScript files comprehensively
- **Framework Integration:** Automatic Laravel framework translation bootstrapping
- **Three Operational Modes:** Full sync, missing-only (`--skip-existing`), refresh-only (`--refresh`)
- **Production-Ready Safety:** Atomic file writes, path validation, and security checks
- **Module Support:** Full integration with `nwidart/laravel-modules` with consolidation options

## 📋 Requirements

- PHP 8.2 or higher
- Laravel 11.0 or higher
- Google Gemini API key
- `pcntl` extension (for fork driver on Linux/macOS)
- `tokenizer` PHP extension (for proper code parsing)

## ⚡ Quick Start

### 1. Installation

```bash
composer require jayesh/laravel-gemini-translator
php artisan vendor:publish --provider="Jayesh\LaravelGeminiTranslator\TranslationServiceProvider"
```

### 2. Configuration

Add to your `.env`:

```env
GEMINI_API_KEY="YOUR_GEMINI_API_KEY"
GEMINI_REQUEST_TIMEOUT=600
```

Get your API key from [Google AI Studio](https://makersuite.google.com/app/apikey).

### 3. Basic Usage

```bash
# Linux/macOS (fastest with configurable concurrency)
php artisan translations:extract-and-generate --driver=fork --concurrency=10

# Windows (stable)
php artisan translations:extract-and-generate --driver=sync

# Preview changes without writing files
php artisan translations:extract-and-generate --dry-run

# Refresh only existing translations (re-translate existing keys only)
php artisan translations:extract-and-generate --refresh

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
# Concurrency driver (default, fork, sync)
php artisan translations:extract-and-generate --driver=fork

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
- `--refresh` and `--skip-existing` are mutually exclusive (the command will fail if both are used)
- `--dry-run` works with all other options to preview changes
- `--concurrency` only affects fork driver

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
- **Fork Driver:** Parallel processing (Linux/macOS) with configurable processes
- **Sync Driver:** Sequential processing (Windows/Linux) - more stable
- **Configurable Concurrency:** Control number of parallel processes

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

### Rate Limits
- Free tier has 15 RPM / 1000 daily requests
- Use `--retry-delay` and `--max-retries` for better rate limit handling
- Upgrade to Gemini Pro for higher quotas

### Performance Tips
- Use `--driver=fork --concurrency=N` on Linux/macOS for best performance
- Adjust `--chunk-size` based on API limits (default: 25 keys per request)
- Increase `--concurrency` carefully to avoid hitting rate limits

### Common Issues
- **Windows:** Use `--driver=sync` instead of fork for stability
- **Large Projects:** Use smaller `--chunk-size` to avoid API timeouts
- **Module Projects:** Ensure `nwidart/laravel-modules` is properly configured

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