<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Gemini;

/**
 * Resolves per-model RPM/RPD from config, not from a hard-wired constant.
 *
 * The shipped table is only a snapshot. Operators replace, zero, or add rows
 * in config/gemini-translator.php when Google changes published limits.
 */
final readonly class FreeTierQuotaCatalog
{
    /** @param array<string, array{rpm?: int|null, rpd?: int|null}|null> $models */
    public function __construct(
        private array $models,
        private string $asOf,
        private bool $applyCaps,
    ) {}

    /** @param array{as_of?: mixed, apply_free_tier_caps?: mixed, models?: mixed} $quotas */
    public static function fromConfig(array $quotas): self
    {
        $rawModels = $quotas['models'] ?? [];
        $models = [];

        if (is_array($rawModels)) {
            foreach ($rawModels as $model => $limits) {
                $key = self::normalize((string) $model);
                if ($key === '') {
                    continue;
                }

                if ($limits === null) {
                    continue;
                }

                if (!is_array($limits)) {
                    continue;
                }

                $models[$key] = [
                    'rpm' => self::nullableNonNegativeInt($limits['rpm'] ?? null),
                    'rpd' => self::nullableNonNegativeInt($limits['rpd'] ?? null),
                ];
            }
        }

        return new self(
            models: $models,
            asOf: is_string($quotas['as_of'] ?? null) ? $quotas['as_of'] : '',
            applyCaps: (bool) ($quotas['apply_free_tier_caps'] ?? true),
        );
    }

    public function find(string $model): ?FreeTierQuota
    {
        $normalized = self::normalize($model);
        $limits = $this->models[$normalized] ?? null;

        if ($limits === null) {
            return null;
        }

        return new FreeTierQuota(
            model: $normalized,
            requestsPerMinute: $limits['rpm'],
            requestsPerDay: $limits['rpd'],
            asOf: $this->asOf,
        );
    }

    /** @return list<FreeTierQuota> */
    public function all(): array
    {
        $quotas = [];
        foreach (array_keys($this->models) as $model) {
            $quota = $this->find($model);
            if ($quota instanceof FreeTierQuota) {
                $quotas[] = $quota;
            }
        }

        return $quotas;
    }

    public function asOf(): string
    {
        return $this->asOf;
    }

    public function applyCaps(): bool
    {
        return $this->applyCaps;
    }

    public static function normalize(string $model): string
    {
        $model = strtolower(trim($model));

        if (str_starts_with($model, 'models/')) {
            $model = substr($model, 7);
        }

        return $model;
    }

    private static function nullableNonNegativeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            return max(0, (int) $value);
        }

        return null;
    }
}
