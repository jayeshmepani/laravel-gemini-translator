<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tasks;

use Illuminate\Support\Facades\Log;
use Jayesh\LaravelGeminiTranslator\Services\TranslationService;
use Throwable;

/** In-process and worker-process entry point for a single translation chunk. */
final class TranslationChunkHandler
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function handle(array $payload): array
    {
        $keys = $payload['keys'] ?? [];
        $originalKeys = $payload['original_keys'] ?? [];
        $languages = $payload['languages'] ?? [];
        $contextualFileKey = (string) ($payload['contextual_file_key'] ?? '');
        $maxRetries = (int) ($payload['max_retries'] ?? 5);
        $retryDelay = (int) ($payload['retry_delay'] ?? 3);
        $projectContext = $payload['project_context'] ?? null;
        $sourceTextMap = $payload['source_text_map'] ?? [];
        $model = $payload['model'] ?? null;
        if (is_string($model) && $model !== '') {
            config(['gemini.model' => TranslationService::resolveModel($model)]);
        }

        try {
            $geminiResponse = TranslationService::staticTranslateKeysWithGemini(
                $keys,
                $languages,
                $contextualFileKey,
                $maxRetries,
                $retryDelay,
                is_string($projectContext) ? $projectContext : null,
                is_array($sourceTextMap) ? $sourceTextMap : [],
            );

            $structured = TranslationService::staticStructureTranslationsFromGemini(
                $geminiResponse,
                $originalKeys,
                $contextualFileKey,
                $languages,
                $sourceTextMap,
            );

            return [
                'status' => 'success',
                'data' => $structured,
                'chunk_keys_count' => count($keys),
            ];
        } catch (Throwable $throwable) {
            Log::error('Translation task failed for file: ' . $contextualFileKey, [
                'keys' => array_slice(is_array($originalKeys) ? $originalKeys : [], 0, 10),
                'error' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            $preview = implode(',', array_slice(is_array($originalKeys) ? $originalKeys : [], 0, 3));

            return [
                'status' => 'error',
                'message' => "File: {$contextualFileKey}, Keys: {$preview}... - Error: " . $throwable->getMessage(),
                'chunk_keys_count' => count($keys),
                'failed_keys' => is_array($originalKeys) ? $originalKeys : [],
                'filename' => $contextualFileKey,
            ];
        }
    }
}
