# Laravel Gemini AI Translation Extractor

An interactive Artisan command (`translations:extract-and-generate`) to scan your Laravel project for translation keys, translate them using Google's Gemini AI, and generate the necessary language files.

This tool is designed to dramatically speed up the localization process for both PHP (`/lang/en/messages.php`) and JSON (`/lang/en.json`) translation files.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![Total Downloads](https://img.shields.io/packagist/dt/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![License](https://img.shields.io/packagist/l/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)

## Features

- **Interactive Prompts:** Interactively select which translation files (`messages.php`, `validation.php`, etc.) and JSON key prefixes to process
- **Powerful Key Extraction:** Scans Blade, PHP, Vue, and JS files for translation keys using precise regular expressions
- **Intelligent Exclusions:** Automatically ignores `route()` and `config()` helpers to prevent false positives
- **AI-Powered Translation:** Uses the Gemini AI API to provide high-quality translations for multiple languages at once
- **Concurrency Support:** Uses `spatie/fork` for parallel processing, making API calls significantly faster
- **Detailed Logging:** Creates a `translation_extraction_log.json` file detailing every key and where it was found

## Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- Google Gemini API key

## Installation

You can install the package via Composer:

```bash
composer require jayesh/laravel-gemini-translator
```

The package uses Laravel's auto-discovery to register its service provider. A post-install script will attempt to run `php artisan gemini:install` for you, which publishes the `config/gemini.php` file.

If this fails for any reason, you can run it manually:

```bash
php artisan vendor:publish --provider="Gemini\Laravel\GeminiServiceProvider"
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

1. **Scanning:** It will scan your project for all translation keys
2. **File Selection:** It will ask you which translation files (e.g., `messages.php`, `auth.php`, Root JSON file) you want to process
3. **Translation:** It will send the keys to the Gemini API and show a progress bar
4. **File Generation:** It will write the translated keys to the correct language files in your `lang` directory, overwriting existing files

### Command Options

You can customize the command's behavior with the following options:

- `--langs=en,es,fr`: Specify the comma-separated language codes to translate to
- `--driver=fork`: Use the high-performance fork driver for concurrency (requires the `pcntl` PHP extension)
- `--skip-existing`: Prevent the command from re-translating keys that already exist in all target languages

For a full list of options, run:

```bash
php artisan help translations:extract-and-generate
```

## Example Output

```
┌ Which translation files would you like to process? ┐
│ ● messages.php                                     │
│ ○ auth.php                                         │
│ ○ validation.php                                   │
│ ● Root JSON file                                   │
└────────────────────────────────────────────────────┘

Extracting translation keys...
Found 156 unique translation keys

Translating to: Spanish, French, German
 ████████████████████████████████████████ 100%

Translation completed successfully!
Generated files:
- lang/es/messages.php (78 keys)
- lang/fr/messages.php (78 keys)
- lang/de/messages.php (78 keys)
- lang/es.json (78 keys)
- lang/fr.json (78 keys)
- lang/de.json (78 keys)
```

## File Structure

After running the command, your language files will be organized as follows:

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

The package scans the following file types for translation keys:

- **Blade templates** (`.blade.php`)
- **PHP files** (`.php`)
- **Vue components** (`.vue`)
- **JavaScript files** (`.js`)

## Translation Key Patterns

The following translation key patterns are detected:

- `__('key')`
- `@lang('key')`
- `trans('key')`
- `$t('key')` (Vue.js)
- `this.$t('key')` (Vue.js)

## Troubleshooting

### Common Issues

**API Key Issues:**
- Ensure your `GEMINI_API_KEY` is set correctly in your `.env` file
- Verify your API key is active and has sufficient quota

**Performance Issues:**
- Use the `--driver=fork` option for faster processing (requires `pcntl` extension)
- Increase `GEMINI_REQUEST_TIMEOUT` for large translation batches

**Memory Issues:**
- For very large projects, consider processing files in smaller batches
- Increase PHP memory limit if needed

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Contributions are welcome! Please feel free to submit a pull request or create an issue for any bugs or feature requests.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Security Vulnerabilities

If you discover a security vulnerability within this package, please send an e-mail to the maintainer. All security vulnerabilities will be promptly addressed.

## Credits

- [Jayesh Patel](https://github.com/jayesh)
- [All Contributors](../../contributors)

## Acknowledgments

This package would not be possible without the excellent work of the following open-source projects:

- [google-gemini-php/laravel](https://github.com/google-gemini-php/laravel): For seamless integration with the Gemini AI API
- [spatie/fork](https://github.com/spatie/fork): For enabling high-performance, parallel task processing
- [Laravel Prompts](https://github.com/laravel/prompts): For the beautiful and user-friendly interactive prompts
- [Symfony Finder](https://github.com/symfony/finder): For powerful and flexible file system scanning

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.