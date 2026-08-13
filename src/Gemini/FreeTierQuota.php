<?php

declare(strict_types=1);

namespace Jayesh\LaravelGeminiTranslator\Gemini;

/**
 * One model's recorded request budget.
 *
 * Null rpm/rpd means "not in the snapshot" for that dimension. Zero means
 * Google currently publishes no free-tier budget (or the operator retired it).
 */
final readonly class FreeTierQuota
{
    public function __construct(
        public string $model,
        public ?int $requestsPerMinute,
        public ?int $requestsPerDay,
        public string $tier = 'Free tier',
        public string $asOf = '',
    ) {}

    /**
     * Bound default concurrency to a known positive RPM.
     *
     * Explicit --concurrency is never rewritten. A recorded 0 RPM forces
     * sequential work (1) unless the operator overrode concurrency.
     */
    public function effectiveConcurrency(int $requested, bool $explicit): int
    {
        $requested = max(1, $requested);

        if ($explicit || $this->requestsPerMinute === null) {
            return $requested;
        }

        if ($this->requestsPerMinute < 1) {
            return 1;
        }

        return min($requested, $this->requestsPerMinute);
    }

    public function exceedsRpm(int $concurrency): bool
    {
        return $this->requestsPerMinute !== null && $concurrency > $this->requestsPerMinute;
    }

    public function exceedsDailyBudget(int $requestCount): bool
    {
        return $this->requestsPerDay !== null && $requestCount > $this->requestsPerDay;
    }

    public function hasNoRequestBudget(): bool
    {
        return $this->requestsPerMinute === 0 || $this->requestsPerDay === 0;
    }

    public function formatRpm(): string
    {
        return $this->requestsPerMinute === null ? 'unknown' : (string) $this->requestsPerMinute;
    }

    public function formatRpd(): string
    {
        return $this->requestsPerDay === null ? 'unknown' : (string) $this->requestsPerDay;
    }
}
