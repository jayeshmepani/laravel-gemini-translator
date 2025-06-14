# Laravel Gemini AI Translation Extractor

An interactive Artisan command (`translations:extract-and-generate`) to scan your Laravel project for translation keys, translate them using Google's Gemini AI, and generate the necessary language files.

This tool is designed to dramatically speed up the localization process for both PHP (`/lang/en/messages.php`) and JSON (`/lang/en.json`) translation files.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![Total Downloads](https://img.shields.io/packagist/dt/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![License](https://img.shields.io/packagist/l/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)

## Features

- **Interactive Prompts:** Interactively select which translation files (`messages.php`, `validation.php`, etc.) and JSON key prefixes to process.
- **Powerful Key Extraction:** Scans Blade, PHP, Vue, JS, and TypeScript files for translation keys using precise regular expressions.
- **Intelligent Exclusions:** Automatically ignores `route()` and `config()` helpers to prevent false positives.
- **AI-Powered Translation:** Uses the Gemini AI API to provide high-quality translations for multiple languages at once.
- **Concurrency Support:** Uses `spatie/fork` for parallel processing, making API calls significantly faster.
- **Graceful Stop:** Stop the translation process at any time by pressing a key (defaults to 'q').
- **Detailed Logging:** Creates a `translation_extraction_log.json` file detailing every key and where it was found, plus a `failed_translation_keys.json` for any errors.

## Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- Google Gemini API key
- `pcntl` PHP extension is required for the high-performance `fork` driver.

## Installation

You can install the package via Composer:

```bash
composer require jayesh/laravel-gemini-translator
```

After installation, you need to publish the configuration file:

```bash
php artisan gemini:install
```

## Configuration

Next, add your Google Gemini API key and a request timeout to your `.env` file. You can get an API key from [Google AI Studio](https://makersuite.google.com/app/apikey).

```env
GEMINI_API_KEY=YOUR_GEMINI_API_KEY
GEMINI_REQUEST_TIMEOUT=600
```

## Usage

Once installed and configured, you can run the main command from your terminal:

```bash
php artisan translations:extract-and-generate
```

The command will guide you through the following steps:

1.  **Scanning:** It will scan your project for all translation keys.
2.  **File Selection:** It will ask you which translation files (e.g., `messages.php`, `auth.php`, Root JSON file) you want to process.
3.  **Translation:** It will send the keys to the Gemini API and show a progress bar.
4.  **File Generation:** It will write the translated keys to the correct language files in your `lang` directory, overwriting existing files.

### Command Options

You can customize the command's behavior with the following options:

#### Basic Options

-   `--source=.`: The root directory of the application to scan for keys (default: current directory).
-   `--target-dir=lang`: Root directory for final Laravel translation files (default: `lang`).
-   `--langs=en,ru,uz`: Comma-separated language codes to translate to (default: `en,ru,uz`).

#### Scanning Options

-   `--exclude=vendor,node_modules,...`: Comma-separated directories to exclude from scanning.
-   `--extensions=php,blade.php,vue,js,jsx,ts,tsx`: File extensions to search for translation keys.
-   `--custom-patterns=path/to/patterns.txt`: Path to a file with custom regex patterns.
-   `--no-advanced`: Disable advanced, context-based pattern detection.

#### Performance & Behavior Options

-   `--chunk-size=100`: Number of keys to send to Gemini in a single request (default: 100).
-   `--driver=default`: Concurrency driver. Options: `fork` (fastest, requires `pcntl`), `sync` (sequential, most stable), `async` (currently falls back to `sync`), `default` (currently falls back to `sync`).
-   `--skip-existing`: Skip keys that already have translations in all target languages.

#### Reliability Options

-   `--max-retries=5`: Maximum number of retries for failed API calls (default: 5).
-   `--retry-delay=3`: Base delay in seconds between retries with exponential backoff (default: 3).
-   `--stop-key=q`: The key to press to gracefully stop the translation process (default: `q`).

#### Example Usage

```bash
# Basic usage with custom languages
php artisan translations:extract-and-generate --langs=en,es,fr,de

# High-performance processing with fork driver and smaller chunks
php artisan translations:extract-and-generate --driver=fork --chunk-size=50

# Exclude additional directories and only scan Blade files
php artisan translations:extract-and-generate --exclude=vendor,tests,docs --extensions=blade.php

# Skip existing translations and use a different target directory
php artisan translations:extract-and-generate --skip-existing --target-dir=resources/lang
```

For a full list of options, run:

```bash
php artisan help translations:extract-and-generate
```

## Example Output

```
┌ Which translation files would you like to process? ───────────────────────────┐
│ ● messages.php                                                                │
│ ○ auth.php                                                                    │
│ ○ validation.php                                                              │
│ ● Root JSON file                                                              │
└───────────────────────────────────────────────────────────────────────────────┘

Press the 'q' key at any time to gracefully stop the process.
📊 Total keys to translate: 156
📦 Total chunks to process: 2
🚀 156/156 [============================] 100% -- ✅ Chunk 2/2 - SUCCESS (56 keys) ⏱️  18s

💾 Phase 3: Writing Language Files
 ✅ Wrote: lang/es.json (78 keys)
 ✅ Wrote: lang/es/messages.php (78 keys)
 ✅ Wrote: lang/fr.json (78 keys)
 ✅ Wrote: lang/fr/messages.php (78 keys)
...
```

## File Structure

The command intelligently separates keys. Keys with a group prefix (e.g., `messages.welcome`) are placed in the corresponding PHP file (`messages.php`). Keys without a group (e.g., `'Welcome'`) are placed in the root JSON file for that language (`es.json`).

```
lang/
├── en/
│   ├── messages.php
│   ├── auth.php
│   └── validation.php
├── es/
│   ├── messages.php
│   ├── auth.php
│   └── validation.php
├── fr/
│   ├── messages.php
│   ├── auth.php
│   └── validation.php
├── en.json
├── es.json
└── fr.json
```

## Supported File Types

The package scans the following file types for translation keys by default:

-   Blade templates (`.blade.php`)
-   PHP files (`.php`)
-   Vue components (`.vue`)
-   JavaScript (`.js`, `.jsx`)
-   TypeScript (`.ts`, `.tsx`)

## Translation Key Patterns

The package uses sophisticated pattern matching to detect translation keys.

### Standard Laravel Functions

-   `__('key')`
-   `trans('key')`
-   `trans_choice('key', $count)`
-   `@lang('key')`
-   `@choice('key', $count)`
-   `Lang::get('key')`
-   `Lang::choice('key', $count)`
-   `Lang::has('key')`

### Vue.js/JavaScript Functions

-   `$t('key')`
-   `i18n.t('key')`

### HTML Attributes

-   `v-t="'key'"`
-   `x-text="'key'"`

### Advanced Context Detection

When advanced pattern detection is enabled (default), the package also detects quoted strings that look like translation keys, such as `"messages.welcome_user"` or `"auth.failed"`.

### Intelligent Exclusions

The package automatically ignores strings inside `route()` and `config()` function calls to prevent false positives.

### Custom Patterns

You can define your own regular expression patterns by creating a text file and passing its path to the `--custom-patterns` option. The format for each line should be: `REGEX_PATTERN|DESCRIPTION|CAPTURE_GROUP_NUMBER`.

Example `my-patterns.txt` file:
```
t\(['"]([^'"]+)['"]\)|Custom t() function|1
translate\(['"]([^'"]+)['"]\)|Custom translate() function|1
```

## Troubleshooting

### Common Issues

**API Key Issues:**
-   Ensure your `GEMINI_API_KEY` is set correctly in your `.env` file.
-   Verify your API key is active and has sufficient quota.

**Performance Issues:**
-   Use the `--driver=fork` option for the fastest processing. This requires the `pcntl` PHP extension to be installed and enabled.
-   If `fork` is unavailable, the command will run sequentially (`sync`), which is slower but more compatible.
-   Increase `GEMINI_REQUEST_TIMEOUT` in your `.env` file if you are translating very large chunks of text.

**Memory Issues:**
-   For very large projects, consider processing files in smaller batches by selecting them one by one in the interactive prompt.
-   Reduce the `--chunk-size` to send fewer keys per API request.

## Security Vulnerabilities

If you discover a security vulnerability within this package, please send an e-mail to the maintainer. All security vulnerabilities will be promptly addressed.

## Acknowledgments

This package would not be possible without the excellent work of the following open-source projects:

-   [google-gemini-php/laravel](https://github.com/google-gemini-php/laravel): For seamless integration with the Gemini AI API.
-   [spatie/fork](https://github.com/spatie/fork): For enabling high-performance, parallel task processing.
-   [Laravel Prompts](https://github.com/laravel/prompts): For the beautiful and user-friendly interactive prompts.
-   [Symfony Finder](https://github.com/symfony/finder): For powerful and flexible file system scanning.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.