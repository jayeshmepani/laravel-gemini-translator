# Common Issues & Solutions

## Translation Failures

### Issue: All translations fail with configuration error

**Symptom:**
```
Error: Configuration value for key [gemini.request_timeout] must be an integer, string given.
```

**Cause:**
The `config/gemini.php` file has a type mismatch. The `env()` function returns strings, but the Gemini client requires an integer.

**Solution:**
Edit your `config/gemini.php`:

```php
// ❌ WRONG - env() returns string
'request_timeout' => env('GEMINI_REQUEST_TIMEOUT', 600),

// ✅ CORRECT - Cast to integer
'request_timeout' => (int) env('GEMINI_REQUEST_TIMEOUT', 600),
```

**Prevention:**
Always cast environment variables to their expected types:
- Integer: `(int) env('KEY', default)`
- Boolean: `(bool) env('KEY', default)`
- Float: `(float) env('KEY', default)`

---

### Issue: Empty keys causing "Syntax error"

**Symptom:**
```json
{
    "__MAIN__::ai_assessment": [""]
}
```

**Cause:**
Translation files contain empty string keys (`'' => 'value'` or `"" => 'value'`).

**Solution:**
Fixed in v4.0.1+ with automatic empty key filtering. For older versions, manually remove empty keys from your language files.

---

### Issue: Very long attribute keys failing

**Symptom:**
```
attributes.user_course_schedule_item_configuration_preferences_options_parameters
```

Keys with 80-100+ characters fail to translate.

**Solution:**
Fixed in v4.0.1+ with intelligent chunk size adjustment. The package automatically reduces chunk size for long keys.

Manual override:
```bash
php artisan translations:extract-and-generate --chunk-size=3
```

---

## Environment Configuration

### Recommended `.env` settings

```env
# Gemini API Key (required)
GEMINI_API_KEY=your-api-key-here

# Model selection (optional). Default in 5.1.0: gemini-3.5-flash-lite
GEMINI_MODEL=gemini-3.5-flash-lite
# or: GEMINI_TRANSLATOR_MODEL=gemini-3.5-flash-lite

# Request timeout in seconds (optional)
GEMINI_REQUEST_TIMEOUT=600

# Translation Manager (optional)
# GEMINI_TRANSLATOR_MANAGER=true
# GEMINI_TRANSLATOR_MANAGER_PREFIX=translations-manager
# GEMINI_TRANSLATOR_APPLY_FREE_TIER_CAPS=true

# Base URL (optional, only if using proxy)
# GEMINI_BASE_URL=https://your-proxy.com
```

### config/gemini.php template

```php
<?php

return [
    'api_key' => env('GEMINI_API_KEY'),
    'base_url' => env('GEMINI_BASE_URL'),
    
    // ⚠️ IMPORTANT: Cast to integer!
    'request_timeout' => (int) env('GEMINI_REQUEST_TIMEOUT', 600),
];
```

---

## Performance Tips

### Optimize chunk size based on your keys

```bash
# For short keys (< 30 chars) - use larger chunks
--chunk-size=25

# For medium keys (30-60 chars) - default is fine
--chunk-size=10

# For long keys (60-100+ chars) - use smaller chunks
--chunk-size=3

# For debugging - process one at a time
--chunk-size=1
```

---

## 5.1.0 notes

- **Translation Manager:** open `/translations-manager`. If the app has a login route, sign in first. If a published Blade looks stale (missing Pack, theme, or **Highlight script faults**), overwrite it or remove it so the package views load:

```bash
php artisan vendor:publish --tag=gemini-translator-manager --force
php artisan view:clear

# There is no unpublish command:
rm -rf resources/views/vendor/gemini-translator
php artisan view:clear
```

- **Cannot write lang/:** the error names the PHP process user when it can (`nginx`, `apache`, `http`, your login user, or `www-data` — not always `www-data`). Example: `sudo chown -R USER:USER lang && sudo chmod -R u+w lang`. Apply the same to module `lang/` trees if needed.
- **AI output is a draft:** the package tries to produce the language you asked for. Gemini can still mix scripts, include another language, or return wording that is not literal or expected (for example Gujarati mixed with Hindi, Kannada, or English). The writing-system guard **reduces** those cases; it does not eliminate them, and it does not catch meaning or tone.
- **Script faults in the manager:** turn on **Highlight script faults**. Amber cells have letters that do not belong to that locale (for example Devanagari or Latin inside `gu`). Placeholders are ignored. The toggle is stored as `gemini-translator-malform-detector`. This does not rewrite files; it only marks editors. Use `--refresh` / `--refresh-clean` to regenerate bad Gemini output.
- **Packs:** extra folders such as `lang/app3/` and `lang/web/` are a separate CLI prompt after you pick a module. The manager Pack filter appears only when that module (or non-module) has more than `lang/`.
- **`--refresh` vs `--refresh-clean`:** `--refresh` uses the current file text. `--refresh-clean` ignores that text. Do not combine either with `--skip-existing`.
- **Windows:** `--driver=fork` runs Symfony Process workers. Native menus need `ext-ffi`. Without FFI the command uses Symfony `choice()` / `confirm()`.

