# Laravel Gemini AI Translation Extractor

An interactive Artisan command that scans your Laravel project for translation keys, translates them using Google's Gemini AI, and generates the necessary language files.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![Total Downloads](https://img.shields.io/packagist/dt/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)
[![License](https://img.shields.io/packagist/l/jayesh/laravel-gemini-translator.svg?style=flat-square)](https://packagist.org/packages/jayesh/laravel-gemini-translator)

## 🚀 Key Features

- **Interactive & Cross-Platform:** Works on all operating systems with robust fallback
- **AI-Powered Translation:** Uses Gemini AI for high-quality translations
- **Flexible Concurrency:** Fork driver for Linux/macOS, sync driver for Windows
- **Smart Key Detection:** Scans Blade, PHP, Vue, JS, and TypeScript files
- **Intelligent Processing:** Ignores `route()` and `config()` helpers

## 📋 Requirements

- PHP 8.1 or higher
- Laravel 10.0 or higher
- Google Gemini API key
- `pcntl` extension (for fork driver on Linux/macOS)

## ⚡ Quick Start

### 1. Installation

```bash
composer require jayesh/laravel-gemini-translator
php artisan gemini:install
```

### 2. Configuration

Add to your `.env`:

```env
GEMINI_API_KEY="YOUR_GEMINI_API_KEY"
GEMINI_REQUEST_TIMEOUT=600
```

Get your API key from [Google AI Studio](https://makersuite.google.com/app/apikey).

### 3. Usage

```bash
# Linux/macOS (fastest)
php artisan translations:extract-and-generate --driver=fork

# Windows (stable)
php artisan translations:extract-and-generate --driver=sync

# Basic usage
php artisan translations:extract-and-generate
```

## 📖 Documentation

For detailed documentation, step-by-step guides, and advanced usage examples, visit our comprehensive documentation:

**[📚 Full Documentation](https://your-username.github.io/laravel-gemini-translator/)**

## 🔧 Common Options

```bash
# Custom languages
php artisan translations:extract-and-generate --langs=en,es,fr,de

# Skip existing translations
php artisan translations:extract-and-generate --skip-existing

# Custom chunk size for API requests
php artisan translations:extract-and-generate --chunk-size=50

# Get help
php artisan help translations:extract-and-generate
```

## 🏗️ File Structure

```
lang/
├── en/messages.php     # Grouped keys (messages.*)
├── es/messages.php
├── fr/messages.php
├── en.json            # Ungrouped keys
├── es.json
└── fr.json
```

## 🛠️ Supported Files & Functions

- **Files:** `.blade.php`, `.php`, `.vue`, `.js`, `.jsx`, `.ts`, `.tsx`
- **Functions:** `__()`, `trans()`, `@lang()`, `$t()`, `i18n.t()`

## 🐛 Issues & Support

- **Rate Limits:** Free tier has 15 RPM / 1000 daily requests
- **Performance:** Use `--driver=fork` on Linux/macOS for best performance
- **Help:** Run `php artisan help translations:extract-and-generate`

## 📜 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

---

⭐ **Star this repo** if you find it helpful! | 🐛 **Report issues** on GitHub | 📖 **Read full docs** at [Here](https://jayeshmepani.github.io/laravel-gemini-translator/)