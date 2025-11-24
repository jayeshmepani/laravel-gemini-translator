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

# Model selection (optional)
GEMINI_MODEL=gemini-2.0-flash-exp

# Request timeout in seconds (optional)
GEMINI_REQUEST_TIMEOUT=600

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
```

---

## API Errors

### Rate Limiting

**Error:** `429 Too Many Requests`

**Solution:**
- Reduce concurrency: `--concurrency=5`
- Use sync mode: `--driver=sync`
- Wait a few minutes and retry

### Quota Exceeded

**Error:** `quota exceeded` or `limit exceeded`

**Solution:**
- Check your Google Cloud quota limits
- Enable billing on your Google Cloud project
- Request quota increase at https://aistudio.google.com

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
