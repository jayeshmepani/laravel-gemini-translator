<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Tests\Fakes;

final class RecordingHandler
{
    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function handle(array $payload): array
    {
        self::$seen[] = $payload;

        return [
            'status' => 'success',
            'id' => $payload['id'] ?? null,
            'chunk_keys_count' => 1,
        ];
    }

    public static function reset(): void
    {
        self::$seen = [];
    }
}
