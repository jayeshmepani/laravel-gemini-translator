# Laravel Gemini AI Translation Extractor

An interactive Artisan command (`translations:extract-and-generate`) that scans your Laravel project for translation keys, translates them using Google's Gemini AI, and generates the necessary language files.

This tool is designed to dramatically speed up the localization process for both standard PHP array files (`/lang/en/messages.php`) and flat JSON files (`/lang/en.json`).

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![Total Downloads](https://img.shields.io/packagist/dt/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![License](https://img.shields.io/packagist/l/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)

## Key Features

- **Interactive & Cross-Platform:** User-friendly prompts guide you through file selection. Works on all operating systems with a robust fallback for the Windows CLI.
- **Powerful Key Extraction:** Scans Blade, PHP, Vue, JS, and TypeScript files for translation keys using precise regular expressions.
- **Intelligent & Stable:** Automatically ignores `route()` and `config()` helpers to prevent false positives and sanitizes keys to prevent hangs from malformed input.
- **AI-Powered Translation:** Uses the Gemini AI API to provide high-quality translations for multiple languages at once.
- **Flexible Concurrency:**
  - **Linux/macOS:** Use the high-performance `fork` driver for lightning-fast parallel processing.
  - **Windows:** Automatically uses the stable, sequential `sync` driver for maximum compatibility.
- **Graceful Stop:** Stop the translation process at any time by pressing a key (defaults to 'q').
- **Detailed Logging:** Creates a `translation_extraction_log.json` file detailing every key and where it was found, plus a `failed_translation_keys.json` for any API errors.
- **Correct File Generation:** Intelligently creates nested PHP arrays for `lang/en/messages.php` and flat key-value pairs for `lang/en.json` as per Laravel conventions.

## Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- A Google Gemini API key
- The `pcntl` PHP extension is **required** for the high-performance `fork` driver (typically available on Linux/macOS).

## Installation

You can install the package via Composer:

```bash
composer require jayesh/laravel-gemini-translator
```

After installation, you can use the install command:

```bash
php artisan gemini:install
```

This will create a `config/gemini.php` file.

## Configuration

Next, add your Google Gemini API key to your `.env` file. You can get a free API key from [Google AI Studio](https://makersuite.google.com/app/apikey).

```env
GEMINI_API_KEY="YOUR_GEMINI_API_KEY"
GEMINI_REQUEST_TIMEOUT=600
```

### Gemini API Model & Limits

This package uses the **Gemini 2.0 Flash-Lite** model (or a similar high-quality, efficient Gemini model) by default. If you wish to change it, you can modify the `model` key in the `config/gemini.php` file.

**Important Considerations for Free Tier Limits:**
- **Rate Limit**: 30 requests per minute (RPM)
- **Daily Limit**: 1,500 requests per day
- Large projects may exceed API rate limits. Consider using the `--chunk-size` option to control the number of keys sent per request.
- The `--driver=fork` option processes multiple requests concurrently, which can help you reach rate limits faster but complete translations more efficiently.
- If you hit rate limits, the command will automatically retry with exponential backoff (configurable with `--max-retries` and `--retry-delay` options).

## Usage

Once installed and configured, run the main command from your terminal:

```bash
# On Linux/macOS for fastest performance
php artisan translations:extract-and-generate --driver=fork

# On Windows (or for maximum stability on any OS)
php artisan translations:extract-and-generate --driver=sync

# Basic usage with default settings
php artisan translations:extract-and-generate
```

The command will guide you through the following steps:

1. **Scanning:** It scans your project for all translation keys.
2. **File Selection:** It interactively asks which translation files (e.g., `messages.php`, `auth.php`, Root JSON file) you want to process.
3. **Translation:** It sends the keys to the Gemini API and shows real-time progress.
4. **File Generation:** It writes the translated keys to the correct language files in your `lang` directory.

### Command Options

You can customize the command's behavior with the following options:

#### Basic Options

- `--source=.`: The root directory to scan for keys (default: current directory).
- `--target-dir=lang`: Root directory for final translation files (default: `lang`).
- `--langs=en,es,fr`: Comma-separated language codes to translate to (default: `en,ru,uz`).

#### Scanning Options

- `--exclude=vendor,node_modules,...`: Comma-separated directories to exclude from scanning.
- `--extensions=php,blade.php,vue,js,jsx,ts,tsx`: File extensions to search for translation keys.
- `--custom-patterns=path/to/patterns.txt`: Path to a file with custom regex patterns.
- `--no-advanced`: Disable advanced, context-based pattern detection.

#### Performance & Behavior Options

- `--chunk-size=100`: Number of keys to send to Gemini in a single request (default: 100).
- `--driver=default`: Concurrency driver.
  - `fork`: (Recommended on Linux/macOS) Fastest, parallel processing. Requires `pcntl` extension.
  - `sync`: (Default & Recommended on Windows) Sequential, most stable.
  - `default`: (Currently falls back to `sync`)
- `--skip-existing`: Only translates keys that are missing in one or more target language files.

#### Reliability Options

- `--max-retries=5`: Maximum number of retries for a failed API call (default: 5).
- `--retry-delay=3`: Base delay in seconds between retries (uses exponential backoff) (default: 3).
- `--stop-key=q`: The key to press to gracefully stop the translation process (default: `q`).

#### Output Control Options

- `-h, --help`: Display help for the given command.
- `--silent`: Do not output any message.
- `-q, --quiet`: Only errors are displayed. All other output is suppressed.
- `-V, --version`: Display this application version.
- `--ansi|--no-ansi`: Force (or disable --no-ansi) ANSI output.
- `--no-interaction`: Do not ask any interactive question.
- `--env[=ENV]`: The environment the command should run under.
- `-v|vv|vvv, --verbose`: Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug.

For a complete and detailed list of every option, run the help command:

```bash
php artisan help translations:extract-and-generate
```

### Example Usage

```bash
# Basic usage with custom languages, letting the tool pick the best driver
php artisan translations:extract-and-generate --langs=en,es,fr,de

# High-performance processing with fork driver and smaller chunks
php artisan translations:extract-and-generate --driver=fork --chunk-size=50

# Exclude additional directories and only scan Blade files
php artisan translations:extract-and-generate --exclude=vendor,tests,docs --extensions=blade.php

# Skip existing translations and use a different target directory
php artisan translations:extract-and-generate --skip-existing --target-dir=resources/lang

# Use custom patterns and stop key
php artisan translations:extract-and-generate --custom-patterns=my-patterns.txt --stop-key=s

# Get help with all available options
php artisan help translations:extract-and-generate
```

## Example Output

### `fork` driver (Linux/macOS)

The `fork` driver uses a real-time progress bar for an aggregated view of all parallel tasks.

```
┌ Which translation files would you like to process? ────────────────┐
 ● messages.php                                                                
 ○ auth.php                                                                    
 ● Root JSON file                                                              
└────────────────────────────────────────────────────────────┘

Press the 'q' key at any time to gracefully stop the process.
⚡ Using 'fork' driver for high-performance concurrency.
📊 Total keys to translate: 156
📦 Total chunks to process: 2
🚀 156/156 [============================] 100% -- ✅ Chunk 2/2 - SUCCESS (156 keys) ⏱️  18s

╔═ 💾 Phase 3: Writing Language Files ═════════════════════════════╗

 ✅ Wrote: lang/es.json (78 total keys)
 ✅ Wrote: lang/es/messages.php (78 total keys)
 ✅ Wrote: lang/fr.json (78 total keys)
 ✅ Wrote: lang/fr/messages.php (78 total keys)
...
```

### `sync` driver (Windows & Stable Mode)

The `sync` driver provides clear, step-by-step textual feedback for each chunk.

```
┌ Which translation files would you like to process? ───────────────────────────┐
 ● Root JSON file                                                              
└───────────────────────────────────────────────────────────────────────┘

 🐌 Running in synchronous mode - this will be slower but more stable!

Processing file: __JSON__
  -> Processing keys 1-100 of 222... ✓ Done
  -> Processing keys 101-200 of 222... ✓ Done
  -> Processing keys 201-222 of 222... ✗ Failed
     Error: Gemini API Error...

╔═ 💾 Phase 3: Writing Language Files ═══════════════════════════════════════╗

  ✅ Wrote: lang/es.json (222 total keys)
  ✅ Wrote: lang/fr.json (222 total keys)
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

## Supported File Types & Key Patterns

The package uses sophisticated pattern matching to detect translation keys across your entire frontend and backend codebase.

### Supported File Types

The package scans the following file types for translation keys by default:

- Blade templates (`.blade.php`)
- PHP files (`.php`)
- Vue components (`.vue`)
- JavaScript (`.js`, `.jsx`)
- TypeScript (`.ts`, `.tsx`)

### Supported Functions

- `__('key')`, `trans('key')`, `trans_choice('key', $count)`
- `@lang('key')`, `@choice('key', $count)`
- `Lang::get('key')`, `Lang::has('key')`, `Lang::choice('key', $count)`
- `$t('key')` (Vue.js/i18n)
- `i18n.t('key')`

### Supported HTML Attributes

- `v-t="'key'"`
- `x-text="'key'"`

### Advanced Context Detection & Exclusions

When advanced pattern detection is enabled (default), the package also detects quoted strings that look like translation keys (e.g., `"messages.welcome_user"`) while intelligently ignoring strings inside `route()` and `config()` function calls to prevent false positives.

### Custom Patterns

You can define your own regular expression patterns by creating a text file and passing its path to the `--custom-patterns` option. The format for each line should be: `REGEX_PATTERN|DESCRIPTION|CAPTURE_GROUP_NUMBER`.

Example `my-patterns.txt` file:
```
t\(['"]([^'"]+)['"]\)|Custom t() function|1
translate\(['"]([^'"]+)['"]\)|Custom translate() function|1
```

## Getting Help

For detailed information about all available command options and their usage, run:

```bash
php artisan help translations:extract-and-generate
```

This will display comprehensive help information including:
- Command description
- Usage syntax
- All available options with descriptions
- Default values for each option
- Verbosity levels and output control options

## Troubleshooting

### Common Issues

**API Key Issues:**
- Ensure your `GEMINI_API_KEY` is set correctly in your `.env` file.
- Verify your API key is active and has sufficient quota.

**Rate Limiting Issues:**
- The free tier of Gemini has limits of 30 RPM and 1,500 requests per day.
- If you encounter rate limiting errors, try reducing the `--chunk-size` to send fewer keys per request.
- Increase `--retry-delay` to add more time between retries.
- For large projects, consider processing translations in multiple sessions to stay within daily limits.

**Performance Issues:**
- Use the `--driver=fork` option for the fastest processing. This requires the `pcntl` PHP extension to be installed and enabled.
- If `fork` is unavailable, the command will run sequentially (`sync`), which is slower but more compatible.
- For the fastest processing on Linux/macOS, ensure the `pcntl` PHP extension is installed and use `--driver=fork`. Windows users (or users on systems without `pcntl`, like Windows with Laragon/XAMPP) should use the reliable `--driver=sync`. WSL (Windows Subsystem for Linux) is an excellent option for Windows users who want to use the `fork` driver.
- Increase `GEMINI_REQUEST_TIMEOUT` in your `.env` file if you are translating very large chunks of text.

**Memory Issues:**
- For very large projects, consider processing files in smaller batches by selecting them one by one in the interactive prompt.
- Reduce the `--chunk-size` to send fewer keys per API request.

**Command Help:**
- If you're unsure about any option or need to see all available parameters, use the help command:
  ```bash
  php artisan help translations:extract-and-generate
  ```

## Security Vulnerabilities

If you discover a security vulnerability within this package, please send an e-mail to the maintainer. All security vulnerabilities will be promptly addressed.

## Acknowledgments

This package would not be possible without the excellent work of the following open-source projects:

- [google-gemini-php/laravel](https://github.com/google-gemini-php/laravel): For seamless integration with the Gemini AI API.
- [spatie/fork](https://github.com/spatie/fork): For enabling high-performance, parallel task processing.
- [Laravel Prompts](https://github.com/laravel/prompts): For the beautiful and user-friendly interactive prompts.
- [Symfony Finder](https://github.com/symfony/finder): For powerful and flexible file system scanning.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