### Concurrency settings

```bash
# Maximum speed (if you have many short keys)
--driver=fork --concurrency=20

# Balanced (recommended)
--driver=fork --concurrency=10

# Conservative (avoid rate limits)
--driver=fork --concurrency=5

# Debug mode (sequential processing)
--driver=sync

# Windows parallel (Symfony Process workers)
--driver=fork --concurrency=10
# or explicitly:
--driver=process --concurrency=10
```

---

## API Errors

### Rate Limiting

**Error:** `429 Too Many Requests`

**Solution:**
- Match `--concurrency` to the model's free-tier RPM (see table below)
- Use sync mode: `--driver=sync`
- Wait a minute and retry; 429s already use exponential backoff

#### Free-tier request limits (snapshot, not a constant)

Google can change these at any time. The package ships a snapshot dated **2026-08-13** in `config/gemini-translator.php`. Publish that file and edit `quotas.models` when limits move, a model goes to 0, or a new model appears:

```bash
php artisan vendor:publish --tag=gemini-translator-config
```

| Model | RPM | RPD |
| :--- | ---: | ---: |
| `gemini-3.5-flash-lite` (package default) | 15 | 500 |
| `gemini-3.1-flash-lite` | 15 | 500 |
| `gemini-2.5-flash-lite` | 10 | 20 |
| `gemini-2.5-flash` | 5 | 20 |
| `gemini-3.5-flash` | 5 | 20 |
| `gemini-3.6-flash` | 5 | 20 |

Default `--concurrency` is capped only to a **recorded** positive RPM. `0` RPM/RPD means “no free-tier budget” in the snapshot. Unknown models are left uncapped until you add them. Set `GEMINI_TRANSLATOR_APPLY_FREE_TIER_CAPS=false` to disable capping.

### Quota Exceeded

**Error:** `quota exceeded` or `limit exceeded`

**Solution:**
- Check your Google Cloud quota limits
- Enable billing on your Google Cloud project
- Request quota increase at https://aistudio.google.com

### Gemini mixed languages or wrote unexpected wording

**Symptom:** You asked for Gujarati (`gu`) and the file contains Hindi or Kannada letters, leftover English, or a translation that is not literal / not what you wanted.

**Cause:** Gemini is a generative model. The package sends script rules and a writing-system check that **reduces** mixed-script or leftover-English output. That is not a complete fix; mixed or off-language rows can still be written. The CLI `hasDisallowedScript()` check still allows Latin tokens in native-script locales. The manager highlighter is stricter and flags leftover Latin in Gujarati, Hindi, Arabic, and similar locales. Neither check judges meaning or tone.

**Solution:** Treat generated files as a draft. Turn on **Highlight script faults** in `/translations-manager` while you review. Re-run `--refresh` (from current file wording) or `--refresh-clean` (from the key) for the affected language. Pass `--context` if your product uses domain terms. Leave brand names if they are intentional.

### Manager shows mixed scripts but the CLI accepted them

**Cause:** The CLI `hasDisallowedScript()` check still allows Latin tokens in native-script locales. The manager highlighter is stricter and flags leftover Latin in Gujarati, Hindi, Arabic, and similar locales.

**Solution:** Keep the highlighter on while reviewing. Re-run `--refresh-clean` for that language if the Latin is leftover English, or leave brand names if they are intentional.

---

### Authentication Failed

**Error:** `401 Unauthorized` or `invalid API key`

**Solution:**
- Verify `GEMINI_API_KEY` in `.env`
- Regenerate API key at https://aistudio.google.com/app/apikey
- Clear config cache: `php artisan config:clear`

---

## Debugging

### Enable verbose logging

```bash
# Use sync mode for detailed error messages
php artisan translations:extract-and-generate \
    --driver=sync \
    --chunk-size=1
```

### Check Laravel logs

```bash
tail -f storage/logs/laravel.log | grep -i "gemini\|translation"
```

### Test Gemini connectivity

```bash
php artisan tinker
```

```php
use Gemini\Laravel\Facades\Gemini;
use Gemini\Data\Content;

$response = Gemini::geminiFlash()
    ->generateContent(Content::parse('Hello, world!'));
    
echo $response->text();
```

---

## After Installation Checklist

1. ✅ Published config has integer cast: `(int) env('GEMINI_REQUEST_TIMEOUT', 600)`
2. ✅ API key is set in `.env`: `GEMINI_API_KEY=your-key`
3. ✅ Config cache is cleared: `php artisan config:clear`
4. ✅ Gemini connectivity tested in tinker
5. ✅ First translation run completed successfully

---

## Success!

When everything is configured correctly, you should see:

```
✅ All selected keys are fully translated!
Success Rate: 100%
```

For more help, check the [README](../README.md) or open an issue on GitHub.
